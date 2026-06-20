<?php

declare(strict_types=1);

namespace Bayti\Api\Tests\Http\Controllers\Auth;

use Bayti\Api\Domain\User\OtpAttempt;
use Bayti\Api\Domain\User\OtpAttemptRepository;
use Bayti\Api\Domain\User\User;
use Bayti\Api\Domain\User\UserRepository;
use Bayti\Api\Http\Controllers\Auth\Dto\ResetInput;
use Bayti\Api\Http\Controllers\Auth\ResetController;
use Bayti\Api\Infrastructure\Otp\InMemoryOtpProvider;
use Bayti\Api\Infrastructure\Otp\OtpProvider;
use Bayti\Api\Tests\Http\HttpTestCase;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;

#[CoversClass(ResetController::class)]
#[CoversClass(ResetInput::class)]
final class ResetControllerTest extends HttpTestCase
{
    private InMemoryOtpProvider $otpProvider;

    protected function setUp(): void
    {
        parent::setUp();
        $this->otpProvider = new InMemoryOtpProvider();
        $this->bind(OtpProvider::class, $this->otpProvider);
    }

    #[Test]
    public function sendsOtpForActiveVerifiedUser(): void
    {
        $user = $this->makeUser(active: true, phoneVerified: true);

        $userRepo = $this->createMock(UserRepository::class);
        $userRepo->method('findByEmail')->willReturn($user);

        $otpRepo = $this->createMock(OtpAttemptRepository::class);
        $otpRepo->method('countRecentSendsForPhone')->willReturn(0);

        $em = $this->stubEm(fn ($em) =>
            $em->method('getRepository')->willReturnMap([
                [User::class, $userRepo],
                [OtpAttempt::class, $otpRepo],
            ]));
        $this->bind(EntityManagerInterface::class, $em);

        $response = $this->handle($this->jsonRequest('POST', '/v3/auth/reset', [
            'email' => $user->getEmail(),
        ]));

        self::assertSame(200, $response->getStatusCode());
        $body = $this->jsonBody($response);

        self::assertNotEmpty($body['verification_id']);
        // Real OTP provider was hit.
        self::assertStringStartsWith('inmem-', $body['verification_id']);
        self::assertNotEmpty($this->otpProvider->allIssued());
    }

    #[Test]
    public function returnsFakeVidForUnknownEmail(): void
    {
        $userRepo = $this->createMock(UserRepository::class);
        $userRepo->method('findByEmail')->willReturn(null);

        $em = $this->stubEm(fn ($em) =>
            $em->method('getRepository')->with(User::class)->willReturn($userRepo));
        $this->bind(EntityManagerInterface::class, $em);

        $response = $this->handle($this->jsonRequest('POST', '/v3/auth/reset', [
            'email' => 'unknown@example.com',
        ]));

        self::assertSame(200, $response->getStatusCode());
        $body = $this->jsonBody($response);
        self::assertStringStartsWith('fake-', $body['verification_id']);
        self::assertEmpty($this->otpProvider->allIssued());
    }

    #[Test]
    public function returnsFakeVidForDeactivatedUser(): void
    {
        $user = $this->makeUser(active: false, phoneVerified: true);

        $userRepo = $this->createMock(UserRepository::class);
        $userRepo->method('findByEmail')->willReturn($user);

        $em = $this->stubEm(fn ($em) =>
            $em->method('getRepository')->with(User::class)->willReturn($userRepo));
        $this->bind(EntityManagerInterface::class, $em);

        $response = $this->handle($this->jsonRequest('POST', '/v3/auth/reset', [
            'email' => $user->getEmail(),
        ]));

        self::assertSame(200, $response->getStatusCode());
        self::assertStringStartsWith(
            'fake-',
            $this->jsonBody($response)['verification_id'],
        );
        self::assertEmpty($this->otpProvider->allIssued());
    }

    #[Test]
    public function returnsFakeVidForUnverifiedUser(): void
    {
        $user = $this->makeUser(active: true, phoneVerified: false);

        $userRepo = $this->createMock(UserRepository::class);
        $userRepo->method('findByEmail')->willReturn($user);

        $em = $this->stubEm(fn ($em) =>
            $em->method('getRepository')->with(User::class)->willReturn($userRepo));
        $this->bind(EntityManagerInterface::class, $em);

        $response = $this->handle($this->jsonRequest('POST', '/v3/auth/reset', [
            'email' => $user->getEmail(),
        ]));

        self::assertSame(200, $response->getStatusCode());
        self::assertStringStartsWith(
            'fake-',
            $this->jsonBody($response)['verification_id'],
        );
    }

    #[Test]
    public function returns422OnMissingEmail(): void
    {
        $response = $this->handle($this->jsonRequest('POST', '/v3/auth/reset', []));
        self::assertSame(422, $response->getStatusCode());
    }

    // -------------------------------------------------------------------
    // Two-channel reset (email / phone)
    // -------------------------------------------------------------------

    #[Test]
    public function emailChannelSendsEmailOtp(): void
    {
        $user = $this->makeUser(active: true, phoneVerified: true);

        $userRepo = $this->createMock(UserRepository::class);
        $userRepo->method('findByEmail')->willReturn($user);

        $persistedOtps = [];
        $otpRepo = $this->createMock(OtpAttemptRepository::class);
        $otpRepo->method('countRecentSendsForPhone')->willReturn(0);
        $otpRepo->method('save')->willReturnCallback(function (OtpAttempt $a) use (&$persistedOtps) {
            $persistedOtps[] = $a;
        });

        $em = $this->stubEm(fn ($em) =>
            $em->method('getRepository')->willReturnMap([
                [User::class, $userRepo],
                [OtpAttempt::class, $otpRepo],
            ]));
        $this->bind(EntityManagerInterface::class, $em);

        $response = $this->handle($this->jsonRequest('POST', '/v3/auth/reset', [
            'channel' => 'email',
            'email' => $user->getEmail(),
        ]));

        self::assertSame(200, $response->getStatusCode());
        $body = $this->jsonBody($response);
        // Email-OTP verification ids carry the 'em-' prefix.
        self::assertStringStartsWith('em-', $body['verification_id']);
        // SMS provider was NOT hit (email is local).
        self::assertEmpty($this->otpProvider->allIssued());
        // A password-reset, email-channel OTP row was persisted.
        self::assertCount(1, $persistedOtps);
        self::assertTrue($persistedOtps[0]->isEmailChannel());
        self::assertSame(OtpAttempt::PURPOSE_PASSWORD_RESET, $persistedOtps[0]->getPurpose());
    }

    #[Test]
    public function emailChannelReturnsFakeVidForUnknownEmail(): void
    {
        // Anti-enumeration must hold on the email channel too.
        $userRepo = $this->createMock(UserRepository::class);
        $userRepo->method('findByEmail')->willReturn(null);

        $em = $this->stubEm(fn ($em) =>
            $em->method('getRepository')->with(User::class)->willReturn($userRepo));
        $this->bind(EntityManagerInterface::class, $em);

        $response = $this->handle($this->jsonRequest('POST', '/v3/auth/reset', [
            'channel' => 'email',
            'email' => 'unknown@example.com',
        ]));

        self::assertSame(200, $response->getStatusCode());
        self::assertStringStartsWith('fake-', $this->jsonBody($response)['verification_id']);
    }

    #[Test]
    public function phoneChannelSendsSmsToRegisteredPhone(): void
    {
        $user = $this->makeUser(active: true, phoneVerified: true);

        $userRepo = $this->createMock(UserRepository::class);
        $userRepo->method('findByPhone')->willReturn($user);

        $otpRepo = $this->createMock(OtpAttemptRepository::class);
        $otpRepo->method('countRecentSendsForPhone')->willReturn(0);

        $em = $this->stubEm(fn ($em) =>
            $em->method('getRepository')->willReturnMap([
                [User::class, $userRepo],
                [OtpAttempt::class, $otpRepo],
            ]));
        $this->bind(EntityManagerInterface::class, $em);

        $response = $this->handle($this->jsonRequest('POST', '/v3/auth/reset', [
            'channel' => 'phone',
            'phone' => $user->getPhone(),
        ]));

        self::assertSame(200, $response->getStatusCode());
        self::assertStringStartsWith('inmem-', $this->jsonBody($response)['verification_id']);
        self::assertNotEmpty($this->otpProvider->allIssued());
    }

    #[Test]
    public function phoneChannelRequiresPhone(): void
    {
        $response = $this->handle($this->jsonRequest('POST', '/v3/auth/reset', [
            'channel' => 'phone',
        ]));
        self::assertSame(422, $response->getStatusCode());
    }
}
