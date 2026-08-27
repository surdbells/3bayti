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
 * The update endpoint (POST /v3/ota/updates) serves the newest ACTIVE bundle
 * for a given {app_id, platform, channel} whose {@see $minNativeVersion} is
 * compatible with the device's native build, and only when its {@see $version}
 * is newer than the device's current bundle.
 *
 * OTA ships JS/CSS only, never native code. A change needing a new native
 * capability must go through the store with a native version bump; the
 * `min_native_version` gate keeps such a bundle off older shells.
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

    /** Public HTTPS URL of the bundle .zip (the CDN object). */
    #[ORM\Column(type: 'string', length: 1000)]
    private string $url;

    /**
     * Integrity checksum the plugin verifies the download against. For a PLAIN
     * bundle this is the SHA256 of the .zip (64 hex); for a SIGNED bundle it's
     * the RSA-encrypted checksum from `@capgo/cli bundle encrypt` (512 hex for
     * RSA-2048, more for larger keys), hence the generous length.
     */
    #[ORM\Column(type: 'string', length: 2048)]
    private string $checksum;

    /** Lowest native app build this bundle is compatible with. */
    #[ORM\Column(name: 'min_native_version', type: 'string', length: 64)]
    private string $minNativeVersion;

    /**
     * The ivSessionKey from `@capgo/cli bundle encrypt`, non-null only for
     * signed/encrypted bundles. It's an RSA-wrapped AES key (~370 chars for
     * RSA-2048, more for larger keys), so it needs room well beyond 255.
     */
    #[ORM\Column(name: 'session_key', type: 'string', length: 2048, nullable: true)]
    private ?string $sessionKey = null;

    #[ORM\Column(name: 'is_active', type: 'boolean')]
    private bool $isActive = true;

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
    ) {
        if (!in_array($platform, self::ALL_PLATFORMS, true)) {
            throw new \InvalidArgumentException("Unknown OTA platform: {$platform}");
        }
        $this->appId = $appId;
        $this->platform = $platform;
        $this->channel = $channel !== '' ? $channel : self::DEFAULT_CHANNEL;
        $this->version = $version;
        $this->url = $url;
        $this->checksum = $checksum;
        $this->minNativeVersion = $minNativeVersion !== '' ? $minNativeVersion : '0.0.0';
        $this->sessionKey = $sessionKey;
        $this->isActive = true;
        $this->createdAt = new DateTimeImmutable('now', new DateTimeZone('UTC'));
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

    public function getSessionKey(): ?string
    {
        return $this->sessionKey;
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

    public function getCreatedAt(): DateTimeImmutable
    {
        return $this->createdAt;
    }
}
