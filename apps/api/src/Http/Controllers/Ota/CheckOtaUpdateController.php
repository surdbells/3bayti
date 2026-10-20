<?php

declare(strict_types=1);

namespace Bayti\Api\Http\Controllers\Ota;

use Bayti\Api\Domain\Ota\OtaBundle;
use Bayti\Api\Domain\Ota\OtaBundleRepository;
use Bayti\Api\Domain\Ota\OtaVersion;
use Bayti\Api\Http\Responder;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * POST /v3/ota/updates, the self-hosted update endpoint the
 * @capgo/capacitor-updater plugin polls on app resume / cold start.
 *
 * INTENTIONALLY UNAUTHENTICATED, the plugin runs before/around app auth and
 * carries no user JWT. It is safe: the endpoint only reads public release
 * metadata and returns a bundle URL + checksum. Integrity is enforced
 * downstream by the plugin (SHA256 verification, and signature verification
 * when bundles are signed). Everything fails safe to "no update", including
 * a missing/malformed version_build (the native-range gate can't be checked).
 *
 * Request body (AppInfos, sent by the plugin):
 *   platform, app_id, version_name (current bundle), version_build (native
 *   app version), version_code (native build number, unused), version_os,
 *   device_id, plugin_version, custom_id, is_prod, is_emulator, defaultChannel.
 *
 * version_build is the native MARKETING version: CFBundleShortVersionString
 * (MARKETING_VERSION) on iOS, PackageInfo.versionName on Android (plugin
 * 8.51.3, CapacitorUpdaterPlugin.swift / .java; no `version` override in
 * capacitor.config.ts). The build number (CFBundleVersion / versionCode) is
 * sent as version_code and ignored, so a TestFlight / Android test build is
 * only told apart from the production shell by a higher marketing version
 * (see {@see OtaBundle} for the native-range rule).
 *
 * version_name on the built-in web code (a fresh install, or right after a
 * store update resets to builtin) is ALSO that marketing version: the plugin's
 * builtin BundleInfo takes versionBuild as its version (CapgoUpdater.java
 * getBundleInfo, CapgoUpdater.swift). Since only a strictly newer version is
 * served, a bundle reaches a fresh install of a shell in its range only when
 * the bundle version is above that shell's marketing version (bundle 1.8.4
 * with range [1.8, 1.9] reaches fresh 1.8 installs but not fresh 1.9 ones).
 *
 * Response, update available:
 *   { "version": "1.0.7", "url": "https://…/1.0.7.zip",
 *     "checksum": "<sha256>"[, "session_key": "<iv>"][, "comment": "<notes>", "notes": "<notes>"] }
 * Response, no update:
 *   { "kind": "up_to_date", "error": "no_new_version_available",
 *     "message": "No update", "version": "", "url": "" }
 *
 * Plugin contract (@capgo/capacitor-updater 8.51.3, what every shipped shell
 * carries), which these shapes follow:
 *  - An update-available response must NOT carry `message`: getLatest()
 *    rejects any response with a non-empty `message` and no error/kind
 *    (CapacitorUpdaterPlugin.java getLatest, .swift getLatest). The release
 *    notes therefore travel as `comment`, the field iOS decodes (AppVersionDec)
 *    and passes through (and stores on the downloaded bundle); Android passes
 *    every key through. `notes` is kept for raw readers.
 *  - "No update" must carry kind=up_to_date: without a kind the background
 *    check treats it as a FAILED download ("Error no url or wrong format":
 *    a downloadFailed event + a download_fail stat on every resume), and
 *    getLatest() rejects with "No update". With it, both report up to date.
 */
final class CheckOtaUpdateController
{
    use Responder;

    /** Default appId when the plugin omits it (single-app deployment). */
    private const APP_ID = 'com.threebayti.app';

    /**
     * The plugin's AppInfos body is well under 2 KB; a bigger one is treated
     * as malformed and never processed here. Note this is defense in depth:
     * the app-wide BodyParsingMiddleware (Bootstrap) decodes JSON bodies
     * before this runs, so the real cap on this unauthenticated endpoint is
     * the web server's body limit (docker/default.conf keeps the 1m default
     * everywhere but the OTA upload route; production: OTA-DEPLOY.md → Upload
     * limits).
     */
    private const MAX_BODY_BYTES = 16 * 1024;

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
        $nativeBuild = trim($this->str($body, 'version_build'));
        $channel = $this->str($body, 'defaultChannel') ?: OtaBundle::DEFAULT_CHANNEL;

        // Unknown/invalid platform → nothing to serve. Fail safe.
        if (!in_array($platform, OtaBundle::ALL_PLATFORMS, true)) {
            return $this->noUpdate();
        }

        // Compatibility gate, FAIL CLOSED: without a well-formed native version
        // we can't prove the device's shell is inside any bundle's native range
        // (min_native_version .. effective max). Serving JS that needs a newer
        // native shell crashes the app, and serving a production bundle to a
        // newer test shell replaces that build's own web code. Every plugin
        // build we ship sends version_build, so this only drops
        // malformed/foreign callers.
        if (!OtaVersion::isComparable($nativeBuild)) {
            return $this->noUpdate();
        }

        /** @var OtaBundleRepository $repo */
        $repo = $this->em->getRepository(OtaBundle::class);
        $bundle = $this->pick($repo->activeFor($appId, $platform, $channel), $nativeBuild);
        if ($bundle === null) {
            return $this->noUpdate();
        }

        // Only serve when the bundle is strictly newer than the device's.
        if (OtaVersion::compare($bundle->getVersion(), $currentVersion) <= 0) {
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
        // "What's new" summary for the update sheet. Sent as `comment` (the
        // key both platforms pass through getLatest(); iOS also stores it on
        // the downloaded bundle) and `notes` (raw readers). NEVER as
        // `message`: the plugin's getLatest() rejects an update response that
        // carries one (see the class doc).
        $notes = $bundle->getNotes();
        if ($notes !== null && $notes !== '') {
            $payload['comment'] = $notes;
            $payload['notes'] = $notes;
        }

        return $this->ok($payload);
    }

    /**
     * The bundle to offer: the HIGHEST version among the active bundles whose
     * native range contains the device's native version, i.e.
     * min_native_version <= version_build <= effective max (max_native_version,
     * or min_native_version when the row has none; {@see OtaBundle::supportsNative()}).
     *
     * Highest version, not newest created_at, because:
     *  - devices only ever accept a strictly newer version, so the version is
     *    what decides what a device ends up on; created_at is just upload
     *    order, which a hotfix registered late via `ota:publish`, or an old
     *    row re-activated in the portal, would otherwise let shadow a newer
     *    release (devices past it would then get nothing at all);
     *  - a rollback is always published as the highest version and
     *    deactivates the bundles its native range overlaps, so it wins under
     *    either rule for the shells it covers;
     *  - deactivating a bundle still falls back to the next-highest active
     *    one for devices that hadn't downloaded it.
     * Filtering by native range BEFORE taking the max means a release that
     * needs a newer shell doesn't strand older shells (they still get the
     * highest bundle they can run), and a production release built for shell
     * 1.8 is never offered to a newer test shell (1.8.1, 1.9) above its max:
     * that shell gets only a bundle published for its own range, or nothing
     * and keeps its built-in web code.
     *
     * @param list<OtaBundle> $active
     */
    private function pick(array $active, string $nativeBuild): ?OtaBundle
    {
        $best = null;
        foreach ($active as $bundle) {
            if (!$bundle->supportsNative($nativeBuild)) {
                continue;
            }
            if ($best === null || OtaVersion::compare($bundle->getVersion(), $best->getVersion()) > 0) {
                $best = $bundle;
            }
        }

        return $best;
    }

    /**
     * The plugin's "up to date" contract: kind=up_to_date (+ the error code
     * Capgo's own backend uses), so neither the background check nor
     * getLatest() treats it as a failure. `message` is fine here because
     * kind/error are present.
     */
    private function noUpdate(): ResponseInterface
    {
        return $this->ok([
            'kind' => 'up_to_date',
            'error' => 'no_new_version_available',
            'message' => 'No update',
            'version' => '',
            'url' => '',
        ]);
    }

    /**
     * The request's AppInfos as an array. Uses the body BodyParsingMiddleware
     * already decoded (application/json), so it is never decoded twice; falls
     * back to decoding the raw body for any other Content-Type. A body over
     * MAX_BODY_BYTES (declared or actual) or malformed input yields an empty
     * array, so the caller degrades to "no update" rather than erroring.
     *
     * @return array<string, mixed>
     */
    private function readBody(ServerRequestInterface $request): array
    {
        $size = max(
            (int) $request->getHeaderLine('Content-Length'),
            (int) ($request->getBody()->getSize() ?? 0),
        );
        if ($size > self::MAX_BODY_BYTES) {
            return [];
        }

        $parsed = $request->getParsedBody();
        if (is_array($parsed)) {
            /** @var array<string, mixed> $parsed */
            return $parsed;
        }

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
}
