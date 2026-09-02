<?php

declare(strict_types=1);

namespace Bayti\Api\Tests\Infrastructure\Cache;

use Bayti\Api\Infrastructure\Cache\MappingCacheVersion;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * The per-deploy cache version tag that namespaces Doctrine's metadata/query
 * cache so an entity-mapping change can never be read against stale metadata
 * (incident PHP-24 / PHP-26).
 */
#[CoversClass(MappingCacheVersion::class)]
final class MappingCacheVersionTest extends TestCase
{
    /** @var array<string, string|false> */
    private array $savedGetenv = [];
    /** @var array<string, mixed> */
    private array $savedServerEnv = [];
    private const RELEASE_KEYS = ['APP_RELEASE', 'RELEASE_ID', 'GIT_SHA', 'GIT_COMMIT'];

    private string $tmpRoot = '';

    protected function setUp(): void
    {
        // Neutralise any release env the CI runner might set (GIT_SHA etc.),
        // so the fingerprint-fallback tests are deterministic. Restored after.
        foreach (self::RELEASE_KEYS as $key) {
            $this->savedGetenv[$key] = getenv($key);
            $this->savedServerEnv[$key] = $_ENV[$key] ?? null;
            putenv($key);
            unset($_ENV[$key]);
        }

        $this->tmpRoot = sys_get_temp_dir() . '/mcv-' . bin2hex(random_bytes(6));
        mkdir($this->tmpRoot . '/src/Domain', 0o777, true);
    }

    protected function tearDown(): void
    {
        foreach (self::RELEASE_KEYS as $key) {
            $saved = $this->savedGetenv[$key] ?? false;
            if ($saved === false) {
                putenv($key);
            } else {
                putenv("{$key}={$saved}");
            }
            if (($this->savedServerEnv[$key] ?? null) === null) {
                unset($_ENV[$key]);
            } else {
                $_ENV[$key] = $this->savedServerEnv[$key];
            }
        }

        if ($this->tmpRoot !== '' && is_dir($this->tmpRoot)) {
            $this->rrmdir($this->tmpRoot);
        }
    }

    #[Test]
    public function explicitReleaseEnvWinsOverTheFingerprint(): void
    {
        $_ENV['APP_RELEASE'] = 'v1.2.3-abc';
        // Sanitised to filesystem-safe chars.
        self::assertSame('v123abc', MappingCacheVersion::compute($this->tmpRoot));
    }

    #[Test]
    public function releaseEnvIsCheckedInPriorityOrder(): void
    {
        // GIT_SHA is lower priority than APP_RELEASE.
        $_ENV['GIT_SHA'] = 'deadbeef';
        self::assertSame('deadbeef', MappingCacheVersion::compute($this->tmpRoot));

        $_ENV['APP_RELEASE'] = 'release99';
        self::assertSame('release99', MappingCacheVersion::compute($this->tmpRoot));
    }

    #[Test]
    public function longReleaseIdIsTruncatedToThirtyTwoChars(): void
    {
        $_ENV['APP_RELEASE'] = str_repeat('a', 100);
        self::assertSame(str_repeat('a', 32), MappingCacheVersion::compute($this->tmpRoot));
    }

    #[Test]
    public function fingerprintIsA16CharHexHashWhenNoReleaseEnv(): void
    {
        file_put_contents($this->tmpRoot . '/src/Domain/Order.php', '<?php class Order {}');
        $tag = MappingCacheVersion::compute($this->tmpRoot);
        self::assertMatchesRegularExpression('/^[0-9a-f]{16}$/', $tag);
    }

    #[Test]
    public function fingerprintIsStableWhenNothingChanges(): void
    {
        file_put_contents($this->tmpRoot . '/src/Domain/Order.php', '<?php class Order {}');
        $first = MappingCacheVersion::compute($this->tmpRoot);
        $second = MappingCacheVersion::compute($this->tmpRoot);
        self::assertSame($first, $second);
    }

    #[Test]
    public function fingerprintChangesWhenAMappingFileChanges(): void
    {
        $file = $this->tmpRoot . '/src/Domain/Order.php';
        file_put_contents($file, '<?php class Order {}');
        $before = MappingCacheVersion::compute($this->tmpRoot);

        // Simulate a deploy adding a mapped field: content (size) changes.
        file_put_contents($file, '<?php class Order { public $deletedAt; }');
        $after = MappingCacheVersion::compute($this->tmpRoot);

        self::assertNotSame($before, $after, 'a changed entity must rotate the cache namespace');
    }

    #[Test]
    public function fingerprintChangesWhenAnEntityIsAdded(): void
    {
        file_put_contents($this->tmpRoot . '/src/Domain/Order.php', '<?php class Order {}');
        $before = MappingCacheVersion::compute($this->tmpRoot);

        file_put_contents($this->tmpRoot . '/src/Domain/Coupon.php', '<?php class Coupon {}');
        $after = MappingCacheVersion::compute($this->tmpRoot);

        self::assertNotSame($before, $after);
    }

    #[Test]
    public function missingDomainDirectoryYieldsAStableConstant(): void
    {
        $this->rrmdir($this->tmpRoot . '/src');
        self::assertSame('base', MappingCacheVersion::compute($this->tmpRoot));
    }

    private function rrmdir(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        foreach (scandir($dir) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $path = $dir . DIRECTORY_SEPARATOR . $entry;
            is_dir($path) && !is_link($path) ? $this->rrmdir($path) : @unlink($path);
        }
        @rmdir($dir);
    }
}
