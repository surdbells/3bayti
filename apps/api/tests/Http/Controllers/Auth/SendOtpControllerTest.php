<?php

declare(strict_types=1);

namespace Bayti\Api\Tests\Http\Controllers\Auth;

use Bayti\Api\Domain\User\OtpAttempt;
use Bayti\Api\Domain\User\OtpAttemptRepository;
use Bayti\Api\Domain\User\User;
use Bayti\Api\Domain\User\UserRepository;
use Bayti\Api\Http\Controllers\Auth\Dto\SendOtpInput;
use Bayti\Api\Http\Controllers\Auth\SendOtpController;
use Bayti\Api\Infrastructure\Otp\InMemoryOtpProvider;
use Bayti\Api\Infrastructure\Otp\OtpProvider;
use Bayti\Api\Tests\Http\HttpTestCase;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;

#[CoversClass(SendOtpController::class)]
#[CoversClass(SendOtpInput::class)]
final class SendOtpControllerTest extends HttpTestCase
{
    private InMemoryOtpProvider $otpProvider;

    protected function setUp(): void
    {
        parent::setUp();
        $this->otpProvider = new InMemoryOtpProvider();
        $this->bind(OtpProvider::class, $this->otpProvider);
    }

    #[Test]
    public function sendsOtpForUnverifiedUser(): void
    {
        $user = $this->makeUser(phoneVerified: false);

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

        $response = $this->handle($this->jsonRequest('POST', '/v3/auth/send-otp', [
            'email' => $user->getEmail(),
        ]));

        self::assertSame(200, $response->getStatusCode());

        $body = $this->jsonBody($response);
        self::assertNotEmpty($body['verification_id']);
        // Real OTP provider was hit — not a fake.
        self::assertStringStartsWith('inmem-', $body['verification_id']);
        self::assertNotEmpty($this->otpProvider->allIssued());
    }

    #[Test]
    public function returnsFakeVerificationIdForUnknownEmail(): void
    {
        $userRepo = $this->createMock(UserRepository::class);
        $userRepo->method('findByEmail')->willReturn(null);

        $em = $this->stubEm(fn ($em) =>
            $em->method('getRepository')->with(User::class)->willReturn($userRepo));
        $this->bind(EntityManagerInterface::class, $em);

        $response = $this->handle($this->jsonRequest('POST', '/v3/auth/send-otp', [
            'email' => 'nobody@example.com',
        ]));

        self::assertSame(200, $response->getStatusCode());
        $body = $this->jsonBody($response);
        self::assertNotEmpty($body['verification_id']);
        self::assertStringStartsWith('fake-', $body['verification_id']);
        // Real OTP provider was NOT hit — anti-enumeration policy.
        self::assertEmpty($this->otpProvider->allIssued());
    }

    #[Test]
    public function returnsFakeVerificationIdForAlreadyVerifiedUser(): void
    {
        $user = $this->makeUser(phoneVerified: true);

        $userRepo = $this->createMock(UserRepository::class);
        $userRepo->method('findByEmail')->willReturn($user);

        $em = $this->stubEm(fn ($em) =>
            $em->method('getRepository')->with(User::class)->willReturn($userRepo));
        $this->bind(EntityManagerInterface::class, $em);

        $response = $this->handle($this->jsonRequest('POST', '/v3/auth/send-otp', [
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
    public function returns422OnMissingEmail(): void
    {
        $response = $this->handle($this->jsonRequest('POST', '/v3/auth/send-otp', []));
        self::assertSame(422, $response->getStatusCode());
    }
}
