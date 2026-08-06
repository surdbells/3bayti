<?php

declare(strict_types=1);

namespace Bayti\Api\Http\Controllers\Ota;

use Bayti\Api\Domain\Ota\OtaBundle;
use Bayti\Api\Domain\Ota\OtaBundleRepository;
use Bayti\Api\Http\Responder;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * POST /v3/ota/updates — the self-hosted update endpoint the
 * @capgo/capacitor-updater plugin polls on app resume / cold start.
 *
 * INTENTIONALLY UNAUTHENTICATED — the plugin runs before/around app auth and
 * carries no user JWT. It is safe: the endpoint only reads public release
 * metadata and returns a bundle URL + checksum. Integrity is enforced
 * downstream by the plugin (SHA256 verification, and signature verification
 * when bundles are signed). Everything fails safe to "no update".
 *
 * Request body (AppInfos, sent by the plugin):
 *   platform, app_id, version_name (current bundle), version_build (native
 *   app build), version_os, device_id, plugin_version, custom_id, is_prod,
 *   is_emulator, defaultChannel.
 *
 * Response — update available:
 *   { "version": "1.0.7", "url": "https://…/1.0.7.zip",
 *     "checksum": "<sha256>"[, "session_key": "<iv>"] }
 * Response — no update:
 *   { "message": "No update", "version": "", "url": "" }
 */
final class CheckOtaUpdateController
{
    use Responder;

    /** Default appId when the plugin omits it (single-app deployment). */
    private const APP_ID = 'com.threebayti.app';

    public function __construct(
        protected readonly ResponseFactoryInterface $responseFactory,
        private readonly EntityManagerInterface $em,
    ) {
    }

    protected function getResponseFactory(): ResponseFactoryInterface
    {
        return $this->responseFactory;
    }

    public function __invoke(ServerRequestInterface $request): ResponseInterface
    {
        $body = $this->readBody($request);

        $platform = $this->str($body, 'platform');
        $appId = $this->str($body, 'app_id') ?: self::APP_ID;
        $currentVersion = $this->str($body, 'version_name');
        $nativeBuild = $this->str($body, 'version_build');
        $channel = $this->str($body, 'defaultChannel') ?: OtaBundle::DEFAULT_CHANNEL;

        // Unknown/invalid platform → nothing to serve. Fail safe.
        if (!in_array($platform, OtaBundle::ALL_PLATFORMS, true)) {
            return $this->noUpdate();
        }

        /** @var OtaBundleRepository $repo */
        $repo = $this->em->getRepository(OtaBundle::class);
        $bundle = $repo->latestActive($appId, $platform, $channel);
        if ($bundle === null) {
            return $this->noUpdate();
        }

        // Compatibility gate: never send a bundle that needs a newer native
        // shell than the device is running (OTA ships JS only).
        if ($nativeBuild !== '' && self::compareSemver($nativeBuild, $bundle->getMinNativeVersion()) < 0) {
            return $this->noUpdate();
        }

        // Only serve when the bundle is strictly newer than the device's.
        if (self::compareSemver($bundle->getVersion(), $currentVersion) <= 0) {
            return $this->noUpdate();
        }

        $payload = [
            'version' => $bundle->getVersion(),
            'url' => $bundle->getUrl(),
            'checksum' => $bundle->getChecksum(),
        ];
        $sessionKey = $bundle->getSessionKey();
        if ($sessionKey !== null && $sessionKey !== '') {
            $payload['session_key'] = $sessionKey;
        }

        return $this->ok($payload);
    }

    private function noUpdate(): ResponseInterface
    {
        return $this->ok([
            'message' => 'No update',
            'version' => '',
            'url' => '',
        ]);
    }

    /**
     * Read + JSON-decode the request body directly (no body-parsing middleware
     * is registered app-wide). Any malformed input yields an empty array so the
     * caller degrades to "no update" rather than erroring.
     *
     * @return array<string, mixed>
     */
    private function readBody(ServerRequestInterface $request): array
    {
        $decoded = json_decode((string) $request->getBody(), true);
        return is_array($decoded) ? $decoded : [];
    }

    /**
     * @param array<string, mixed> $body
     */
    private function str(array $body, string $key): string
    {
        $value = $body[$key] ?? null;
        return is_string($value) ? $value : '';
    }

    /**
     * Numeric semver comparison. Negative if a<b, positive if a>b, 0 if equal.
     * Pre-release suffixes are ignored; missing/non-numeric segments count as 0.
     */
    private static function compareSemver(string $a, string $b): int
    {
        $pa = array_map('intval', explode('.', explode('-', $a)[0]));
        $pb = array_map('intval', explode('.', explode('-', $b)[0]));
        $len = max(count($pa), count($pb));
        for ($i = 0; $i < $len; $i++) {
            $va = $pa[$i] ?? 0;
            $vb = $pb[$i] ?? 0;
            if ($va !== $vb) {
                return $va <=> $vb;
            }
        }
        return 0;
    }
}
