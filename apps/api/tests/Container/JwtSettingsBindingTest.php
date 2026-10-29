<?php

declare(strict_types=1);

namespace Bayti\Api\Tests\Container;

use Bayti\Api\Infrastructure\Auth\JwtSettings;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * The refresh-token lifetime is 5 years and config/di.php enforces it as a
 * FLOOR: older runbooks seeded JWT_REFRESH_TOKEN_TTL=604800 (7 days), which
 * silently signed out anyone idle for a week. Runs the real di.php factory.
 */
#[CoversClass(JwtSettings::class)]
final class JwtSettingsBindingTest extends TestCase
{
    private const FIVE_YEARS = 157680000;

    private ?string $ttlBackup = null;

    protected function setUp(): void
    {
        parent::setUp();
        $this->ttlBackup = $_ENV['JWT_REFRESH_TOKEN_TTL'] ?? null;
    }

    protected function tearDown(): void
    {
        if ($this->ttlBackup === null) {
            unset($_ENV['JWT_REFRESH_TOKEN_TTL']);
        } else {
            $_ENV['JWT_REFRESH_TOKEN_TTL'] = $this->ttlBackup;
        }
        parent::tearDown();
    }

    #[Test]
    public function defaultsToFiveYears(): void
    {
        unset($_ENV['JWT_REFRESH_TOKEN_TTL']);

        self::assertSame(self::FIVE_YEARS, $this->resolve()->refreshTtlSeconds);
    }

    #[Test]
    public function aShorterEnvValueIsRaisedToTheFloor(): void
    {
        $_ENV['JWT_REFRESH_TOKEN_TTL'] = '604800'; // the old runbook value

        self::assertSame(self::FIVE_YEARS, $this->resolve()->refreshTtlSeconds);
    }

    #[Test]
    public function aLongerEnvValueIsHonoured(): void
    {
        $_ENV['JWT_REFRESH_TOKEN_TTL'] = '315360000'; // 10 years

        self::assertSame(315360000, $this->resolve()->refreshTtlSeconds);
    }

    #[Test]
    public function constructorDefaultIsFiveYears(): void
    {
        self::assertSame(self::FIVE_YEARS, JwtSettings::REFRESH_TTL_SECONDS);
        self::assertSame(
            self::FIVE_YEARS,
            (new JwtSettings(signingSecret: str_repeat('k', 32)))->refreshTtlSeconds,
        );
    }

    private function resolve(): JwtSettings
    {
        /** @var array<string, mixed> $definitions */
        $definitions = require __DIR__ . '/../../config/di.php';
        $factory = $definitions[JwtSettings::class];
        self::assertIsCallable($factory);
        $settings = $factory();
        self::assertInstanceOf(JwtSettings::class, $settings);
        return $settings;
    }
}
