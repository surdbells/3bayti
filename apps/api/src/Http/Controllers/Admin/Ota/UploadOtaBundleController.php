<?php

declare(strict_types=1);

namespace Bayti\Api\Http\Controllers\Admin\Ota;

use Bayti\Api\Domain\Ota\OtaBundle;
use Bayti\Api\Domain\Ota\OtaBundleStorageService;
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
 *   ?platform=android&version=1.0.7&channel=production&min_native=1.6.0
 *   [&app_id=…&session_key=…]
 *
 * Stores the .zip on the app server (var/uploads/ota/…, served statically by
 * Apache), computes its SHA256, and inserts the ota_bundles row. Devices then
 * pick it up via POST /v3/ota/updates.
 */
final class UploadOtaBundleController
{
    use Responder;

    private const APP_ID = 'com.threebayti.app';
    /** 60 MB, a web bundle is a few MB; this is a generous safety cap. */
    private const MAX_BYTES = 60 * 1024 * 1024;

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
        $minNative = trim((string) ($q['min_native'] ?? '')) ?: '0.0.0';
        $appId = trim((string) ($q['app_id'] ?? '')) ?: self::APP_ID;
        $sessionKeyRaw = trim((string) ($q['session_key'] ?? ''));
        $sessionKey = $sessionKeyRaw !== '' ? $sessionKeyRaw : null;
        // For signed/encrypted bundles the plugin verifies against the checksum
        // that `@capgo/cli encrypt` emits (not a SHA256 of the ciphertext), so
        // the operator supplies it. Optional for plain bundles (we compute it).
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
        if (!$this->isSemver($version)) {
            throw HttpException::badRequest('version must be semver (e.g. 1.0.7).');
        }
        if (!$this->isSemver($minNative)) {
            throw HttpException::badRequest('min_native must be semver (e.g. 1.6.0).');
        }

        // The uploaded .zip.
        $upload = $request->getUploadedFiles()['file'] ?? null;
        if (!$upload instanceof UploadedFileInterface) {
            throw HttpException::badRequest('No file in field "file". Send multipart/form-data with the bundle .zip in field "file".');
        }
        if ($upload->getError() !== UPLOAD_ERR_OK) {
            throw HttpException::badRequest('File upload failed (error code ' . $upload->getError() . ').');
        }
        $size = (int) $upload->getSize();
        if ($size <= 0) {
            throw HttpException::badRequest('The uploaded file is empty.');
        }
        if ($size > self::MAX_BYTES) {
            throw HttpException::badRequest('Bundle too large (max 60 MB).');
        }
        $bytes = (string) $upload->getStream();
        if ($signed) {
            // An encrypted bundle is ciphertext, not a readable .zip, and the
            // plugin checks it against the encrypt-command checksum, which we
            // can't derive, so the operator must supply it.
            if ($providedChecksum === '') {
                throw HttpException::badRequest('Signed bundles require the checksum from `@capgo/cli encrypt` (send it as ?checksum=…).');
            }
            $checksum = $providedChecksum;
        } else {
            // Plain bundle: must be a real .zip (PK\x03\x04, or PK\x05\x06 for an
            // empty archive). Checksum is the SHA256 we compute (or an override).
            if (!str_starts_with($bytes, "PK\x03\x04") && !str_starts_with($bytes, "PK\x05\x06")) {
                throw HttpException::badRequest('The uploaded file is not a .zip archive.');
            }
            $checksum = $providedChecksum !== '' ? $providedChecksum : hash('sha256', $bytes);
        }

        // Duplicate guard for every target (atomic, reject before storing
        // anything if any platform already has this version).
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
        }

        // Store the (identical) bytes + register one row per target platform.
        $bundles = [];
        foreach ($targets as $target) {
            $stored = $this->storage->store($bytes, $target, $version);
            $bundle = new OtaBundle(
                $appId,
                $target,
                $channel,
                $version,
                $stored['url'],
                $checksum,
                $minNative,
                $sessionKey,
            );
            $this->em->persist($bundle);
            $bundles[] = $bundle;
        }
        $this->em->flush();

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

    private function isSemver(string $value): bool
    {
        return (bool) preg_match('/^\d+(\.\d+){0,3}(-[0-9A-Za-z.-]+)?$/', $value);
    }
}
