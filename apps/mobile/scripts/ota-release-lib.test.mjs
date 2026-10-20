// Run: npm run test:ota-release   (node --test, no browser / build needed)
import { test } from 'node:test';
import assert from 'node:assert/strict';
import {
  CAPGO_CLI,
  builtinReachWarning,
  compareVersions,
  isPlainZip,
  isPlatform,
  isVersion,
  parseEncryptOutput,
  parseZipOutput,
  plainChecksumError,
  publishedBundles,
  resolveNativeRange,
  sessionKeyError,
  signedChecksumError,
  unsignedError,
} from './ota-release-lib.mjs';

const ZIP = 'com.threebayti.app_1.7.1.zip';
const ENC_ZIP = `${ZIP}_encrypted.zip`;
const PLAIN = 'a'.repeat(64);
const ENCRYPTED = 'ab'.repeat(256); // 512 hex, RSA-2048
// Real ivSessionKey shape: base64(16-byte IV) ":" base64(256-byte RSA block).
const IV = `${Buffer.alloc(16, 1).toString('base64')}:${Buffer.alloc(256, 2).toString('base64')}`;
const exists = (p) => p === ENC_ZIP;

test('versions are numeric, canonical, 1-4 segments (matches OtaVersion::PATTERN)', () => {
  for (const v of ['1', '1.7', '1.7.1', '1.0.0.4', '0.0.0']) assert.equal(isVersion(v), true, v);
  for (const v of ['', '1.7.1-beta.1', '1.7.0+45', 'v1.7', '1.7.', '1.0.0.0.4', '1.08', '01.7', '1.1000000000', '1.8\n']) {
    assert.equal(isVersion(v), false, JSON.stringify(v));
  }
});

test('compareVersions is numeric and pads missing segments (mirrors OtaVersion::compare)', () => {
  assert.ok(compareVersions('1.6.10', '1.6.9') > 0);
  assert.ok(compareVersions('1.8', '1.8.1') < 0);
  assert.equal(compareVersions('1.8', '1.8.0'), 0);
  assert.equal(compareVersions('1.8.0.0', '1.8'), 0);
});

test('--min-native is required (no silent 0.0.0 default)', () => {
  for (const missing of [undefined, '', '   ']) {
    const r = resolveNativeRange(missing, '');
    assert.match(r.error, /--min-native is required/);
    assert.match(r.error, /CFBundleShortVersionString/);
  }
  // A max alone doesn't stand in for the min.
  assert.match(resolveNativeRange('', '1.9').error, /--min-native is required/);
});

test('--max-native defaults to --min-native and is returned explicitly for the upload', () => {
  assert.deepEqual(resolveNativeRange('1.8', undefined), { minNative: '1.8', maxNative: '1.8', error: null });
  assert.deepEqual(resolveNativeRange(' 1.8 ', '  '), { minNative: '1.8', maxNative: '1.8', error: null });
  assert.deepEqual(resolveNativeRange('1.8', '1.9'), { minNative: '1.8', maxNative: '1.9', error: null });
  // Equal under padding is a valid single-version range.
  assert.equal(resolveNativeRange('1.8', '1.8.0').error, null);
});

test('the native range must be canonical versions with max >= min', () => {
  assert.match(resolveNativeRange('1.08', '').error, /--min-native must be numeric/);
  assert.match(resolveNativeRange('1.8-beta', '').error, /--min-native must be numeric/);
  assert.match(resolveNativeRange('1.8', 'v1.9').error, /--max-native must be numeric/);
  assert.match(resolveNativeRange('1.9', '1.8.5').error, /--max-native \(1\.8\.5\) must not be lower than --min-native \(1\.9\)/);
});

test('a range ending at 0 (the old 0.0.0 default) is refused like the API does', () => {
  assert.match(resolveNativeRange('0.0.0', '').error, /matches no installed app/);
  assert.match(resolveNativeRange('0', '0.0').error, /matches no installed app/);
  assert.equal(resolveNativeRange('0.0.0', '1.9').error, null);
});

test('builtinReachWarning flags a version not above the max (built-in code reports the app version)', () => {
  assert.equal(builtinReachWarning('1.8.4', '1.8', '1.8'), null);
  assert.equal(builtinReachWarning('1.8.2', '1.8.1', '1.8.1'), null);
  assert.equal(builtinReachWarning('1.8.4', '1.7', '1.8'), null);
  assert.match(builtinReachWarning('1.8', '1.8', '1.8'), /app version 1\.8 on/);
  assert.match(builtinReachWarning('1.8.0', '1.8', ''), /app version 1\.8 on/); // empty max = min; 1.8.0 == 1.8
  assert.match(builtinReachWarning('1.8.4', '1.8', '1.9'), /app versions 1\.8\.4 to 1\.9/);
  assert.match(builtinReachWarning('1.7.9', '1.8', '1.9'), /app versions 1\.8 to 1\.9/);
  // Invalid input is resolveNativeRange's / isVersion's job.
  assert.equal(builtinReachWarning('1.8-beta', '1.8', '1.8'), null);
  assert.equal(builtinReachWarning('1.8', '', ''), null);
});

test('platform accepts android, ios and both', () => {
  for (const p of ['android', 'ios', 'both']) assert.equal(isPlatform(p), true, p);
  assert.equal(isPlatform('windows'), false);
  assert.equal(isPlatform(''), false);
});

test('unsigned is only allowed for android off production (mirrors OtaSigning)', () => {
  assert.equal(unsignedError('android', 'dev'), null);
  assert.match(unsignedError('android', 'production'), /production/);
  assert.match(unsignedError('ios', 'dev'), /iOS/);
  assert.match(unsignedError('both', 'dev'), /iOS/);
});

test('the CLI is pinned to an exact version, never @latest', () => {
  assert.match(CAPGO_CLI, /^@capgo\/cli@\d+\.\d+\.\d+$/);
});

test('parses the real CLI v8 `bundle zip --json` output: the zip is `filename`, `bundle` is the version', () => {
  // Shape printed by @capgo/cli 8.46/8.68: { bundle: <package.json version>, filename, checksum }.
  const out = JSON.stringify({ bundle: '0.0.1', filename: ZIP, checksum: PLAIN }, null, 2);
  assert.deepEqual(parseZipOutput(out, (p) => p === ZIP), { zipPath: ZIP, plainChecksum: PLAIN });
});

test('zip parsing fails loudly instead of treating the bundle version as a path', () => {
  const out = JSON.stringify({ bundle: '0.0.1', checksum: PLAIN });
  assert.throws(() => parseZipOutput(out, (p) => p === '0.0.1'), /filename/);
});

test('plainChecksumError accepts only the 64-hex sha256 (a CRC32 cannot be signed)', () => {
  assert.equal(plainChecksumError(PLAIN), null);
  assert.match(plainChecksumError('1a2b3c4d'), /SHA256/);
  assert.match(plainChecksumError(''), /SHA256/);
});

test('parses CLI v8 --json output into session key, encrypted checksum and encrypted file', () => {
  const out = `Encrypting checksum with V3\n${JSON.stringify({ checksum: ENCRYPTED, filename: ENC_ZIP, ivSessionKey: IV }, null, 2)}`;
  assert.deepEqual(parseEncryptOutput(out, ZIP, PLAIN, exists), {
    sessionKey: IV,
    checksum: ENCRYPTED,
    encryptedPath: ENC_ZIP,
  });
});

test('parses the text-mode output too', () => {
  const out = `✔ Encoded Checksum: ${ENCRYPTED}\n✔ ivSessionKey: ${IV}\n✔ Encrypted zip saved at ${ENC_ZIP}\nDone ✅`;
  assert.deepEqual(parseEncryptOutput(out, ZIP, PLAIN, exists), {
    sessionKey: IV,
    checksum: ENCRYPTED,
    encryptedPath: ENC_ZIP,
  });
});

test('fails hard (no plain-checksum fallback) when no encrypted checksum is printed', () => {
  const out = JSON.stringify({ filename: ENC_ZIP, ivSessionKey: IV });
  assert.throws(() => parseEncryptOutput(out, ZIP, PLAIN, exists), (e) => {
    assert.match(e.message, /encrypted checksum/);
    assert.match(e.message, /ENCRYPTED checksum it printed/);
    assert.match(e.message, /never the plain/);
    assert.doesNotMatch(e.message, /--checksum <plainChecksum>/);
    return true;
  });
});

test('refuses an "encrypted" checksum that is really the plain sha256', () => {
  const out = JSON.stringify({ checksum: PLAIN, filename: ENC_ZIP, ivSessionKey: IV });
  assert.throws(() => parseEncryptOutput(out, ZIP, PLAIN, exists), /PLAIN sha256/);
});

test('fails when the session key is missing or malformed', () => {
  assert.throws(() => parseEncryptOutput(JSON.stringify({ checksum: ENCRYPTED, filename: ENC_ZIP }), ZIP, PLAIN, exists), /ivSessionKey/);
  assert.throws(
    () => parseEncryptOutput(JSON.stringify({ checksum: ENCRYPTED, filename: ENC_ZIP, ivSessionKey: 'aXY=:c2Vzc2lvbg==' }), ZIP, PLAIN, exists),
    /ivSessionKey/,
  );
});

test('never falls back to uploading the plain zip', () => {
  const out = JSON.stringify({ checksum: ENCRYPTED, ivSessionKey: IV, filename: ZIP });
  assert.throws(() => parseEncryptOutput(out, ZIP, PLAIN, (p) => p === ZIP), /encrypted zip/);
});

test('falls back to the conventional <zip>_encrypted.zip name when the CLI omits filename', () => {
  const out = JSON.stringify({ checksum: ENCRYPTED, ivSessionKey: IV });
  assert.equal(parseEncryptOutput(out, ZIP, PLAIN, exists).encryptedPath, ENC_ZIP);
});

test('signedChecksumError mirrors OtaChecksum: exactly 512 hex (RSA-2048)', () => {
  assert.equal(signedChecksumError(ENCRYPTED), null);
  // The plugin rejects any ciphertext that isn't 256 bytes.
  assert.match(signedChecksumError('cd'.repeat(512)), /512/); // RSA-4096 sized
  assert.match(signedChecksumError('a'.repeat(511)), /got 511/); // truncated paste
  assert.match(signedChecksumError(PLAIN), /PLAIN/);
  assert.match(signedChecksumError(''), /no encrypted checksum/);
  assert.match(signedChecksumError('zz'.repeat(256)), /hex/);
});

test('sessionKeyError mirrors OtaSigning::sessionKeyError', () => {
  assert.equal(sessionKeyError(IV), null);
  assert.match(sessionKeyError(''), /no session key/);
  assert.match(sessionKeyError('iv:key'), /ivSessionKey/);
  assert.match(sessionKeyError(IV.slice(0, -10)), /ivSessionKey/);
});

test('isPlainZip spots the zip header so the plain zip is never uploaded as signed', () => {
  assert.equal(isPlainZip(Buffer.from('PK\x03\x04rest', 'latin1')), true);
  assert.equal(isPlainZip(Buffer.from('PK\x05\x06', 'latin1')), true);
  assert.equal(isPlainZip(Buffer.from([0x8a, 0x11, 0x5c, 0xe0])), false);
  assert.equal(isPlainZip(Buffer.from('PK')), false);
});

test('publishedBundles handles both the single and the `both` response shapes', () => {
  assert.deepEqual(publishedBundles({ bundle: { id: 1 } }), [{ id: 1 }]);
  assert.deepEqual(publishedBundles({ bundles: [{ id: 1 }, { id: 2 }] }), [{ id: 1 }, { id: 2 }]);
  assert.deepEqual(publishedBundles({}), []);
});
