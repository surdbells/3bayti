/**
 * Client-side mirrors of the API's OTA rules, so the upload form fails fast
 * with the same message the server would give. Keep in step with:
 *   - OtaVersion::PATTERN / compare   (apps/api/src/Domain/Ota/OtaVersion.php)
 *   - OtaChecksum::validate           (apps/api/src/Domain/Ota/OtaChecksum.php)
 *   - OtaSigning                      (apps/api/src/Domain/Ota/OtaSigning.php)
 *   - UploadOtaBundleController::MAX_BYTES
 *   - CheckOtaUpdateController::pick  (which active bundle is served)
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

interface ServableBundle {
  id: number;
  app_id: string;
  platform: string;
  channel: string;
  version: string;
  is_active: boolean;
}

/**
 * Ids of the bundle each app/platform/channel serves to an up-to-date shell:
 * the HIGHEST-version active row (the endpoint picks by version, not upload
 * time). An older shell may be served a lower bundle if the highest one needs
 * a newer min_native.
 */
export function servedOtaBundleIds(bundles: readonly ServableBundle[]): Set<number> {
  const best = new Map<string, ServableBundle>();
  for (const b of bundles) {
    if (!b.is_active) continue;
    const key = `${b.app_id}|${b.platform}|${b.channel}`;
    const current = best.get(key);
    if (!current || compareOtaVersions(b.version, current.version) > 0) {
      best.set(key, b);
    }
  }
  return new Set([...best.values()].map((b) => b.id));
}
