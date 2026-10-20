<?php

declare(strict_types=1);

namespace Bayti\Api\Domain\Ota;

use DateTimeImmutable;
use DateTimeZone;
use Doctrine\ORM\Mapping as ORM;

/**
 * A published OTA (over-the-air) web bundle, one zipped Angular `www` build
 * that the @capgo/capacitor-updater plugin can download and apply on top of an
 * installed native shell, without an app-store release.
 *
 * The update endpoint (POST /v3/ota/updates) serves the highest-version ACTIVE
 * bundle for a given {app_id, platform, channel} whose NATIVE RANGE contains
 * the device's native app version, and only when its {@see $version} is newer
 * than the device's current bundle.
 *
 * Native range: a bundle runs on shells with
 *   min_native_version <= device version_build <= effective max
 * where the effective max is {@see $maxNativeVersion}, or min_native_version
 * when that is null (rows published before the column existed). Both ends are
 * inclusive and compared with {@see OtaVersion::compare()}, which pads missing
 * segments with 0 ("1.8" == "1.8.0", while "1.8.1" is above a max of "1.8").
 *
 *  - min keeps a bundle that needs a new native capability off OLDER shells
 *    (OTA ships JS/CSS only; native changes go through the store);
 *  - max keeps a bundle built for the production shell off NEWER shells, so a
 *    production release never replaces the newer web code inside a TestFlight
 *    or Android test build (e.g. production shell 1.8, test shell 1.8.1).
 *
 * version_build is the MARKETING version, not the build number: the installed
 * @capgo/capacitor-updater (8.51.3) sets it from CFBundleShortVersionString
 * (Xcode MARKETING_VERSION) on iOS (CapacitorUpdaterPlugin.swift, via
 * Bundle.main.versionName in InternalUtils.swift) and from
 * PackageInfo.versionName on Android (CapacitorUpdaterPlugin.java), unless the
 * CapacitorUpdater `version` config overrides it (capacitor.config.ts does
 * not). CFBundleVersion / versionCode travel separately as `version_code` and
 * are NOT used here. A test build is therefore only kept apart from production
 * when its MARKETING version is higher (1.8.1), never by a higher build number
 * alone (1.8 build 6 reports "1.8", the same as the production shell).
 */
#[ORM\Entity(repositoryClass: OtaBundleRepository::class)]
#[ORM\Table(name: 'ota_bundles')]
#[ORM\Index(columns: ['app_id', 'platform', 'channel', 'is_active', 'created_at'], name: 'idx_ota_bundles_lookup')]
#[ORM\UniqueConstraint(name: 'uniq_ota_bundle_version', columns: ['app_id', 'platform', 'channel', 'version'])]
class OtaBundle
{
    public const PLATFORM_ANDROID = 'android';
    public const PLATFORM_IOS = 'ios';

    public const ALL_PLATFORMS = [
        self::PLATFORM_ANDROID,
        self::PLATFORM_IOS,
    ];

    public const DEFAULT_CHANNEL = 'production';

    #[ORM\Id]
    #[ORM\GeneratedValue(strategy: 'IDENTITY')]
    #[ORM\Column(type: 'bigint')]
    // @phpstan-ignore-next-line property.unusedType
    private ?int $id = null;

    #[ORM\Column(name: 'app_id', type: 'string', length: 255)]
    private string $appId;

    #[ORM\Column(type: 'string', length: 16)]
    private string $platform;

    #[ORM\Column(type: 'string', length: 64)]
    private string $channel;

    /** Bundle semver, e.g. "1.0.7". */
    #[ORM\Column(type: 'string', length: 64)]
    private string $version;

    /** Public HTTPS URL of the bundle .zip (normally our own /uploads/ota/… file). */
    #[ORM\Column(type: 'string', length: 1000)]
    private string $url;

    /**
     * Integrity checksum the plugin verifies the download against. For a PLAIN
     * bundle this is the SHA256 of the .zip (64 hex); for a SIGNED bundle it's
     * the RSA-encrypted checksum from `@capgo/cli bundle encrypt` (exactly 512
     * hex: the plugin only accepts RSA-2048, see OtaChecksum). The column is
     * generous so a future key change needs no migration.
     */
    #[ORM\Column(type: 'string', length: 2048)]
    private string $checksum;

    /** Lowest native app (marketing) version this bundle runs on, inclusive. */
    #[ORM\Column(name: 'min_native_version', type: 'string', length: 64)]
    private string $minNativeVersion;

    /**
     * Highest native app (marketing) version this bundle runs on, inclusive.
     * Every write path stores it explicitly (defaulting to min_native_version);
     * it is null only on rows published before the column existed, which
     * {@see getEffectiveMaxNativeVersion()} treats as max = min.
     */
    #[ORM\Column(name: 'max_native_version', type: 'string', length: 64, nullable: true)]
    private ?string $maxNativeVersion = null;

    /**
     * The ivSessionKey from `@capgo/cli bundle encrypt`, non-null only for
     * signed/encrypted bundles. It's an RSA-wrapped AES key (~370 chars for
     * RSA-2048, more for larger keys), so it needs room well beyond 255.
     */
    #[ORM\Column(name: 'session_key', type: 'string', length: 2048, nullable: true)]
    private ?string $sessionKey = null;

    #[ORM\Column(name: 'is_active', type: 'boolean')]
    private bool $isActive = true;

    /**
     * Human-readable "what's new" notes for this release, shown to the
     * customer after the update applies (via the OTA update-check response).
     * Null when the operator didn't provide any.
     */
    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $notes = null;

    /**
     * Set only on a row created by the one-click rollback: the version of the
     * bundle whose file this row re-publishes (e.g. "1.6.3"). Shown in the
     * portal so operators can tell a rollback from a fresh release.
     */
    #[ORM\Column(name: 'rollback_of_version', type: 'string', length: 64, nullable: true)]
    private ?string $rollbackOfVersion = null;

    #[ORM\Column(name: 'created_at', type: 'datetimetz_immutable')]
    private DateTimeImmutable $createdAt;

    public function __construct(
        string $appId,
        string $platform,
        string $channel,
        string $version,
        string $url,
        string $checksum,
        string $minNativeVersion,
        ?string $sessionKey = null,
        ?string $notes = null,
        ?string $maxNativeVersion = null,
    ) {
        if (!in_array($platform, self::ALL_PLATFORMS, true)) {
            throw new \InvalidArgumentException("Unknown OTA platform: {$platform}");
        }
        $minNativeVersion = $minNativeVersion !== '' ? $minNativeVersion : '0.0.0';
        // Max defaults to min (the bundle runs on exactly the shell it was
        // built for) and is always stored explicitly on a new row.
        $maxNativeVersion = ($maxNativeVersion === null || $maxNativeVersion === '') ? $minNativeVersion : $maxNativeVersion;
        if (OtaVersion::compare($maxNativeVersion, $minNativeVersion) < 0) {
            throw new \InvalidArgumentException(
                "OTA max native version {$maxNativeVersion} is below min native version {$minNativeVersion}",
            );
        }
        $this->appId = $appId;
        $this->platform = $platform;
        $this->channel = $channel !== '' ? $channel : self::DEFAULT_CHANNEL;
        $this->version = $version;
        $this->url = $url;
        $this->checksum = $checksum;
        $this->minNativeVersion = $minNativeVersion;
        $this->maxNativeVersion = $maxNativeVersion;
        $this->sessionKey = $sessionKey;
        $this->notes = ($notes === null || trim($notes) === '') ? null : trim($notes);
        $this->isActive = true;
        $this->createdAt = new DateTimeImmutable('now', new DateTimeZone('UTC'));
    }

    /**
     * A new row that re-publishes $source's file (same url, checksum, session
     * key and native range) under $version, so devices that already moved past
     * $source download it again. The range is copied as $source's EFFECTIVE
     * one (a legacy row with no max gets max = min stored explicitly), so the
     * rollback runs on exactly the shells the source did. It carries no
     * "what's new" notes: customers rolled back should not see an old
     * release's summary a second time.
     */
    public static function rollbackOf(OtaBundle $source, string $version): self
    {
        $bundle = new self(
            $source->getAppId(),
            $source->getPlatform(),
            $source->getChannel(),
            $version,
            $source->getUrl(),
            $source->getChecksum(),
            $source->getMinNativeVersion(),
            $source->getSessionKey(),
            null,
            $source->getEffectiveMaxNativeVersion(),
        );
        $bundle->rollbackOfVersion = $source->getRollbackOfVersion() ?? $source->getVersion();

        return $bundle;
    }

    public function getId(): ?int
    {
        return $this->id !== null ? (int) $this->id : null;
    }

    public function getAppId(): string
    {
        return $this->appId;
    }

    public function getPlatform(): string
    {
        return $this->platform;
    }

    public function getChannel(): string
    {
        return $this->channel;
    }

    public function getVersion(): string
    {
        return $this->version;
    }

    public function getUrl(): string
    {
        return $this->url;
    }

    public function getChecksum(): string
    {
        return $this->checksum;
    }

    public function getMinNativeVersion(): string
    {
        return $this->minNativeVersion;
    }

    /**
     * The stored max native version: null only on rows published before the
     * column existed. Use {@see getEffectiveMaxNativeVersion()} for any
     * compatibility decision.
     */
    public function getMaxNativeVersion(): ?string
    {
        return $this->maxNativeVersion;
    }

    /** The upper end of the native range: max_native_version, or min when unset. */
    public function getEffectiveMaxNativeVersion(): string
    {
        return $this->maxNativeVersion ?? $this->minNativeVersion;
    }

    /**
     * Whether a device whose native app version is $nativeBuild can run this
     * bundle: min <= $nativeBuild <= effective max, both ends inclusive.
     * Callers check {@see OtaVersion::isComparable()} on a device-reported
     * value first (the update endpoint fails closed on anything else).
     */
    public function supportsNative(string $nativeBuild): bool
    {
        return OtaVersion::compare($nativeBuild, $this->minNativeVersion) >= 0
            && OtaVersion::compare($nativeBuild, $this->getEffectiveMaxNativeVersion()) <= 0;
    }

    /**
     * Whether no installed app can ever be offered this bundle: its effective
     * native range ends at 0 (e.g. a legacy row published with the old
     * "0.0.0" min default and no max, so [0.0.0, 0.0.0]). New uploads with
     * such a range are refused ({@see OtaVersion::nativeRangeError()}); the
     * rollback and re-activation paths refuse such a row too, so an operator
     * is told instead of getting an active bundle that serves nobody.
     */
    public function reachesNoShell(): bool
    {
        return OtaVersion::isZero($this->getEffectiveMaxNativeVersion());
    }

    /**
     * Whether this bundle's effective native range and $other's share at least
     * one native version (both ranges inclusive): the bundles compete for
     * those shells. A rollback deactivates exactly the active bundles that
     * overlap its range and leaves bundles for other shells alone
     * ({@see OtaRollbackService}).
     */
    public function nativeRangeOverlaps(OtaBundle $other): bool
    {
        return OtaVersion::compare($this->minNativeVersion, $other->getEffectiveMaxNativeVersion()) <= 0
            && OtaVersion::compare($other->getMinNativeVersion(), $this->getEffectiveMaxNativeVersion()) <= 0;
    }

    /** The effective native range for messages ({@see OtaVersion::rangeLabel()}). */
    public function nativeRangeLabel(): string
    {
        return OtaVersion::rangeLabel($this->minNativeVersion, $this->getEffectiveMaxNativeVersion());
    }

    public function getSessionKey(): ?string
    {
        return $this->sessionKey;
    }

    public function getNotes(): ?string
    {
        return $this->notes;
    }

    public function isActive(): bool
    {
        return $this->isActive;
    }

    public function deactivate(): void
    {
        $this->isActive = false;
    }

    public function setActive(bool $active): void
    {
        $this->isActive = $active;
    }

    public function getRollbackOfVersion(): ?string
    {
        return $this->rollbackOfVersion;
    }

    /**
     * Whether this (active) bundle would ALWAYS be served instead of a bundle
     * with $version and native range [$minNative, $maxNative] on the same
     * app/platform/channel, i.e. the other bundle could never reach a device.
     * The update endpoint serves the highest version among the active bundles
     * a device's shell can run, so that is the case when this one is at least
     * as high AND its native range [min, effective max] fully covers the other
     * one's. A lower version for shells outside this range is NOT shadowed:
     * an older-shell hotfix below min, or a test-shell bundle above max, still
     * reaches those shells.
     */
    public function shadows(string $version, string $minNative, string $maxNative): bool
    {
        return $this->isActive
            && OtaVersion::compare($this->version, $version) >= 0
            && OtaVersion::compare($this->minNativeVersion, $minNative) <= 0
            && OtaVersion::compare($this->getEffectiveMaxNativeVersion(), $maxNative) >= 0;
    }

    public function getCreatedAt(): DateTimeImmutable
    {
        return $this->createdAt;
    }
}
