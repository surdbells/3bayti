#!/usr/bin/env node
/**
 * Self-hosted OTA release automation (CI / power users; the standard path is
 * the portal "OTA updates" page).
 *
 * Build → zip → encrypt (signing, the DEFAULT) → upload to
 * POST /v3/admin/ota/bundles, all with the pinned @capgo/cli (CAPGO_CLI in
 * ota-release-lib.mjs). The same thing the portal page does, as one command.
 *
 * Auth (pick one):
 *   OTA_ADMIN_TOKEN=<admin bearer token>            (preferred for CI)
 *   OTA_ADMIN_EMAIL=… OTA_ADMIN_PASSWORD=…          (auto-login)
 *
 * Usage (from apps/mobile; via npm, put `--` before the flags):
 *   npm run ota:release -- --platform both --version 1.0.7 --min-native 1.6.0
 *   node scripts/ota-release.mjs --platform ios --version 1.0.7 \
 *        --zip <file>_encrypted.zip --session-key <ivSessionKey> --checksum <encryptedChecksum>
 *   node scripts/ota-release.mjs --platform android --channel dev --version 1.0.7 --unsigned
 *
 * Flags:
 *   --platform android|ios|both (required; `both` publishes one upload to each)
 *   --version  <x.y.z>          (required; numeric, 1-4 segments, no leading zeros / pre-release suffix)
 *   --channel  <name>           (default production)
 *   --min-native <x.y.z>        (default 0.0.0)
 *   --app-id   <id>             (default com.threebayti.app)
 *   --notes    "<what's new>"   optional update summary shown to customers
 *   --www      <dir>            (default www)
 *   --skip-build                skip `npm run build`
 *   --unsigned                  skip encryption (android + non-production
 *                               channel ONLY: iOS can't install unsigned
 *                               bundles and production must be signed; the
 *                               API enforces the same rule)
 *   --sign                      accepted for compatibility; signing is the default
 *   --zip <file> --session-key <k> --checksum <c>
 *                               upload a bundle you already encrypted (skips
 *                               build/zip; <file> is the *_encrypted.zip, <c>
 *                               the ENCRYPTED checksum)
 *   --dry-run                   do everything except the upload
 *
 * Env: OTA_API_BASE (default https://api-v3.3bayti.ae)
 */
import { execSync } from 'node:child_process';
import { readFileSync, existsSync, openSync, readSync, closeSync } from 'node:fs';
import { basename } from 'node:path';
import {
  CAPGO_CLI,
  PLATFORMS,
  isPlainZip,
  isPlatform,
  isVersion,
  parseEncryptOutput,
  parseZipOutput,
  plainChecksumError,
  publishedBundles,
  sessionKeyError,
  signedChecksumError,
  unsignedError,
} from './ota-release-lib.mjs';

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
function q(p) {
  // Cross-shell path quoting (works in POSIX sh and cmd for typical paths).
  return JSON.stringify(p);
}
/** The pinned CLI, never `@latest` (it reads the private signing key). */
function capgo(args) {
  return `npx --yes ${CAPGO_CLI} ${args}`;
}
/** First 4 bytes of a file (to tell a plain zip from ciphertext). */
function head4(path) {
  const fd = openSync(path, 'r');
  try {
    const buf = Buffer.alloc(4);
    const n = readSync(fd, buf, 0, 4, 0);
    return buf.subarray(0, n);
  } finally {
    closeSync(fd);
  }
}

const platform = opt('platform');
const version = opt('version').trim();
const channel = opt('channel', 'production').trim();
const minNative = opt('min-native', '0.0.0').trim();
const appId = opt('app-id', 'com.threebayti.app').trim();
// Optional "what's new" summary shown to customers after this update applies.
const notes = opt('notes', '').trim();
const wwwDir = opt('www', 'www');
const doBuild = !flag('skip-build');
const unsigned = flag('unsigned');
const dryRun = flag('dry-run');
const preEncryptedZip = opt('zip').trim();
let sessionKey = opt('session-key').trim();
let checksum = opt('checksum').trim();

if (!isPlatform(platform)) die(`--platform must be one of: ${PLATFORMS.join(', ')}`);
if (!isVersion(version)) die('--version must be numeric with 1-4 segments and no leading zeros, e.g. 1.0.7 (no pre-release suffix)');
if (!isVersion(minNative)) die('--min-native must be numeric with 1-4 segments, e.g. 1.6.0');
if (unsigned && flag('sign')) die('Use either --unsigned or --sign, not both (signing is the default).');
if (unsigned) {
  if (sessionKey || checksum || preEncryptedZip) die('--unsigned cannot be combined with --zip/--session-key/--checksum.');
  const why = unsignedError(platform, channel);
  if (why) die(`--unsigned refused: ${why}. Drop --unsigned to sign the bundle (see OTA-SIGNING.md).`);
}
if (sessionKey || checksum || preEncryptedZip) {
  if (!sessionKey || !checksum || !preEncryptedZip) {
    die('A pre-encrypted bundle needs all of --zip <encrypted zip>, --session-key <ivSessionKey> and --checksum <encrypted checksum>.');
  }
  const skError = sessionKeyError(sessionKey);
  if (skError) die(`--session-key: ${skError}.`);
  const csError = signedChecksumError(checksum);
  if (csError) die(`--checksum: ${csError}. Pass the ENCRYPTED checksum printed by \`bundle encrypt\`.`);
  if (!existsSync(preEncryptedZip)) die(`--zip not found: ${preEncryptedZip}`);
  if (isPlainZip(head4(preEncryptedZip))) {
    die(`--zip ${preEncryptedZip} is the PLAIN zip. Pass the *_encrypted.zip that \`bundle encrypt\` wrote.`);
  }
}

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

function buildAndZip() {
  if (doBuild) {
    console.log('• Building web bundle…');
    sh('npm run build');
  }
  if (!existsSync(wwwDir)) die(`www dir not found: ${wwwDir} (build first, or pass --www)`);

  console.log(`• Zipping bundle (${CAPGO_CLI})…`);
  // --package-json: in this pnpm workspace the CLI otherwise resolves the
  // repo-ROOT package.json (where @capgo/capacitor-updater isn't declared) and
  // aborts with "Cannot find @capgo/capacitor-updater in node_modules". Point
  // it at THIS package's manifest (cwd = apps/mobile) so the plugin check
  // passes. Run this script from apps/mobile.
  // --name: name the zip after the release (the CLI's default name uses the
  // package.json version, 0.0.1, for every release).
  const zipName = `${appId}_${version}.zip`;
  const out = sh(capgo(`bundle zip ${appId} --path ${q(wwwDir)} --name ${q(zipName)} --package-json package.json --json`), true);
  try {
    return parseZipOutput(out, existsSync);
  } catch (e) {
    return die(e.message);
  }
}

function encrypt(zipPath, plainChecksum) {
  console.log(`• Encrypting bundle (${CAPGO_CLI} bundle encrypt)…`);
  // @capgo/cli v8: `bundle encrypt <zip> <plainChecksum>` takes the plain-zip
  // checksum (from `bundle zip --json`) as INPUT, writes the ciphertext to a
  // NEW `<zip>_encrypted.zip` (auto-using the .capgo_key_v2 private key here)
  // and emits an ivSessionKey (-> session_key) and an ENCRYPTED checksum (512
  // hex for RSA-2048). We publish exactly those three. There is deliberately
  // NO fallback to the plain checksum or the plain zip (parseEncryptOutput
  // throws instead): either one makes every device reject the bundle.
  const pcError = plainChecksumError(plainChecksum);
  if (pcError) {
    die(`Cannot sign: ${pcError} (got "${plainChecksum}"). Encrypt manually and re-run with --zip/--session-key/--checksum (see OTA-SIGNING.md).`);
  }
  const out = sh(capgo(`bundle encrypt ${q(zipPath)} ${plainChecksum} --package-json package.json --json`), true);
  let enc;
  try {
    enc = parseEncryptOutput(out, zipPath, plainChecksum, existsSync);
  } catch (e) {
    return die(e.message);
  }
  if (isPlainZip(head4(enc.encryptedPath))) {
    die(`${enc.encryptedPath} starts like a plain zip, not ciphertext. Refusing to upload it as a signed bundle.`);
  }
  return enc;
}

async function upload(token, zipPath) {
  const qs = new URLSearchParams({ platform, version, channel, min_native: minNative, app_id: appId });
  if (sessionKey) qs.set('session_key', sessionKey);
  if (checksum) qs.set('checksum', checksum);
  if (notes) qs.set('notes', notes);

  const form = new FormData();
  form.append('file', new Blob([readFileSync(zipPath)]), basename(zipPath));

  console.log(`• Uploading ${basename(zipPath)} to ${API_BASE}/v3/admin/ota/bundles …`);
  const res = await fetch(`${API_BASE}/v3/admin/ota/bundles?${qs.toString()}`, {
    method: 'POST',
    headers: { Authorization: `Bearer ${token}` },
    body: form,
  });
  const body = await res.json().catch(() => ({}));
  if (!res.ok) {
    die(`Upload failed (${res.status}): ${body?.error?.message || body?.message || JSON.stringify(body)}`);
  }
  return publishedBundles(body);
}

(async () => {
  const token = dryRun ? '' : await login();

  let uploadPath;
  if (preEncryptedZip) {
    uploadPath = preEncryptedZip;
  } else {
    const { zipPath, plainChecksum } = buildAndZip();
    uploadPath = zipPath;
    if (!unsigned) {
      const enc = encrypt(zipPath, plainChecksum);
      ({ sessionKey, checksum } = enc);
      uploadPath = enc.encryptedPath;
    }
  }

  if (dryRun) {
    console.log('\n• Dry run — not uploading. Would publish:');
    console.log({ platform, version, channel, minNative, appId, file: uploadPath, signed: !!sessionKey, checksum });
    return;
  }

  const bundles = await upload(token, uploadPath);
  for (const bundle of bundles) {
    console.log(
      `\n✔ Published ${bundle?.platform} ${bundle?.version} on "${bundle?.channel}" ` +
        `(${bundle?.signed ? 'signed' : 'unsigned'}, id ${bundle?.id}).`,
    );
  }
})().catch((e) => die(e?.message || String(e)));
