/**
 * Pure helpers for scripts/ota-release.mjs, split out so they can be unit
 * tested (`npm run test:ota-release`) without building, zipping or uploading.
 *
 * Rules mirrored from the API (keep in step):
 *   - versions: OtaVersion::PATTERN, numeric only, 1-4 canonical segments
 *     (no leading zeros, <= 9 digits), no pre-release suffix
 *     (apps/api/src/Domain/Ota/OtaVersion.php)
 *   - checksums: OtaChecksum (apps/api/src/Domain/Ota/OtaChecksum.php)
 *   - signing: OtaSigning, session-key shape + where unsigned is allowed
 *     (apps/api/src/Domain/Ota/OtaSigning.php)
 *   - platforms: android | ios | both (UploadOtaBundleController)
 *   - native range: min_native REQUIRED, max_native optional (defaults to
 *     min_native), max >= min, not ending at 0, compared like
 *     OtaVersion::compare (OtaVersion::nativeRangeError,
 *     OtaBundle::getEffectiveMaxNativeVersion)
 */

export const PLATFORMS = ['android', 'ios', 'both'];

/**
 * The @capgo/cli the release runs, PINNED: it reads the private signing key
 * (.capgo_key_v2) and its output format (zip `filename`, encrypt
 * `checksum`/`ivSessionKey`, hex vs base64 checksum) is what this script
 * parses. 8.68.1 is the version that output was verified against. Bump
 * deliberately (and re-check parseZipOutput/parseEncryptOutput) — never
 * `@latest`.
 */
export const CAPGO_CLI = '@capgo/cli@8.68.1';

/** Numeric only, 1-4 canonical segments ("1.7", "1.7.1", "1.0.0.4"; not "1.07"). */
export const VERSION_PATTERN = /^(0|[1-9]\d{0,8})(\.(0|[1-9]\d{0,8})){0,3}$/;

const PLAIN_CHECKSUM = /^[a-f0-9]{64}$/i;
/**
 * RSA-encrypted checksum, hex (updater >= 7.30): EXACTLY 512 chars. The plugin
 * only accepts a 256-byte (RSA-2048) ciphertext, and our key is RSA-2048.
 */
const SIGNED_CHECKSUM = /^[a-f0-9]{512}$/i;
/** ivSessionKey: base64(16-byte IV) ":" base64(256-byte RSA-2048 block). */
const SESSION_KEY = /^[A-Za-z0-9+/]{22}==:[A-Za-z0-9+/]{342}==$/;

export function isVersion(v) {
  return VERSION_PATTERN.test(String(v ?? ''));
}

export function isPlatform(p) {
  return PLATFORMS.includes(p);
}

/**
 * Numeric version compare (mirrors OtaVersion::compare): negative if a < b,
 * positive if a > b, 0 if equal. Missing segments count as 0, so "1.8"
 * equals "1.8.0".
 */
export function compareVersions(a, b) {
  const pa = String(a ?? '').trim().split('-')[0].split('.').map((p) => parseInt(p, 10) || 0);
  const pb = String(b ?? '').trim().split('-')[0].split('.').map((p) => parseInt(p, 10) || 0);
  for (let i = 0; i < Math.max(pa.length, pb.length); i++) {
    const d = (pa[i] ?? 0) - (pb[i] ?? 0);
    if (d !== 0) return d;
  }
  return 0;
}

/**
 * The bundle's native range from --min-native / --max-native. The update
 * endpoint offers a bundle only to app builds whose native version
 * (version_build = iOS CFBundleShortVersionString / Android versionName) is
 * inside [min, max], and an empty max means "exactly min", so a production
 * bundle never replaces the web code of a TestFlight / test build that
 * carries a higher app version.
 *
 * @returns {{ minNative: string, maxNative: string, error: string | null }}
 *          `error` names the flag to fix; maxNative is the EFFECTIVE max
 *          (min when --max-native is omitted), which is what gets uploaded.
 */
export function resolveNativeRange(minRaw, maxRaw) {
  const minNative = String(minRaw ?? '').trim();
  const maxNative = String(maxRaw ?? '').trim() || minNative;
  let error = null;
  if (!minNative) {
    error =
      '--min-native is required: the app version this bundle was built for (iOS CFBundleShortVersionString / ' +
      'Android versionName, e.g. 1.8). The bundle is offered only to app versions from --min-native to ' +
      '--max-native (default: --min-native).';
  } else if (!isVersion(minNative)) {
    error = '--min-native must be numeric with 1-4 segments and no leading zeros, e.g. 1.8';
  } else if (!isVersion(maxNative)) {
    error = '--max-native must be numeric with 1-4 segments and no leading zeros, e.g. 1.9 (or omit it to use --min-native)';
  } else if (compareVersions(maxNative, minNative) < 0) {
    error = `--max-native (${maxNative}) must not be lower than --min-native (${minNative})`;
  } else if (compareVersions(maxNative, '0') === 0) {
    // Mirrors OtaVersion::nativeRangeError: the old 0.0.0 default matches no real app.
    error =
      `A native range ending at ${maxNative} matches no installed app (devices report their app version, e.g. 1.8). ` +
      'Set --min-native to the app version this bundle was built for.';
  }
  return { minNative, maxNative, error };
}

/**
 * Null when every app build in [minNative, maxNative] still on its BUILT-IN
 * web code (a fresh install or a store update) can take a bundle of this
 * version, else a warning (not an error: devices already on a lower OTA
 * bundle still take it). On built-in code the updater plugin reports the
 * app's marketing version as its current bundle version, and the update
 * endpoint only offers a strictly newer version, so the bundle version must be
 * above the max (a 1.8.1 test shell needs 1.8.2 or higher). Mirrors the
 * portal's otaBuiltinReachWarning.
 *
 * @param {string} version
 * @param {string} minNative
 * @param {string} maxNative the EFFECTIVE max (resolveNativeRange's maxNative)
 * @returns {string | null}
 */
export function builtinReachWarning(version, minNative, maxNative) {
  const v = String(version ?? '').trim();
  const min = String(minNative ?? '').trim();
  const max = String(maxNative ?? '').trim() || min;
  if (!isVersion(v) || !isVersion(min) || !isVersion(max)) return null; // the validators report these
  if (compareVersions(v, max) > 0) return null;
  const from = compareVersions(v, min) < 0 ? min : v;
  const missed = compareVersions(from, max) === 0 ? `app version ${max}` : `app versions ${from} to ${max}`;
  return (
    `--version ${v} is not above --max-native ${max}: an app build still on its built-in web code (a fresh install ` +
    `or a store update) reports its app version as its current bundle and is only offered a newer one, so ${missed} ` +
    `on built-in code will never be offered this bundle. Use a version above ${max} to reach them.`
  );
}

/**
 * Null when an UNSIGNED bundle may be published for this platform/channel,
 * else why not (mirrors OtaSigning::unsignedError). iOS can never install an
 * unsigned bundle (the app embeds a public key); production must be signed.
 */
export function unsignedError(platform, channel) {
  if (platform === 'ios' || platform === 'both') {
    return 'iOS only installs SIGNED bundles (the app embeds a public key)';
  }
  if (channel === 'production') {
    return 'production bundles must be SIGNED';
  }
  return null;
}

/** Null when `checksum` is acceptable for a SIGNED bundle, else the reason. */
export function signedChecksumError(checksum, plainChecksum = '') {
  const cs = String(checksum ?? '').trim();
  if (!cs) {
    return 'no encrypted checksum';
  }
  if (PLAIN_CHECKSUM.test(cs) || (plainChecksum && cs.toLowerCase() === String(plainChecksum).toLowerCase())) {
    return 'that is the PLAIN sha256 of the unencrypted zip, not the encrypted checksum';
  }
  if (!SIGNED_CHECKSUM.test(cs)) {
    return `expected the hex encrypted checksum, exactly 512 hex chars for RSA-2048 (got ${cs.length})`;
  }
  return null;
}

/** Null when `key` has the `bundle encrypt` ivSessionKey shape, else the reason. */
export function sessionKeyError(key) {
  const k = String(key ?? '').trim();
  if (!k) {
    return 'no session key';
  }
  return SESSION_KEY.test(k)
    ? null
    : 'expected the ivSessionKey printed by `bundle encrypt` (<24-char base64 IV>:<344-char base64 key>)';
}

/** Null when `checksum` is the plain 64-hex SHA256 `bundle zip` prints, else the reason. */
export function plainChecksumError(checksum) {
  return PLAIN_CHECKSUM.test(String(checksum ?? '').trim())
    ? null
    : 'expected the 64-hex SHA256 from `bundle zip` (a CRC32 means the CLI did not detect updater >= 7)';
}

/**
 * Whether the first bytes are a plain .zip header (local file header, or the
 * end-of-central-directory of an empty archive). `bundle encrypt` output is
 * AES ciphertext and starts with these bytes about once in 2^32.
 *
 * @param {Uint8Array} bytes
 */
export function isPlainZip(bytes) {
  return (
    bytes.length >= 4 &&
    bytes[0] === 0x50 &&
    bytes[1] === 0x4b &&
    ((bytes[2] === 0x03 && bytes[3] === 0x04) || (bytes[2] === 0x05 && bytes[3] === 0x06))
  );
}

/** Extract the last JSON object printed on stdout (the CLI may log extra lines). */
export function lastJson(out) {
  const matches = String(out ?? '').match(/\{[\s\S]*\}/g);
  if (!matches) return null;
  try {
    return JSON.parse(matches[matches.length - 1]);
  } catch {
    return null;
  }
}

/**
 * Parse `@capgo/cli bundle zip <appId> --json` output.
 *
 * CLI v8 prints `{ bundle, filename, checksum }`, where `bundle` is the bundle
 * VERSION (from package.json, e.g. "0.0.1"), NOT a path: the zip is
 * `filename`. Throws when the zip can't be located.
 *
 * @param {string} out CLI stdout
 * @param {(p: string) => boolean} exists file-existence check (injectable for tests)
 * @returns {{ zipPath: string, plainChecksum: string }}
 */
export function parseZipOutput(out, exists) {
  const json = lastJson(out);
  const zipPath = [json?.filename, json?.path, json?.zip].find(
    (p) => typeof p === 'string' && p.trim() !== '' && exists(p),
  );
  if (!zipPath) {
    throw new Error(`Could not determine the zip path (the "filename" field) from \`bundle zip --json\` output:\n${out}`);
  }
  return { zipPath, plainChecksum: String(json?.checksum ?? '').trim() };
}

/**
 * Parse `@capgo/cli bundle encrypt <zip> <plainChecksum> --json` output.
 *
 * CLI v8 prints `{ checksum, filename, ivSessionKey }` (text mode: "Encoded
 * Checksum: …", "ivSessionKey: …", "Encrypted zip saved at …"). It writes the
 * ciphertext to a NEW file (`<zip>_encrypted.zip`), it does not encrypt in
 * place, so the upload must use `encryptedPath`, never the plain zip.
 *
 * Throws (never falls back) when the session key, the ENCRYPTED checksum or
 * the encrypted file can't be determined: a signed bundle published with the
 * plain checksum or the plain zip fails verification on every device.
 *
 * @param {string} out       CLI stdout
 * @param {string} zipPath   the plain zip that was encrypted
 * @param {string} plainChecksum  the plain sha256 from `bundle zip`
 * @param {(p: string) => boolean} exists  file-existence check (injectable for tests)
 * @returns {{ sessionKey: string, checksum: string, encryptedPath: string }}
 */
export function parseEncryptOutput(out, zipPath, plainChecksum, exists) {
  const text = String(out ?? '');
  const json = lastJson(text);
  const manual =
    `Run \`npx ${CAPGO_CLI} bundle encrypt <zip> <plainChecksum>\` yourself and re-run this script with ` +
    '--zip <the *_encrypted.zip it wrote> --session-key <ivSessionKey> --checksum <the ENCRYPTED checksum it printed> ' +
    '(never the plain `bundle zip` checksum; see OTA-SIGNING.md).';

  const sessionKey = String(
    json?.ivSessionKey ||
      json?.sessionKey ||
      text.match(/ivSessionKey["'\s:=]+([A-Za-z0-9+/=:_-]+)/i)?.[1] ||
      '',
  ).trim();
  const skError = sessionKeyError(sessionKey);
  if (skError) {
    throw new Error(`Could not parse a valid ivSessionKey from \`bundle encrypt\` output (${skError}). ${manual}\nOutput was:\n${text}`);
  }

  const checksum = String(
    json?.checksum || text.match(/checksum["'\s:=]+([0-9a-f]+)/i)?.[1] || '',
  ).trim();
  const csError = signedChecksumError(checksum, plainChecksum);
  if (csError) {
    throw new Error(`Could not get the encrypted checksum from \`bundle encrypt\` (${csError}). ${manual}\nOutput was:\n${text}`);
  }

  const candidates = [
    json?.filename,
    text.match(/Encrypted zip saved at\s+(.+?)\s*$/im)?.[1],
    `${zipPath}_encrypted.zip`,
  ].filter((p) => typeof p === 'string' && p.trim() !== '');
  const encryptedPath = candidates.find((p) => p !== zipPath && exists(p));
  if (!encryptedPath) {
    throw new Error(`Could not find the encrypted zip written by \`bundle encrypt\` (looked for ${candidates.join(', ')}). ${manual}`);
  }

  return { sessionKey, checksum, encryptedPath };
}

/** The upload endpoint answers {bundle} for one platform, {bundles:[…]} for `both`. */
export function publishedBundles(body) {
  if (Array.isArray(body?.bundles)) return body.bundles;
  return body?.bundle ? [body.bundle] : [];
}
