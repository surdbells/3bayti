#!/usr/bin/env node
/**
 * Self-hosted OTA release automation.
 *
 * Build → zip (via @capgo/cli) → optionally encrypt (signing) → upload to
 * POST /v3/admin/ota/bundles. The same thing the portal "OTA updates" page does,
 * as one command for CI / power users.
 *
 * Auth (pick one):
 *   OTA_ADMIN_TOKEN=<admin bearer token>            (preferred for CI)
 *   OTA_ADMIN_EMAIL=… OTA_ADMIN_PASSWORD=…          (auto-login)
 *
 * Usage:
 *   node scripts/ota-release.mjs --platform android --version 1.0.7 --min-native 1.6.0
 *   node scripts/ota-release.mjs --platform ios --version 1.0.7 --sign
 *   node scripts/ota-release.mjs --platform android --version 1.0.7 \
 *        --session-key <ivSessionKey> --checksum <encryptChecksum>   # pre-encrypted
 *
 * Flags:
 *   --platform android|ios      (required)
 *   --version  <semver>         (required)
 *   --channel  <name>           (default production)
 *   --min-native <semver>       (default 0.0.0)
 *   --app-id   <id>             (default com.threebayti.app)
 *   --www      <dir>            (default www)
 *   --skip-build                skip `npm run build`
 *   --sign                      run `@capgo/cli encrypt` and upload signed
 *   --session-key <k> --checksum <c>   upload a bundle you already encrypted
 *   --dry-run                   do everything except the upload
 *
 * Env: OTA_API_BASE (default https://api-v3.3bayti.ae)
 */
import { execSync } from 'node:child_process';
import { readFileSync, existsSync } from 'node:fs';
import { basename } from 'node:path';

const API_BASE = (process.env.OTA_API_BASE || 'https://api-v3.3bayti.ae').replace(/\/+$/, '');
const argv = process.argv.slice(2);

function opt(name, def = '') {
  const i = argv.indexOf(`--${name}`);
  return i >= 0 && argv[i + 1] && !argv[i + 1].startsWith('--') ? argv[i + 1] : def;
}
function flag(name) {
  return argv.includes(`--${name}`);
}
function die(msg) {
  console.error(`\n✖ ${msg}\n`);
  process.exit(1);
}
function sh(cmd, capture = false) {
  console.log(`  $ ${cmd}`);
  return execSync(cmd, { encoding: 'utf8', stdio: capture ? ['inherit', 'pipe', 'inherit'] : 'inherit' });
}
function isSemver(v) {
  return /^\d+(\.\d+){0,3}$/.test(v);
}
function q(p) {
  // Cross-shell path quoting (works in POSIX sh and cmd for typical paths).
  return JSON.stringify(p);
}

const platform = opt('platform');
const version = opt('version').trim();
const channel = opt('channel', 'production').trim();
const minNative = opt('min-native', '0.0.0').trim();
const appId = opt('app-id', 'com.threebayti.app').trim();
const wwwDir = opt('www', 'www');
const doBuild = !flag('skip-build');
const doSign = flag('sign');
const dryRun = flag('dry-run');
let sessionKey = opt('session-key').trim();
let checksum = opt('checksum').trim();

if (!['android', 'ios'].includes(platform)) die('--platform must be android or ios');
if (!isSemver(version)) die('--version must be semver, e.g. 1.0.7');
if (!isSemver(minNative)) die('--min-native must be semver, e.g. 1.6.0');

async function login() {
  if (process.env.OTA_ADMIN_TOKEN) return process.env.OTA_ADMIN_TOKEN.trim();
  const email = process.env.OTA_ADMIN_EMAIL;
  const password = process.env.OTA_ADMIN_PASSWORD;
  if (!email || !password) {
    die('Set OTA_ADMIN_TOKEN, or OTA_ADMIN_EMAIL + OTA_ADMIN_PASSWORD, to authenticate.');
  }
  console.log('• Signing in…');
  const res = await fetch(`${API_BASE}/v3/auth/login`, {
    method: 'POST',
    headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify({ email, password }),
  });
  const body = await res.json().catch(() => ({}));
  if (!res.ok || !body?.access_token) {
    die(`Login failed (${res.status}): ${body?.error?.message || body?.message || 'no access_token'}`);
  }
  return body.access_token;
}

/** Extract the last JSON object printed on stdout (the CLI may log extra lines). */
function lastJson(out) {
  const matches = out.match(/\{[\s\S]*\}/g);
  if (!matches) return null;
  try {
    return JSON.parse(matches[matches.length - 1]);
  } catch {
    return null;
  }
}

function buildAndZip() {
  if (doBuild) {
    console.log('• Building web bundle…');
    sh('npm run build');
  }
  if (!existsSync(wwwDir)) die(`www dir not found: ${wwwDir} (build first, or pass --www)`);

  console.log('• Zipping bundle (@capgo/cli)…');
  const out = sh(`npx @capgo/cli bundle zip ${appId} --path ${q(wwwDir)} --json`, true);
  const json = lastJson(out);
  const zipPath = json?.bundle || json?.path || json?.zip;
  if (!zipPath || !existsSync(zipPath)) {
    die(`Could not determine the zip path from @capgo/cli output:\n${out}`);
  }
  return { zipPath, plainChecksum: json?.checksum || '' };
}

function encrypt(zipPath, plainChecksum) {
  console.log('• Encrypting bundle (@capgo/cli bundle encrypt)…');
  // @capgo/cli v8: `bundle encrypt <zip> <plainChecksum>` takes the plain-zip
  // checksum (from `bundle zip --json`) as INPUT, encrypts the zip in place
  // (RSA/v2, auto-using .capgo_key_v2 here), and emits BOTH an ivSessionKey
  // (-> session_key) and an ENCRYPTED checksum (512 hex for RSA-2048). PUBLISH
  // the emitted encrypted checksum — the device verifies against it — NOT the
  // plain one. Older CLIs used a bare `encrypt`; we handle either name/shape
  // below and fall back to the plain checksum only if the CLI emits none.
  if (!plainChecksum) {
    die('No plain checksum from `bundle zip --json` — cannot sign. Re-run with --session-key/--checksum.');
  }
  const out = sh(`npx @capgo/cli bundle encrypt ${q(zipPath)} ${plainChecksum} --json`, true);
  const json = lastJson(out);
  const iv =
    json?.ivSessionKey ||
    json?.sessionKey ||
    out.match(/ivSessionKey["'\s:=]+([A-Za-z0-9+/=:_-]+)/i)?.[1];
  if (!iv) {
    die(
      'Could not parse ivSessionKey from `@capgo/cli bundle encrypt` output. Run it manually and ' +
        're-run this script with --session-key <iv> --checksum <plainChecksum> (see OTA-SIGNING.md).\n' +
        `Output was:\n${out}`,
    );
  }
  // Publish checksum: prefer one emitted by encrypt (older CLIs), else the
  // plain-zip checksum (v8).
  const emittedCs = json?.checksum || out.match(/checksum["'\s:=]+([A-Za-z0-9+/=:_-]+)/i)?.[1];
  return { sessionKey: iv, checksum: emittedCs || plainChecksum };
}

async function upload(token, zipPath) {
  const qs = new URLSearchParams({ platform, version, channel, min_native: minNative, app_id: appId });
  if (sessionKey) qs.set('session_key', sessionKey);
  if (checksum) qs.set('checksum', checksum);

  const form = new FormData();
  form.append('file', new Blob([readFileSync(zipPath)]), basename(zipPath));

  console.log(`• Uploading to ${API_BASE}/v3/admin/ota/bundles …`);
  const res = await fetch(`${API_BASE}/v3/admin/ota/bundles?${qs.toString()}`, {
    method: 'POST',
    headers: { Authorization: `Bearer ${token}` },
    body: form,
  });
  const body = await res.json().catch(() => ({}));
  if (!res.ok) {
    die(`Upload failed (${res.status}): ${body?.error?.message || body?.message || JSON.stringify(body)}`);
  }
  return body.bundle;
}

(async () => {
  const token = await login();
  const { zipPath, plainChecksum } = buildAndZip();

  if (doSign && !sessionKey) {
    ({ sessionKey, checksum } = encrypt(zipPath, plainChecksum));
  }
  if (sessionKey && !checksum) {
    die('A signed bundle needs both --session-key and --checksum (from @capgo/cli encrypt).');
  }

  if (dryRun) {
    console.log('\n• Dry run — not uploading. Would publish:');
    console.log({ platform, version, channel, minNative, appId, zipPath, signed: !!sessionKey });
    return;
  }

  const bundle = await upload(token, zipPath);
  console.log(
    `\n✔ Published ${bundle?.platform} ${bundle?.version} on "${bundle?.channel}" ` +
      `(${bundle?.signed ? 'signed' : 'unsigned'}, id ${bundle?.id}).`,
  );
})().catch((e) => die(e?.message || String(e)));
