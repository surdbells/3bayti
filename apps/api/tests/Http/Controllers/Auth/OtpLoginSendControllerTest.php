<?php

declare(strict_types=1);

namespace Bayti\Api\Tests\Http\Controllers\Auth;

use Bayti\Api\Domain\User\OtpAttempt;
use Bayti\Api\Domain\User\OtpAttemptRepository;
use Bayti\Api\Domain\User\User;
use Bayti\Api\Domain\User\UserRepository;
use Bayti\Api\Http\Controllers\Auth\Dto\OtpLoginSendInput;
use Bayti\Api\Http\Controllers\Auth\OtpLoginSendController;
use Bayti\Api\Infrastructure\Otp\InMemoryOtpProvider;
use Bayti\Api\Infrastructure\Otp\OtpProvider;
use Bayti\Api\Tests\Http\HttpTestCase;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;

#[CoversClass(OtpLoginSendController::class)]
#[CoversClass(OtpLoginSendInput::class)]
final class OtpLoginSendControllerTest extends HttpTestCase
{
    private InMemoryOtpProvider $otpProvider;

    protected function setUp(): void
    {
        parent::setUp();
        $this->otpProvider = new InMemoryOtpProvider();
        $this->bind(OtpProvider::class, $this->otpProvider);
    }

    // -------------------------------------------------------------------
    // Phone channel
    // -------------------------------------------------------------------

    #[Test]
    public function phoneChannelSendsSmsForKnownActiveUser(): void
    {
        $user = $this->makeUser(active: true, phoneVerified: true);

        $userRepo = $this->createMock(UserRepository::class);
        $userRepo->method('findByPhone')->willReturn($user);

        $otpRepo = $this->createMock(OtpAttemptRepository::class);

        $em = $this->stubEm(fn ($em) =>
            $em->method('getRepository')->willReturnMap([
                [User::class, $userRepo],
                [OtpAttempt::class, $otpRepo],
            ]));
        $this->bind(EntityManagerInterface::class, $em);

        $response = $this->handle($this->jsonRequest('POST', '/v3/auth/otp-login/send', [
            'channel' => 'phone',
            'phone' => $user->getPhone(),
        ]));

        self::assertSame(200, $response->getStatusCode());
        $body = $this->jsonBody($response);
        self::assertNotEmpty($body['verification_id']);
        // Real SMS provider was hit.
        self::assertStringStartsWith('inmem-', $body['verification_id']);
        self::assertNotEmpty($this->otpProvider->allIssued());
    }

    #[Test]
    public function phoneChannelReturnsFakeVidForUnknownPhone(): void
    {
        $userRepo = $this->createMock(UserRepository::class);
        $userRepo->method('findByPhone')->willReturn(null);

        $em = $this->stubEm(fn ($em) =>
            $em->method('getRepository')->with(User::class)->willReturn($userRepo));
        $this->bind(EntityManagerInterface::class, $em);

        $response = $this->handle($this->jsonRequest('POST', '/v3/auth/otp-login/send', [
            'channel' => 'phone',
            'phone' => '+971500000000',
        ]));

        self::assertSame(200, $response->getStatusCode());
        self::assertStringStartsWith('fake-', $this->jsonBody($response)['verification_id']);
        // No real send for an unknown identifier.
        self::assertEmpty($this->otpProvider->allIssued());
    }

    #[Test]
    public function phoneChannelReturnsFakeVidForInactiveUser(): void
    {
        $user = $this->makeUser(active: false, phoneVerified: true);

        $userRepo = $this->createMock(UserRepository::class);
        $userRepo->method('findByPhone')->willReturn($user);

        $em = $this->stubEm(fn ($em) =>
            $em->method('getRepository')->with(User::class)->willReturn($userRepo));
        $this->bind(EntityManagerInterface::class, $em);

        $response = $this->handle($this->jsonRequest('POST', '/v3/auth/otp-login/send', [
            'channel' => 'phone',
            'phone' => $user->getPhone(),
        ]));

        self::assertSame(200, $response->getStatusCode());
        self::assertStringStartsWith('fake-', $this->jsonBody($response)['verification_id']);
        self::assertEmpty($this->otpProvider->allIssued());
    }

    #[Test]
    public function phoneChannelRequiresPhone(): void
    {
        $response = $this->handle($this->jsonRequest('POST', '/v3/auth/otp-login/send', [
            'channel' => 'phone',
        ]));
        self::assertSame(422, $response->getStatusCode());
    }

    // -------------------------------------------------------------------
    // Email channel
    // -------------------------------------------------------------------

    #[Test]
    public function emailChannelSendsEmailOtpForKnownActiveUser(): void
    {
        $user = $this->makeUser(active: true, phoneVerified: true);

        $userRepo = $this->createMock(UserRepository::class);
        $userRepo->method('findByEmail')->willReturn($user);

        $persistedOtps = [];
        $otpRepo = $this->createMock(OtpAttemptRepository::class);
        $otpRepo->method('save')->willReturnCallback(function (OtpAttempt $a) use (&$persistedOtps) {
            $persistedOtps[] = $a;
        });

        $em = $this->stubEm(fn ($em) =>
            $em->method('getRepository')->willReturnMap([
                [User::class, $userRepo],
                [OtpAttempt::class, $otpRepo],
            ]));
        $this->bind(EntityManagerInterface::class, $em);

        $response = $this->handle($this->jsonRequest('POST', '/v3/auth/otp-login/send', [
            'channel' => 'email',
            'email' => $user->getEmail(),
        ]));

        self::assertSame(200, $response->getStatusCode());
        $body = $this->jsonBody($response);
        // Email-OTP verification ids carry the 'em-' prefix.
        self::assertStringStartsWith('em-', $body['verification_id']);
        // SMS provider was NOT hit (email is local).
        self::assertEmpty($this->otpProvider->allIssued());
        // A login-purpose, email-channel OTP row was persisted.
        self::assertCount(1, $persistedOtps);
        self::assertTrue($persistedOtps[0]->isEmailChannel());
        self::assertSame(OtpAttempt::PURPOSE_LOGIN_2FA, $persistedOtps[0]->getPurpose());
    }

    #[Test]
    public function emailChannelReturnsFakeVidForUnknownEmail(): void
    {
        $userRepo = $this->createMock(UserRepository::class);
        $userRepo->method('findByEmail')->willReturn(null);

        $em = $this->stubEm(fn ($em) =>
            $em->method('getRepository')->with(User::class)->willReturn($userRepo));
        $this->bind(EntityManagerInterface::class, $em);

        $response = $this->handle($this->jsonRequest('POST', '/v3/auth/otp-login/send', [
            'channel' => 'email',
            'email' => 'unknown@example.com',
        ]));

        self::assertSame(200, $response->getStatusCode());
        self::assertStringStartsWith('fake-', $this->jsonBody($response)['verification_id']);
    }

    #[Test]
    public function emailChannelRequiresEmail(): void
    {
        $response = $this->handle($this->jsonRequest('POST', '/v3/auth/otp-login/send', [
            'channel' => 'email',
        ]));
        self::assertSame(422, $response->getStatusCode());
    }

    #[Test]
    public function rejectsUnknownChannel(): void
    {
        $response = $this->handle($this->jsonRequest('POST', '/v3/auth/otp-login/send', [
            'channel' => 'carrier-pigeon',
            'phone' => '+971501234567',
        ]));
        self::assertSame(422, $response->getStatusCode());
    }
}
