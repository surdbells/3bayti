<?php

declare(strict_types=1);

namespace Bayti\Api\Tests\Infrastructure\Otp;

use Bayti\Api\Infrastructure\Otp\OtpAlertConfig;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(OtpAlertConfig::class)]
final class OtpAlertConfigTest extends TestCase
{
    #[Test]
    public function otpAlertEmailsWinOverTheAdminList(): void
    {
        $config = OtpAlertConfig::fromEnv([
            'OTP_ALERT_EMAILS' => ' owner@3bayti.ae , ops@3bayti.ae,owner@3bayti.ae',
            'ADMIN_NOTIFICATION_EMAILS' => 'admin@3bayti.ae',
        ]);

        self::assertSame(['owner@3bayti.ae', 'ops@3bayti.ae'], $config->recipients);
        self::assertSame(OtpAlertConfig::SOURCE_OTP_ALERT_EMAILS, $config->recipientSource);
    }

    #[Test]
    public function fallsBackToAdminNotificationEmails(): void
    {
        $config = OtpAlertConfig::fromEnv(['ADMIN_NOTIFICATION_EMAILS' => 'admin@3bayti.ae,not-an-email']);

        self::assertSame(['admin@3bayti.ae'], $config->recipients);
        self::assertSame(OtpAlertConfig::SOURCE_ADMIN_NOTIFICATION_EMAILS, $config->recipientSource);
    }

    #[Test]
    public function anOtpAlertListWithNoValidAddressAlsoFallsBack(): void
    {
        $config = OtpAlertConfig::fromEnv(['OTP_ALERT_EMAILS' => 'oops', 'ADMIN_NOTIFICATION_EMAILS' => 'admin@3bayti.ae']);

        self::assertSame(['admin@3bayti.ae'], $config->recipients);
    }

    #[Test]
    public function nothingConfiguredMeansNoRecipients(): void
    {
        self::assertSame([], OtpAlertConfig::fromEnv([])->recipients);
    }

    #[Test]
    public function cooldownDefaultsToAnHourAndIsClampedToAMinute(): void
    {
        self::assertSame(3600, OtpAlertConfig::fromEnv([])->cooldownSeconds);
        self::assertSame(3600, OtpAlertConfig::fromEnv(['OTP_ALERT_COOLDOWN_SECONDS' => 'abc'])->cooldownSeconds);
        self::assertSame(3600, OtpAlertConfig::fromEnv(['OTP_ALERT_COOLDOWN_SECONDS' => '-5'])->cooldownSeconds);
        self::assertSame(60, OtpAlertConfig::fromEnv(['OTP_ALERT_COOLDOWN_SECONDS' => '0'])->cooldownSeconds);
        self::assertSame(1800, OtpAlertConfig::fromEnv(['OTP_ALERT_COOLDOWN_SECONDS' => ' 1800 '])->cooldownSeconds);
    }
}
