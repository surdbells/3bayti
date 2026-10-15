import {
  OTA_MAX_BYTES,
  compareOtaVersions,
  isOtaVersion,
  isPlainZipHeader,
  otaChecksumError,
  otaSessionKeyError,
  otaUnsignedError,
  servedOtaBundleIds,
} from './ota-rules';

/** base64 of n bytes of `byte`, like `bundle encrypt` prints (btoa: no Node Buffer in Karma). */
const b64 = (n: number, byte: number) => btoa(String.fromCharCode(...new Array<number>(n).fill(byte)));
const SESSION_KEY = `${b64(16, 1)}:${b64(256, 2)}`;

describe('ota-rules', () => {
  it('accepts only numeric, canonical 1-4 segment versions', () => {
    for (const v of ['1', '1.7', '1.7.1', '1.0.0.4', ' 1.7.1 ', '0.0.0']) expect(isOtaVersion(v)).withContext(v).toBeTrue();
    for (const v of ['', '1.7.1-beta.1', 'v1.7', '1.7.', '1.0.0.0.4', '1.08', '01.7', '1.1000000000']) {
      expect(isOtaVersion(v)).withContext(v).toBeFalse();
    }
  });

  it('compares versions numerically', () => {
    expect(compareOtaVersions('1.6.10', '1.6.9')).toBeGreaterThan(0);
    expect(compareOtaVersions('1.6', '1.6.0')).toBe(0);
  });

  it('rejects the plain checksum for a signed bundle', () => {
    const plain = 'a'.repeat(64);
    expect(otaChecksumError(plain, true)).toContain('PLAIN');
    expect(otaChecksumError('', true)).not.toBeNull();
    expect(otaChecksumError('ab'.repeat(256), true)).toBeNull();
    expect(otaChecksumError(plain, false)).toBeNull();
    expect(otaChecksumError('', false)).toBeNull();
    expect(otaChecksumError('ab'.repeat(256), false)).not.toBeNull();
  });

  it('requires exactly 512 hex for a signed checksum (the plugin only takes RSA-2048)', () => {
    expect(otaChecksumError('a'.repeat(511), true)).toContain('got 511');
    expect(otaChecksumError('ab'.repeat(512), true)).toContain('exactly 512');
  });

  it('checks the session key shape', () => {
    expect(otaSessionKeyError(SESSION_KEY)).toBeNull();
    expect(otaSessionKeyError('iv:key')).not.toBeNull();
    expect(otaSessionKeyError(SESSION_KEY.slice(0, -10))).not.toBeNull();
  });

  it('allows unsigned bundles only for android off production', () => {
    expect(otaUnsignedError('android', 'beta')).toBeNull();
    expect(otaUnsignedError('android', 'production')).toContain('Production');
    expect(otaUnsignedError('ios', 'beta')).toContain('iOS');
    expect(otaUnsignedError('both', 'beta')).toContain('iOS');
  });

  it('recognises the plain zip header', () => {
    expect(isPlainZipHeader(new Uint8Array([0x50, 0x4b, 0x03, 0x04]))).toBeTrue();
    expect(isPlainZipHeader(new Uint8Array([0x50, 0x4b, 0x05, 0x06]))).toBeTrue();
    expect(isPlainZipHeader(new Uint8Array([0x8a, 0x11, 0x5c, 0xe0]))).toBeFalse();
    expect(isPlainZipHeader(new Uint8Array([0x50, 0x4b]))).toBeFalse();
  });

  it('marks the highest active version as served, not the newest upload', () => {
    const row = (id: number, version: string, is_active = true, platform = 'android') => ({
      id, version, is_active, platform, app_id: 'com.threebayti.app', channel: 'production',
    });
    const served = servedOtaBundleIds([
      row(1, '1.7.3'),
      row(2, '1.7.2'), // uploaded later, lower version
      row(3, '1.7.10', false),
      row(4, '1.7.1', true, 'ios'),
    ]);
    expect([...served].sort()).toEqual([1, 4]);
  });

  it('caps uploads at 50 MB like the API', () => {
    expect(OTA_MAX_BYTES).toBe(50 * 1024 * 1024);
  });
});
