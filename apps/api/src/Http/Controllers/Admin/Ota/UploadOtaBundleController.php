<?php

declare(strict_types=1);

namespace Bayti\Api\Http\Controllers\Admin\Ota;

use Bayti\Api\Domain\Ota\OtaBundle;
use Bayti\Api\Domain\Ota\OtaBundleRepository;
use Bayti\Api\Domain\Ota\OtaBundleStorageService;
use Bayti\Api\Domain\Ota\OtaChecksum;
use Bayti\Api\Domain\Ota\OtaSigning;
use Bayti\Api\Domain\Ota\OtaVersion;
use Bayti\Api\Http\Errors\HttpException;
use Bayti\Api\Http\Responder;
use Bayti\Api\Http\Serializers\OtaBundleSerializer;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\UploadedFileInterface;

/**
 * POST /v3/admin/ota/bundles, upload + register an OTA web bundle from the
 * portal (no shell needed).
 *
 * multipart/form-data with the .zip in field "file"; the release metadata comes
 * in the query string (matching the existing /v3/upload convention):
 *   ?platform=android|ios|both&version=1.0.7&channel=production&min_native=1.8
 *   [&max_native=1.9&app_id=…&session_key=…&checksum=…&notes=…]
 *
 * Stores the .zip on the app server (var/uploads/ota/…, served statically by
 * Apache), computes its SHA256 (unsigned bundles), and inserts the ota_bundles
 * row(s). Devices then pick it up via POST /v3/ota/updates.
 *
 * Native range ({@see OtaBundle}): the bundle is served to devices whose native
 * app (marketing) version is within [min_native, max_native], inclusive.
 * min_native is REQUIRED (no silent 0.0.0 default: under "max defaults to
 * min" that would serve nobody); max_native is optional, defaults to
 * min_native and is stored explicitly. Both must be canonical versions
 * ({@see OtaVersion::nativeRangeError()}) with max >= min, else 400. Leaving
 * max at min is what keeps a production bundle off newer TestFlight / Android
 * test shells (e.g. 1.8.1).
 *
 * Signing ({@see OtaSigning}): a bundle is SIGNED when session_key is sent;
 * it then needs the ivSessionKey shape, the 512-hex encrypted checksum, and
 * ciphertext bytes (the plain zip is refused: `bundle encrypt` writes the
 * ciphertext to a separate <zip>_encrypted.zip). UNSIGNED bundles are only
 * accepted for android on a non-production channel: iOS can't install them
 * at all (the app embeds a public key) and production must be signed.
 *
 * A version no device could ever be served is refused too: one already
 * published (duplicate), one whose file path is still in use (a rollback row
 * serving it after its original row was deleted), and one shadowed by an
 * active bundle that is at least as high and whose native range covers the
 * new one's ({@see OtaBundle::shadows()}).
 *
 * File/row consistency: the bytes are first written to a private staging file;
 * the rows are flushed and the staging file copied to its public path inside
 * ONE transaction, so a failing flush, copy or commit rolls the rows back and
 * deletes every file this request wrote. Neither an orphan file nor a row
 * pointing at a missing file survives a failed upload. (The copy runs just
 * before COMMIT rather than after it, so no device can be served a committed
 * row whose file doesn't exist yet.)
 *
 * Size: {@see MAX_BYTES}. PHP's upload_max_filesize / post_max_size
 * (docker/php.ini, and the aaPanel PHP 8.3 config in production, see
 * OTA-DEPLOY.md) and the web server's body limit must be at least this large.
 */
final class UploadOtaBundleController
{
    use Responder;

    private const APP_ID = 'com.threebayti.app';
    /**
     * 50 MB. Today's bundle is ~15 MB (apps/mobile/www is ~20 MB unzipped), so
     * this leaves room to grow. Keep in step with docker/php.ini
     * (upload_max_filesize = 50M, post_max_size = 52M), docker/default.conf
     * (client_max_body_size) and the portal's client-side check.
     */
    public const MAX_BYTES = 50 * 1024 * 1024;

    public function __construct(
        protected readonly ResponseFactoryInterface $responseFactory,
        private readonly EntityManagerInterface $em,
        private readonly OtaBundleStorageService $storage,
        private readonly OtaBundleSerializer $serializer,
    ) {
    }

    protected function getResponseFactory(): ResponseFactoryInterface
    {
        return $this->responseFactory;
    }

    public function __invoke(ServerRequestInterface $request): ResponseInterface
    {
        $q = $request->getQueryParams();
        $platform = trim((string) ($q['platform'] ?? ''));
        $version = trim((string) ($q['version'] ?? ''));
        $channel = trim((string) ($q['channel'] ?? '')) ?: OtaBundle::DEFAULT_CHANNEL;
        // Native range: min required, max defaults to min (validated below).
        // (Explicit '' check, not `?:`: "0" is falsy in PHP and must still be
        // validated as a max rather than silently replaced by the min.)
        $minNative = trim((string) ($q['min_native'] ?? ''));
        $maxNativeRaw = trim((string) ($q['max_native'] ?? ''));
        $maxNative = $maxNativeRaw !== '' ? $maxNativeRaw : $minNative;
        $appId = trim((string) ($q['app_id'] ?? '')) ?: self::APP_ID;
        $sessionKeyRaw = trim((string) ($q['session_key'] ?? ''));
        $sessionKey = $sessionKeyRaw !== '' ? $sessionKeyRaw : null;
        // Optional "what's new" summary shown to customers after this update.
        $notesRaw = trim((string) ($q['notes'] ?? ''));
        $notes = $notesRaw !== '' ? $notesRaw : null;
        // For signed/encrypted bundles the plugin verifies against the checksum
        // that `@capgo/cli bundle encrypt` emits (not a SHA256 of the
        // ciphertext), so the operator supplies it. Optional for plain bundles
        // (we compute it).
        $providedChecksum = trim((string) ($q['checksum'] ?? ''));
        $signed = $sessionKey !== null;

        // Metadata validation. `platform=both` fans one upload out to android +
        // ios, the web bundle (and its signed checksum/session key) is
        // platform-agnostic, so one file publishes to both.
        $targets = $platform === 'both'
            ? OtaBundle::ALL_PLATFORMS
            : (in_array($platform, OtaBundle::ALL_PLATFORMS, true) ? [$platform] : []);
        if ($targets === []) {
            throw HttpException::badRequest(
                'platform must be one of: ' . implode(', ', OtaBundle::ALL_PLATFORMS) . ', both',
            );
        }
        if (!OtaVersion::isValid($version)) {
            throw HttpException::badRequest('version must be numeric with 1-4 segments (e.g. 1.0.7); pre-release suffixes are not supported.');
        }
        $nativeRangeError = OtaVersion::nativeRangeError($minNative, $maxNative);
        if ($nativeRangeError !== null) {
            throw HttpException::badRequest($nativeRangeError);
        }
        // Both become storage path segments, so keep them to a safe charset
        // (and no trailing "." on app_id, which would alias another app's
        // directory; OtaBundleStorageService rejects it too).
        if (!preg_match('/^[A-Za-z0-9][A-Za-z0-9_-]{0,63}\z/', $channel)) {
            throw HttpException::badRequest('channel may only contain letters, digits, "_" and "-" (max 64).');
        }
        if (!preg_match('/^[A-Za-z0-9](?:[A-Za-z0-9._-]{0,253}[A-Za-z0-9_-])?\z/', $appId)) {
            throw HttpException::badRequest('app_id may only contain letters, digits, ".", "_" and "-", and must not end with ".".');
        }

        // Signing policy, before touching the upload.
        if ($signed) {
            $sessionKeyError = OtaSigning::sessionKeyError($sessionKeyRaw);
            if ($sessionKeyError !== null) {
                throw HttpException::badRequest($sessionKeyError);
            }
        } else {
            $unsignedError = OtaSigning::unsignedError($targets, $channel);
            if ($unsignedError !== null) {
                throw HttpException::badRequest($unsignedError);
            }
        }

        // The uploaded .zip.
        $upload = $request->getUploadedFiles()['file'] ?? null;
        if (!$upload instanceof UploadedFileInterface) {
            // PHP discards a body over post_max_size before we run, so it
            // surfaces here as "no file"; say what actually happened.
            $contentLength = (int) $request->getHeaderLine('Content-Length');
            $postMax = (string) ini_get('post_max_size');
            if ($contentLength > self::iniBytes($postMax)) {
                throw HttpException::badRequest(self::phpLimitMessage('post_max_size', $postMax));
            }
            throw HttpException::badRequest('No file in field "file". Send multipart/form-data with the bundle .zip in field "file".');
        }
        if ($upload->getError() === UPLOAD_ERR_INI_SIZE) {
            throw HttpException::badRequest(self::phpLimitMessage('upload_max_filesize', (string) ini_get('upload_max_filesize')));
        }
        if ($upload->getError() === UPLOAD_ERR_FORM_SIZE) {
            throw HttpException::badRequest(self::tooLargeMessage());
        }
        if ($upload->getError() !== UPLOAD_ERR_OK) {
            throw HttpException::badRequest('File upload failed (error code ' . $upload->getError() . ').');
        }
        $size = (int) $upload->getSize();
        if ($size <= 0) {
            throw HttpException::badRequest('The uploaded file is empty.');
        }
        if ($size > self::MAX_BYTES) {
            throw HttpException::badRequest(self::tooLargeMessage());
        }
        $bytes = (string) $upload->getStream();
        if ($signed) {
            // An encrypted bundle is ciphertext, not a readable .zip, and the
            // plugin checks it against the encrypt-command checksum, which we
            // can't derive, so the operator must supply it.
            if ($providedChecksum === '') {
                throw HttpException::badRequest('Signed bundles require the encrypted checksum from `@capgo/cli bundle encrypt` (send it as ?checksum=…).');
            }
            // `bundle encrypt` leaves the plain zip next to the ciphertext; a
            // signed upload of the plain zip fails AES decryption on every
            // device, so refuse it here.
            if (OtaSigning::looksLikePlainZip($bytes)) {
                throw HttpException::badRequest('This looks like the PLAIN .zip. A signed bundle must be the *_encrypted.zip written by `@capgo/cli bundle encrypt`.');
            }
            $checksum = $providedChecksum;
        } else {
            // Plain bundle: must be a real .zip (PK\x03\x04, or PK\x05\x06 for an
            // empty archive). Checksum is the SHA256 we compute (or an override).
            if (!OtaSigning::looksLikePlainZip($bytes)) {
                throw HttpException::badRequest('The uploaded file is not a .zip archive.');
            }
            $checksum = $providedChecksum !== '' ? $providedChecksum : hash('sha256', $bytes);
        }
        $checksumError = OtaChecksum::validate($checksum, $signed);
        if ($checksumError !== null) {
            throw HttpException::badRequest($checksumError);
        }

        // Servability guards for every target (atomic: reject before storing
        // anything if any platform fails one).
        /** @var OtaBundleRepository $repo */
        $repo = $this->em->getRepository(OtaBundle::class);
        foreach ($targets as $target) {
            $existing = $repo->findOneBy([
                'appId' => $appId,
                'platform' => $target,
                'channel' => $channel,
                'version' => $version,
            ]);
            if ($existing !== null) {
                throw HttpException::badRequest(sprintf(
                    'A bundle already exists for %s / %s version %s. Bump the version.',
                    $target,
                    $channel,
                    $version,
                ));
            }
            // The row was deleted but its file still backs another row (a
            // rollback re-publishes its source's file): re-uploading would
            // replace those bytes under the other row's checksum.
            if ($this->storage->isTaken($appId, $channel, $target, $version)) {
                throw HttpException::badRequest(sprintf(
                    'The file for %s / %s version %s is still served by another bundle (e.g. a rollback). Bump the version.',
                    $target,
                    $channel,
                    $version,
                ));
            }
            foreach ($repo->activeFor($appId, $target, $channel) as $active) {
                if ($active->shadows($version, $minNative, $maxNative)) {
                    throw HttpException::badRequest(sprintf(
                        'Version %s would never be served on %s / %s: active bundle %s (native %s) is at least as high '
                        . 'and runs on every app version this one targets (native %s). Use a version above %s, or '
                        . 'deactivate %s first.',
                        $version,
                        $target,
                        $channel,
                        $active->getVersion(),
                        $active->nativeRangeLabel(),
                        OtaVersion::rangeLabel($minNative, $maxNative),
                        $active->getVersion(),
                        $active->getVersion(),
                    ));
                }
            }
        }

        // Stage → (rows + promote) in one transaction → drop the staging file.
        $staging = $this->storage->stage($bytes);
        /** @var list<string> $promoted */
        $promoted = [];
        /** @var list<OtaBundle> $bundles */
        $bundles = [];
        try {
            $this->em->wrapInTransaction(function () use ($targets, $staging, $appId, $channel, $version, $checksum, $minNative, $maxNative, $sessionKey, $notes, &$promoted, &$bundles): void {
                foreach ($targets as $target) {
                    $bundle = new OtaBundle(
                        $appId,
                        $target,
                        $channel,
                        $version,
                        $this->storage->publicUrlFor($appId, $channel, $target, $version),
                        $checksum,
                        $minNative,
                        $sessionKey,
                        $notes,
                        maxNativeVersion: $maxNative,
                    );
                    $this->em->persist($bundle);
                    $bundles[] = $bundle;
                }
                $this->em->flush();

                foreach ($targets as $target) {
                    $promoted[] = $this->storage->promote($staging, $appId, $channel, $target, $version);
                }
            });
        } catch (\Throwable $e) {
            foreach ($promoted as $path) {
                $this->storage->discard($path);
            }
            throw $e;
        } finally {
            $this->storage->discard($staging);
        }

        // One platform → {bundle}; `both` → {bundles:[…]} (client accepts either).
        if (count($bundles) === 1) {
            return $this->created(['bundle' => $this->serializer->shape($bundles[0])]);
        }

        return $this->created([
            'bundles' => array_map(
                fn (OtaBundle $b): array => $this->serializer->shape($b),
                $bundles,
            ),
        ]);
    }

    private static function tooLargeMessage(): string
    {
        return sprintf('Bundle too large (max %d MB).', intdiv(self::MAX_BYTES, 1024 * 1024));
    }

    /**
     * A PHP ini limit (not our MAX_BYTES) rejected the upload, which happens
     * when the server's PHP config was not raised as OTA-DEPLOY.md describes.
     * Name the setting so the operator fixes the server, not the bundle.
     */
    private static function phpLimitMessage(string $setting, string $value): string
    {
        return sprintf(
            'Upload exceeds the server\'s PHP limit (%s=%s). The bundle cap is %d MB; raise %s per OTA-DEPLOY.md → Upload limits.',
            $setting,
            $value,
            intdiv(self::MAX_BYTES, 1024 * 1024),
            $setting,
        );
    }

    /** Parse a php.ini size ("8M", "512K", "1G"; "0"/"-1" = unlimited) to bytes. */
    private static function iniBytes(string $value): int
    {
        $value = trim($value);
        if ($value === '' || $value === '0' || $value === '-1') {
            return PHP_INT_MAX;
        }
        $number = (int) $value;

        return match (strtolower(substr($value, -1))) {
            'g' => $number * 1024 ** 3,
            'm' => $number * 1024 ** 2,
            'k' => $number * 1024,
            default => $number,
        };
    }
}
