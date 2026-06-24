<?php

declare(strict_types=1);

namespace Bayti\Api\Tests\Domain\User;

use Bayti\Api\Domain\User\OtpRateLimitConfig;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(OtpRateLimitConfig::class)]
final class OtpRateLimitConfigTest extends TestCase
{
    #[Test]
    public function appliesDocumentedDefaultsWhenEnvEmpty(): void
    {
        $c = OtpRateLimitConfig::fromEnv([]);

        self::assertSame(60, $c->resendCooldownSeconds);
        self::assertSame(5, $c->sendsPerHour);
        self::assertSame(10, $c->sendsPerDay);
        self::assertSame(20, $c->sendsPerIpHour);
        self::assertSame(40, $c->sendsPerIpDay);
        self::assertSame(5, $c->maxVerifyAttempts);
    }

    #[Test]
    public function readsOverridesFromEnv(): void
    {
        $c = OtpRateLimitConfig::fromEnv([
            'OTP_RESEND_COOLDOWN_SECONDS' => '30',
            'OTP_SENDS_PER_HOUR' => '7',
            'OTP_SENDS_PER_DAY' => '15',
            'OTP_SENDS_PER_IP_HOUR' => '25',
            'OTP_SENDS_PER_IP_DAY' => '50',
            'OTP_MAX_VERIFY_ATTEMPTS' => '3',
        ]);

        self::assertSame(30, $c->resendCooldownSeconds);
        self::assertSame(7, $c->sendsPerHour);
        self::assertSame(15, $c->sendsPerDay);
        self::assertSame(25, $c->sendsPerIpHour);
        self::assertSame(50, $c->sendsPerIpDay);
        self::assertSame(3, $c->maxVerifyAttempts);
    }

    #[Test]
    public function zeroIsHonouredAsDisable(): void
    {
        $c = OtpRateLimitConfig::fromEnv([
            'OTP_SENDS_PER_HOUR' => '0',
            'OTP_RESEND_COOLDOWN_SECONDS' => '0',
        ]);

        self::assertSame(0, $c->sendsPerHour);
        self::assertSame(0, $c->resendCooldownSeconds);
        // Untouched keys still get defaults.
        self::assertSame(10, $c->sendsPerDay);
    }

    #[Test]
    public function nonNumericFallsBackToDefault(): void
    {
        $c = OtpRateLimitConfig::fromEnv(['OTP_SENDS_PER_HOUR' => 'abc']);
        self::assertSame(5, $c->sendsPerHour);
    }

    #[Test]
    public function negativeIsClampedToZero(): void
    {
        $c = OtpRateLimitConfig::fromEnv(['OTP_SENDS_PER_HOUR' => '-3']);
        self::assertSame(0, $c->sendsPerHour);
    }
}
