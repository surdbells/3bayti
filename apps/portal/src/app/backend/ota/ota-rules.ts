/**
 * Client-side mirrors of the API's OTA rules, so the upload form fails fast
 * with the same message the server would give. Keep in step with:
 *   - OtaVersion::PATTERN / compare   (apps/api/src/Domain/Ota/OtaVersion.php)
 *   - OtaChecksum::validate           (apps/api/src/Domain/Ota/OtaChecksum.php)
 *   - OtaSigning                      (apps/api/src/Domain/Ota/OtaSigning.php)
 *   - UploadOtaBundleController::MAX_BYTES + its min_native / max_native checks
 *   - OtaBundle::getEffectiveMaxNativeVersion (max_native, else min_native)
 *   - CheckOtaUpdateController::pick  (which active bundle is served)
 *   - OtaRollbackService::rollbackTo  (which bundles a rollback deactivates)
 */

/** Numeric only, 1-4 canonical segments (no leading zeros, <= 9 digits); no pre-release suffix. */
export const OTA_VERSION_PATTERN = /^(0|[1-9]\d{0,8})(\.(0|[1-9]\d{0,8})){0,3}$/;

/** Upload cap, UploadOtaBundleController::MAX_BYTES (50 MB). */
export const OTA_MAX_BYTES = 50 * 1024 * 1024;

const PLAIN_CHECKSUM = /^[a-f0-9]{64}$/i;
/** The plugin accepts exactly a 256-byte (RSA-2048) encrypted checksum = 512 hex. */
const SIGNED_CHECKSUM = /^[a-f0-9]{512}$/i;
/** `bundle encrypt` ivSessionKey: base64(16-byte IV) ":" base64(256-byte RSA-2048 block). */
const SESSION_KEY = /^[A-Za-z0-9+/]{22}==:[A-Za-z0-9+/]{342}==$/;

export function isOtaVersion(value: string): boolean {
  return OTA_VERSION_PATTERN.test(value.trim());
}

/** Numeric version compare (mirrors OtaVersion::compare). */
export function compareOtaVersions(a: string, b: string): number {
  const pa = a.split('-')[0].split('.').map((p) => parseInt(p, 10) || 0);
  const pb = b.split('-')[0].split('.').map((p) => parseInt(p, 10) || 0);
  for (let i = 0; i < Math.max(pa.length, pb.length); i++) {
    const d = (pa[i] ?? 0) - (pb[i] ?? 0);
    if (d !== 0) return d;
  }
  return 0;
}

/** Null when the checksum is acceptable (mirrors OtaChecksum::validate), else why not. */
export function otaChecksumError(checksum: string, signed: boolean): string | null {
  const cs = checksum.trim();
  if (signed) {
    if (!cs) return 'A signed bundle needs the encrypted checksum printed by `bundle encrypt`.';
    if (PLAIN_CHECKSUM.test(cs)) {
      return 'That is the PLAIN sha256 (64 hex). A signed bundle needs the ENCRYPTED checksum printed by `bundle encrypt` (512 hex).';
    }
    return SIGNED_CHECKSUM.test(cs)
      ? null
      : `Checksum must be the hex encrypted checksum from \`bundle encrypt\`, exactly 512 hex chars (got ${cs.length}). Check for a truncated paste.`;
  }
  if (!cs) return null; // computed server-side
  return PLAIN_CHECKSUM.test(cs) ? null : 'An unsigned bundle checksum must be the 64-hex SHA256 (or leave it empty).';
}

/** Null when the session key has the ivSessionKey shape (mirrors OtaSigning::sessionKeyError). */
export function otaSessionKeyError(sessionKey: string): string | null {
  return SESSION_KEY.test(sessionKey.trim())
    ? null
    : 'Session key must be the ivSessionKey printed by `bundle encrypt` (<24-char base64 IV>:<344-char base64 key>). Check for a truncated paste.';
}

/**
 * Null when an UNSIGNED bundle may be published (mirrors
 * OtaSigning::unsignedError): only for android on a non-production channel.
 * iOS can't install unsigned bundles (the app embeds a public key) and
 * production must be signed.
 */
export function otaUnsignedError(platform: string, channel: string): string | null {
  if (platform === 'ios' || platform === 'both') {
    return 'iOS only installs SIGNED bundles. Upload the *_encrypted.zip from `bundle encrypt` with its Session key and Checksum.';
  }
  if (channel.trim() === 'production') {
    return 'Production bundles must be SIGNED. Upload the *_encrypted.zip from `bundle encrypt` with its Session key and Checksum.';
  }
  return null;
}

/**
 * Whether the first bytes are a plain .zip header (mirrors
 * OtaSigning::looksLikePlainZip). `bundle encrypt` leaves the plain zip next
 * to its *_encrypted.zip; a signed upload of the plain one fails on every device.
 */
export function isPlainZipHeader(bytes: Uint8Array): boolean {
  return (
    bytes.length >= 4 &&
    bytes[0] === 0x50 &&
    bytes[1] === 0x4b &&
    ((bytes[2] === 0x03 && bytes[3] === 0x04) || (bytes[2] === 0x05 && bytes[3] === 0x06))
  );
}

/**
 * Null when the upload form's Min / Max native are acceptable (mirrors
 * OtaVersion::nativeRangeError, used by UploadOtaBundleController), else why
 * not. Min native is REQUIRED: a bundle only reaches app builds whose native
 * version is inside [min, max] and Max defaults to Min, so there is no safe
 * default (the old silent 0.0.0 served every shell, TestFlight and test
 * builds included). Max is optional (empty = Min) and must not be below Min,
 * and a range ending at 0 (e.g. the old 0.0.0 default) matches no real app.
 */
export function otaNativeRangeError(minNative: string, maxNative: string): string | null {
  const min = minNative.trim();
  const max = maxNative.trim() || min; // empty Max defaults to Min
  if (!min) return 'Min native is required: the app version this bundle was built for (e.g. 1.8).';
  if (!isOtaVersion(min)) return 'Min native must be numeric, e.g. 1.8 or 1.8.1.';
  if (!isOtaVersion(max)) return 'Max native must be numeric, e.g. 1.9 (or leave it empty to use Min native).';
  if (compareOtaVersions(max, min) < 0) return `Max native (${max}) must not be lower than Min native (${min}).`;
  if (compareOtaVersions(max, '0') === 0) {
    return `A native range ending at ${max} matches no installed app (devices report their app version, e.g. 1.8). Set Min native to the app version this bundle was built for.`;
  }
  return null;
}

interface NativeRange {
  min_native_version: string;
  /** Null on rows published before max_native existed: those behave as max = min. */
  max_native_version?: string | null;
}

/**
 * The highest native app version a bundle is offered to (mirrors
 * OtaBundle::getEffectiveMaxNativeVersion): its max_native_version, or its
 * min_native_version when no max was ever stored.
 */
export function effectiveMaxNative(b: NativeRange): string {
  const max = (b.max_native_version ?? '').trim();
  return max !== '' ? max : b.min_native_version;
}

/** "1.8" for a single native version, "1.8 – 1.9" for a range (the bundles table). */
export function otaNativeRangeLabel(b: NativeRange): string {
  const max = effectiveMaxNative(b);
  return compareOtaVersions(max, b.min_native_version) === 0 ? b.min_native_version : `${b.min_native_version} – ${max}`;
}

/**
 * Whether the bundle's effective native range ends at 0 (e.g. a row published
 * with the old 0.0.0 Min native default and no max): no installed app reports
 * that version, so no device is ever offered it. The same range the upload
 * refuses (OtaVersion::nativeRangeError / otaNativeRangeError).
 */
export function otaRangeMatchesNoApp(b: NativeRange): boolean {
  return compareOtaVersions(effectiveMaxNative(b), '0') === 0;
}

/**
 * Null when every app build in [min, max] still on its BUILT-IN web code
 * (a fresh install or a store update) can take a bundle of this version, else
 * a warning. On built-in code the updater plugin reports the app's marketing
 * version as its current bundle version, and the update endpoint only offers
 * a strictly newer version, so the bundle version must be above Max native
 * (e.g. a 1.8.1 test shell needs 1.8.2 or higher).
 */
export function otaBuiltinReachWarning(version: string, minNative: string, maxNative: string): string | null {
  const v = version.trim();
  const min = minNative.trim();
  const max = maxNative.trim() || min;
  if (!isOtaVersion(v) || !isOtaVersion(min) || !isOtaVersion(max)) return null; // the validators report these
  if (compareOtaVersions(v, max) > 0) return null;
  const from = compareOtaVersions(v, min) < 0 ? min : v;
  const missed = compareOtaVersions(from, max) === 0 ? `app version ${max}` : `app versions ${from} to ${max}`;
  return (
    `Version ${v} is not above Max native ${max}. An app build still on its built-in web code (a fresh install or a ` +
    `store update) reports its app version as its current bundle and is only offered a newer one, so ${missed} on ` +
    `built-in code will never be offered this bundle. Use a version above ${max} to reach them.`
  );
}

/**
 * The next native version a device can report after `v`. The update endpoint
 * only trusts numeric version_build values of at most 4 segments
 * (OtaVersion::isComparable), so after 1.8 (= 1.8.0.0) comes 1.8.0.1.
 */
function nextNativeVersion(v: string): string {
  const parts = v.split('.').map((p) => parseInt(p, 10) || 0);
  while (parts.length < 4) parts.push(0);
  parts[3]++;
  return parts.join('.');
}

/** Whether the union of `ranges` covers every native version in [min, max]. */
function rangeCovered(min: string, max: string, ranges: ReadonlyArray<{ min: string; max: string }>): boolean {
  let need = min; // lowest version of [min, max] not yet known to be covered
  const sorted = [...ranges].sort((a, b) => compareOtaVersions(a.min, b.min));
  for (const r of sorted) {
    if (compareOtaVersions(r.min, need) > 0) return false; // nothing covers `need`
    if (compareOtaVersions(r.max, need) >= 0) {
      if (compareOtaVersions(r.max, max) >= 0) return true;
      need = nextNativeVersion(r.max);
    }
  }
  return false;
}

interface ServableBundle extends NativeRange {
  id: number;
  app_id: string;
  platform: string;
  channel: string;
  version: string;
  is_active: boolean;
}

/**
 * Ids of the bundles some app build is actually offered right now. A device
 * on native version N is served the HIGHEST-version active bundle of its
 * app/platform/channel whose native range [min, effective max] contains N
 * (by version, not upload time). So an active bundle is live unless
 * higher-version active bundles cover its WHOLE native range; with different
 * ranges (e.g. production 1.8 + a 1.9 shell) several bundles per
 * platform/channel are live at once. A row whose range ends at 0
 * (otaRangeMatchesNoApp) is never live: no app reports that version.
 */
export function servedOtaBundleIds(bundles: readonly ServableBundle[]): Set<number> {
  const active = bundles.filter((b) => b.is_active);
  const served = new Set<number>();
  for (const b of active) {
    if (otaRangeMatchesNoApp(b)) continue;
    const higher = active
      .filter(
        (o) =>
          o.app_id === b.app_id &&
          o.platform === b.platform &&
          o.channel === b.channel &&
          compareOtaVersions(o.version, b.version) > 0,
      )
      .map((o) => ({ min: o.min_native_version, max: effectiveMaxNative(o) }));
    if (!rangeCovered(b.min_native_version, effectiveMaxNative(b), higher)) served.add(b.id);
  }
  return served;
}

/** Whether `inner`'s whole native range lies inside `outer`'s. */
function nativeRangeWithin(inner: NativeRange, outer: NativeRange): boolean {
  return (
    compareOtaVersions(outer.min_native_version, inner.min_native_version) <= 0 &&
    compareOtaVersions(effectiveMaxNative(inner), effectiveMaxNative(outer)) <= 0
  );
}

/** Whether two native ranges share at least one version (mirrors OtaBundle::nativeRangeOverlaps). */
function nativeRangesOverlap(a: NativeRange, b: NativeRange): boolean {
  return (
    compareOtaVersions(a.min_native_version, effectiveMaxNative(b)) <= 0 &&
    compareOtaVersions(b.min_native_version, effectiveMaxNative(a)) <= 0
  );
}

/**
 * Whether a rollback to `source` switches `other` off. Mirrors
 * OtaRollbackService::rollbackTo: the active bundles of the source's
 * app/platform/channel whose native range OVERLAPS the source's; bundles for
 * other app versions only (e.g. production 1.8 while rolling back the
 * TestFlight 1.8.1 bundle) stay active.
 */
function rollbackDeactivates(source: ServableBundle, other: ServableBundle): boolean {
  return (
    other.is_active &&
    other.app_id === source.app_id &&
    other.platform === source.platform &&
    other.channel === source.channel &&
    nativeRangesOverlap(source, other)
  );
}

export interface OtaRollbackImpact<T> {
  /**
   * The source's range ends at 0 (otaRangeMatchesNoApp): the rollback would
   * reach no app, and the API refuses it (OtaRollbackException::unreachableRange).
   */
  matchesNoApp: boolean;
  /** The other active bundles the rollback switches off (overlapping native ranges). */
  deactivated: T[];
  /**
   * The live ones among `deactivated` whose native range the source's does
   * NOT fully cover: app builds outside the source's range that they serve
   * today lose them, and are then offered only another active bundle that
   * covers them, if any.
   */
  strandedLive: T[];
}

/** What a one-click rollback to `source` does to the bundles served today (the confirm dialog). */
export function otaRollbackImpact<T extends ServableBundle>(source: T, bundles: readonly T[]): OtaRollbackImpact<T> {
  const live = servedOtaBundleIds(bundles);
  const deactivated = bundles.filter((b) => b.id !== source.id && rollbackDeactivates(source, b));
  return {
    matchesNoApp: otaRangeMatchesNoApp(source),
    deactivated,
    strandedLive: deactivated.filter((b) => live.has(b.id) && !nativeRangeWithin(b, source)),
  };
}
