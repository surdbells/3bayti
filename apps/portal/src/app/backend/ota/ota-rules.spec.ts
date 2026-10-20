import {
  OTA_MAX_BYTES,
  compareOtaVersions,
  effectiveMaxNative,
  isOtaVersion,
  isPlainZipHeader,
  otaBuiltinReachWarning,
  otaChecksumError,
  otaNativeRangeError,
  otaNativeRangeLabel,
  otaRangeMatchesNoApp,
  otaRollbackImpact,
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
      min_native_version: '1.8', max_native_version: '1.8',
    });
    const served = servedOtaBundleIds([
      row(1, '1.7.3'),
      row(2, '1.7.2'), // uploaded later, lower version
      row(3, '1.7.10', false),
      row(4, '1.7.1', true, 'ios'),
    ]);
    expect([...served].sort()).toEqual([1, 4]);
  });

  describe('native range', () => {
    const row = (id: number, version: string, min: string, max: string | null, is_active = true) => ({
      id, version, is_active, platform: 'ios', app_id: 'com.threebayti.app', channel: 'production',
      min_native_version: min, max_native_version: max,
    });
    const served = (...rows: ReturnType<typeof row>[]) => [...servedOtaBundleIds(rows)].sort();

    it('requires Min native and checks Max native (optional, defaults to Min, never below it)', () => {
      expect(otaNativeRangeError('', '')).toContain('required');
      expect(otaNativeRangeError('  ', '1.9')).toContain('required');
      expect(otaNativeRangeError('1.8', '')).toBeNull();
      expect(otaNativeRangeError(' 1.8 ', ' 1.9 ')).toBeNull();
      expect(otaNativeRangeError('1.8', '1.8.0')).toBeNull(); // equal: 1.8 == 1.8.0
      expect(otaNativeRangeError('1.08', '')).toContain('Min native must be numeric');
      expect(otaNativeRangeError('1.8', '1.9-beta')).toContain('Max native must be numeric');
      expect(otaNativeRangeError('1.9', '1.8.5')).toContain('must not be lower');
      // The old 0.0.0 default matches no real app (mirrors OtaVersion::nativeRangeError).
      expect(otaNativeRangeError('0.0.0', '')).toContain('matches no installed app');
      expect(otaNativeRangeError('0', '0.0')).toContain('matches no installed app');
      expect(otaNativeRangeError('0.0.0', '1.9')).toBeNull();
    });

    it('defaults the effective max to min for rows stored without one', () => {
      expect(effectiveMaxNative({ min_native_version: '1.8', max_native_version: null })).toBe('1.8');
      expect(effectiveMaxNative({ min_native_version: '1.8' })).toBe('1.8');
      expect(effectiveMaxNative({ min_native_version: '1.8', max_native_version: '1.9' })).toBe('1.9');
    });

    it('labels a single native version or a range', () => {
      expect(otaNativeRangeLabel({ min_native_version: '1.8', max_native_version: null })).toBe('1.8');
      expect(otaNativeRangeLabel({ min_native_version: '1.8', max_native_version: '1.8.0' })).toBe('1.8');
      expect(otaNativeRangeLabel({ min_native_version: '1.8', max_native_version: '1.9' })).toBe('1.8 – 1.9');
    });

    it('keeps a lower bundle live for the shells a higher one does not cover', () => {
      // 2.0 targets the 1.9 TestFlight shell only; 1.9.5 still serves the 1.8 production shell.
      expect(served(row(1, '2.0', '1.9', '1.9'), row(2, '1.9.5', '1.8', '1.8'))).toEqual([1, 2]);
      // Overlapping: 1.9.5 covers 1.8 - 1.9, 2.0 only 1.9, so 1.8 shells still get 1.9.5.
      expect(served(row(1, '2.0', '1.9', '1.9'), row(2, '1.9.5', '1.8', '1.9'))).toEqual([1, 2]);
      // A legacy row (no max) only covers its min, so it doesn't hide a bundle for newer shells.
      expect(served(row(1, '2.0', '1.8', null), row(2, '1.9.5', '1.9', null))).toEqual([1, 2]);
    });

    it('hides a bundle whose whole range higher bundles cover, together', () => {
      expect(served(row(1, '2.0', '1.8', '1.9'), row(2, '1.9.5', '1.8', '1.8'))).toEqual([1]);
      // 1.8 + [1.8.0.1, 1.9] leave no version of [1.8, 1.9] for 1.5 (device versions have <= 4 segments).
      expect(served(row(1, '2.0', '1.8', '1.8'), row(2, '1.9', '1.8.0.1', '1.9'), row(3, '1.5', '1.8', '1.9'))).toEqual([1, 2]);
      // ...but a gap (1.8.0.1 - 1.8.0.x) keeps it live.
      expect(served(row(1, '2.0', '1.8', '1.8'), row(2, '1.9', '1.8.1', '1.9'), row(3, '1.5', '1.8', '1.9'))).toEqual([1, 2, 3]);
      // Inactive rows never cover anything.
      expect(served(row(1, '2.0', '1.8', '1.9', false), row(2, '1.9.5', '1.8', '1.8'))).toEqual([2]);
    });

    it('never marks a range ending at 0 (legacy 0.0.0 default) as live', () => {
      expect(otaRangeMatchesNoApp({ min_native_version: '0.0.0', max_native_version: null })).toBeTrue();
      expect(otaRangeMatchesNoApp({ min_native_version: '0', max_native_version: '0.0' })).toBeTrue();
      expect(otaRangeMatchesNoApp({ min_native_version: '0.0.0', max_native_version: '1.8' })).toBeFalse();
      expect(otaRangeMatchesNoApp({ min_native_version: '1.8' })).toBeFalse();
      expect(served(row(1, '1.8.3', '0.0.0', null), row(2, '1.8.2', '1.8', null))).toEqual([2]);
      expect(served(row(1, '1.8.3', '0.0.0', '1.8'))).toEqual([1]);
    });

    it('warns when the version is not above Max native (built-in code reports the app version)', () => {
      expect(otaBuiltinReachWarning('1.8.4', '1.8', '')).toBeNull();
      expect(otaBuiltinReachWarning('1.8.2', '1.8.1', '')).toBeNull();
      expect(otaBuiltinReachWarning('1.8.4', '1.7', '1.8')).toBeNull();
      expect(otaBuiltinReachWarning('1.8', '1.8', '')).toContain('app version 1.8 on');
      expect(otaBuiltinReachWarning('1.8.0', '1.8', '1.8')).toContain('app version 1.8 on'); // 1.8.0 == 1.8
      expect(otaBuiltinReachWarning('1.8.4', '1.8', '1.9')).toContain('app versions 1.8.4 to 1.9');
      expect(otaBuiltinReachWarning('1.7.9', '1.8', '1.9')).toContain('app versions 1.8 to 1.9');
      // Invalid input is the validators' job, not a warning.
      expect(otaBuiltinReachWarning('1.8-beta', '1.8', '')).toBeNull();
      expect(otaBuiltinReachWarning('1.8', '', '')).toBeNull();
    });

    describe('rollback impact', () => {
      const scoped = (id: number, version: string, min: string, max: string | null, is_active = true, platform = 'ios') => ({
        ...row(id, version, min, max, is_active),
        platform,
      });

      it('flags a source whose range matches no app', () => {
        const source = scoped(1, '1.8.2', '0.0.0', null, false);
        expect(otaRollbackImpact(source, [source, scoped(2, '1.8.3', '1.8', null)]).matchesNoApp).toBeTrue();
        const fixed = scoped(3, '1.8.2', '0.0.0', '1.8', false);
        expect(otaRollbackImpact(fixed, [fixed]).matchesNoApp).toBeFalse();
      });

      it('switches off only the active bundles whose native range overlaps the source (mirrors OtaRollbackService)', () => {
        const source = scoped(1, '1.8.2', '1.8', null, false);
        const prod = scoped(2, '1.8.4', '1.8', null); // same range: pulled, its shells get the rollback
        const test = scoped(3, '1.8.5', '1.8.1', null); // TestFlight range: no overlap, stays live
        const android = scoped(4, '1.8.6', '1.7', '1.8', true, 'android'); // other platform: untouched
        const stale = scoped(5, '1.8.0', '1.8', null, false); // inactive: untouched
        const impact = otaRollbackImpact(source, [source, prod, test, android, stale]);
        expect(impact.matchesNoApp).toBeFalse();
        expect(impact.deactivated.map((b) => b.id)).toEqual([2]);
        expect(impact.strandedLive).toEqual([]);
      });

      it('flags a live bundle it switches off that also serves app versions outside the source range', () => {
        const source = scoped(1, '1.8.2', '1.8', null, false);
        const wide = scoped(2, '1.8.4', '1.7', '1.9'); // overlaps [1.8]: pulled, so 1.7 and 1.9 shells lose it
        const impact = otaRollbackImpact(source, [source, wide]);
        expect(impact.deactivated.map((b) => b.id)).toEqual([2]);
        expect(impact.strandedLive.map((b) => b.id)).toEqual([2]);
        const wideSource = scoped(3, '1.8.1', '1.7', '1.8', false);
        const narrow = scoped(4, '1.8.4', '1.8', null);
        expect(otaRollbackImpact(wideSource, [wideSource, narrow]).deactivated.map((b) => b.id)).toEqual([4]);
        expect(otaRollbackImpact(wideSource, [wideSource, narrow]).strandedLive).toEqual([]);
      });
    });
  });

  it('caps uploads at 50 MB like the API', () => {
    expect(OTA_MAX_BYTES).toBe(50 * 1024 * 1024);
  });
});
