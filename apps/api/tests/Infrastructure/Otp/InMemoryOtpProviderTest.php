<?php

declare(strict_types=1);

namespace Bayti\Api\Tests\Infrastructure\Otp;

use Bayti\Api\Infrastructure\Otp\InMemoryOtpProvider;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(InMemoryOtpProvider::class)]
final class InMemoryOtpProviderTest extends TestCase
{
    #[Test]
    public function sendReturnsAUniqueVerificationId(): void
    {
        $provider = new InMemoryOtpProvider();

        $a = $provider->send('+971501111111');
        $b = $provider->send('+971502222222');

        self::assertNotSame($a, $b);
        self::assertNotEmpty($a);
        self::assertNotEmpty($b);
    }

    #[Test]
    public function sendTracksPhoneByVerificationId(): void
    {
        $provider = new InMemoryOtpProvider();
        $provider->send('+971501111111');
        $provider->send('+971502222222');

        $vid = $provider->latestVerificationFor('+971502222222');
        self::assertNotNull($vid);
        self::assertContains($vid, $provider->allIssued());
    }

    #[Test]
    public function verifyAcceptsDefaultCode(): void
    {
        $provider = new InMemoryOtpProvider(); // default = '000000'
        $vid = $provider->send('+971501111111');

        self::assertTrue($provider->verify($vid, '000000'));
    }

    #[Test]
    public function verifyRejectsWrongCode(): void
    {
        $provider = new InMemoryOtpProvider();
        $vid = $provider->send('+971501111111');

        self::assertFalse($provider->verify($vid, '111111'));
    }

    #[Test]
    public function verifyAcceptsExpectedCodeWhenSet(): void
    {
        $provider = new InMemoryOtpProvider();
        $vid = $provider->send('+971501111111');
        $provider->setExpectedCode($vid, '654321');

        self::assertFalse($provider->verify($vid, '000000'));
        self::assertTrue($provider->verify($vid, '654321'));
    }

    #[Test]
    public function customDefaultCodeWorks(): void
    {
        $provider = new InMemoryOtpProvider(defaultAcceptCode: '999999');
        $vid = $provider->send('+971501111111');

        self::assertFalse($provider->verify($vid, '000000'));
        self::assertTrue($provider->verify($vid, '999999'));
    }

    #[Test]
    public function resetClearsState(): void
    {
        $provider = new InMemoryOtpProvider();
        $provider->send('+971501111111');
        $provider->send('+971502222222');

        self::assertCount(2, $provider->allIssued());
        $provider->reset();
        self::assertCount(0, $provider->allIssued());
        self::assertNull($provider->latestVerificationFor('+971501111111'));
    }
}
