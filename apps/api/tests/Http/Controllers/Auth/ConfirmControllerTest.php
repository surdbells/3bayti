<?php

declare(strict_types=1);

namespace Bayti\Api\Tests\Http\Controllers\Auth;

use Bayti\Api\Domain\User\OtpAttempt;
use Bayti\Api\Domain\User\OtpAttemptRepository;
use Bayti\Api\Domain\User\RefreshToken;
use Bayti\Api\Domain\User\RefreshTokenRepository;
use Bayti\Api\Domain\User\User;
use Bayti\Api\Http\Controllers\Auth\ConfirmController;
use Bayti\Api\Http\Controllers\Auth\Dto\ConfirmInput;
use Bayti\Api\Http\Errors\ErrorCodes;
use Bayti\Api\Infrastructure\Auth\JwtService;
use Bayti\Api\Infrastructure\Otp\InMemoryOtpProvider;
use Bayti\Api\Infrastructure\Otp\OtpProvider;
use Bayti\Api\Tests\Http\HttpTestCase;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;

#[CoversClass(ConfirmController::class)]
#[CoversClass(ConfirmInput::class)]
final class ConfirmControllerTest extends HttpTestCase
{
    private InMemoryOtpProvider $otpProvider;

    protected function setUp(): void
    {
        parent::setUp();
        $this->otpProvider = new InMemoryOtpProvider();
        $this->bind(OtpProvider::class, $this->otpProvider);
    }

    // -------------------------------------------------------------------
    // Happy path
    // -------------------------------------------------------------------

    #[Test]
    public function happyPathVerifiesOtpAndIssuesTokens(): void
    {
        $user = $this->makeUser(id: 42, phoneVerified: false);

        // Pre-stage an OTP through the provider — the provider will
        // accept '000000' as the default code.
        $verificationId = $this->otpProvider->send($user->getPhone());

        // Construct the OtpAttempt as if /register persisted it.
        $attempt = new OtpAttempt(
            verificationId: $verificationId,
            phone: $user->getPhone(),
            purpose: OtpAttempt::PURPOSE_REGISTRATION,
            expiresAt: (new DateTimeImmutable())->modify('+5 minutes'),
            user: $user,
        );

        $otpRepo = $this->createMock(OtpAttemptRepository::class);
        $otpRepo->method('findByVerificationId')->willReturn($attempt);

        $refreshRepo = $this->createMock(RefreshTokenRepository::class);
        $refreshRepo->expects(self::once())->method('save');

        $em = $this->stubEm(fn ($em) =>
            $em->method('getRepository')->willReturnMap([
                [OtpAttempt::class, $otpRepo],
                [RefreshToken::class, $refreshRepo],
            ]));
        $this->bind(EntityManagerInterface::class, $em);

        $response = $this->handle($this->jsonRequest('POST', '/v3/auth/confirm', [
            'verification_id' => $verificationId,
            'code' => '000000',
        ]));

        self::assertSame(200, $response->getStatusCode());

        $body = $this->jsonBody($response);
        self::assertNotEmpty($body['access_token']);
        self::assertNotEmpty($body['refresh_token']);
        self::assertSame(42, $body['user']['id']);
        self::assertTrue($body['user']['is_phone_verified']);

        // Side effect: user was actually marked phone-verified.
        self::assertTrue($user->isPhoneVerified());

        // Issued access token is real (round-trip via JwtService).
        $jwt = $this->app->getContainer()->get(JwtService::class);
        $claims = $jwt->verifyAccessToken($body['access_token']);
        self::assertNotNull($claims);
        self::assertSame(42, $claims->userId);
    }

    #[Test]
    public function acceptsAFourDigitCode(): void
    {
        // MessageCentral issues 4-digit codes in this account; the DTO
        // accepts 4–6 digits. A 4-digit code must pass format validation
        // AND verify end-to-end.
        $user = $this->makeUser(id: 43, phoneVerified: false);
        $verificationId = $this->otpProvider->send($user->getPhone());
        $this->otpProvider->setExpectedCode($verificationId, '1234');

        $attempt = new OtpAttempt(
            verificationId: $verificationId,
            phone: $user->getPhone(),
            purpose: OtpAttempt::PURPOSE_REGISTRATION,
            expiresAt: (new DateTimeImmutable())->modify('+5 minutes'),
            user: $user,
        );

        $otpRepo = $this->createMock(OtpAttemptRepository::class);
        $otpRepo->method('findByVerificationId')->willReturn($attempt);
        $refreshRepo = $this->createMock(RefreshTokenRepository::class);
        $refreshRepo->method('save');

        $em = $this->stubEm(fn ($em) =>
            $em->method('getRepository')->willReturnMap([
                [OtpAttempt::class, $otpRepo],
                [RefreshToken::class, $refreshRepo],
            ]));
        $this->bind(EntityManagerInterface::class, $em);

        $response = $this->handle($this->jsonRequest('POST', '/v3/auth/confirm', [
            'verification_id' => $verificationId,
            'code' => '1234',
        ]));

        self::assertSame(200, $response->getStatusCode());
        self::assertTrue($user->isPhoneVerified());
    }

    // -------------------------------------------------------------------
    // Failure modes — all collapse to single 401
    // -------------------------------------------------------------------

    #[Test]
    public function returns401ForUnknownVerificationId(): void
    {
        $otpRepo = $this->createMock(OtpAttemptRepository::class);
        $otpRepo->method('findByVerificationId')->willReturn(null);

        $em = $this->stubEm(fn ($em) =>
            $em->method('getRepository')->with(OtpAttempt::class)->willReturn($otpRepo));
        $this->bind(EntityManagerInterface::class, $em);

        $response = $this->handle($this->jsonRequest('POST', '/v3/auth/confirm', [
            'verification_id' => 'does-not-exist',
            'code' => '000000',
        ]));

        self::assertSame(401, $response->getStatusCode());
        self::assertSame(
            ErrorCodes::OTP_VERIFICATION_FAILED,
            $this->jsonBody($response)['error']['code'],
        );
    }

    #[Test]
    public function returns401ForWrongCode(): void
    {
        $user = $this->makeUser(phoneVerified: false);
        $verificationId = $this->otpProvider->send($user->getPhone());

        $attempt = new OtpAttempt(
            verificationId: $verificationId,
            phone: $user->getPhone(),
            purpose: OtpAttempt::PURPOSE_REGISTRATION,
            expiresAt: (new DateTimeImmutable())->modify('+5 minutes'),
            user: $user,
        );

        $otpRepo = $this->createMock(OtpAttemptRepository::class);
        $otpRepo->method('findByVerificationId')->willReturn($attempt);

        $em = $this->stubEm(fn ($em) =>
            $em->method('getRepository')->with(OtpAttempt::class)->willReturn($otpRepo));
        $this->bind(EntityManagerInterface::class, $em);

        $response = $this->handle($this->jsonRequest('POST', '/v3/auth/confirm', [
            'verification_id' => $verificationId,
            'code' => '123456', // wrong; default accept is '000000'
        ]));

        self::assertSame(401, $response->getStatusCode());
        self::assertSame(
            ErrorCodes::OTP_VERIFICATION_FAILED,
            $this->jsonBody($response)['error']['code'],
        );
        // User should NOT be marked phone-verified by the failed attempt.
        self::assertFalse($user->isPhoneVerified());
    }

    #[Test]
    public function returns401ForExpiredOtp(): void
    {
        $user = $this->makeUser();
        $verificationId = $this->otpProvider->send($user->getPhone());

        $attempt = new OtpAttempt(
            verificationId: $verificationId,
            phone: $user->getPhone(),
            purpose: OtpAttempt::PURPOSE_REGISTRATION,
            expiresAt: (new DateTimeImmutable())->modify('-1 second'), // expired
            user: $user,
        );

        $otpRepo = $this->createMock(OtpAttemptRepository::class);
        $otpRepo->method('findByVerificationId')->willReturn($attempt);

        $em = $this->stubEm(fn ($em) =>
            $em->method('getRepository')->with(OtpAttempt::class)->willReturn($otpRepo));
        $this->bind(EntityManagerInterface::class, $em);

        $response = $this->handle($this->jsonRequest('POST', '/v3/auth/confirm', [
            'verification_id' => $verificationId,
            'code' => '000000',
        ]));

        self::assertSame(401, $response->getStatusCode());
    }

    #[Test]
    public function returns401ForCrossPurposeAttempt(): void
    {
        // OTP exists but its purpose is password_reset, not registration.
        // Must reject — cross-flow abuse.
        $user = $this->makeUser();
        $attempt = new OtpAttempt(
            verificationId: 'mc-pwreset',
            phone: $user->getPhone(),
            purpose: OtpAttempt::PURPOSE_PASSWORD_RESET,
            expiresAt: (new DateTimeImmutable())->modify('+5 minutes'),
            user: $user,
        );

        $otpRepo = $this->createMock(OtpAttemptRepository::class);
        $otpRepo->method('findByVerificationId')->willReturn($attempt);

        $em = $this->stubEm(fn ($em) =>
            $em->method('getRepository')->with(OtpAttempt::class)->willReturn($otpRepo));
        $this->bind(EntityManagerInterface::class, $em);

        $response = $this->handle($this->jsonRequest('POST', '/v3/auth/confirm', [
            'verification_id' => 'mc-pwreset',
            'code' => '000000',
        ]));

        self::assertSame(401, $response->getStatusCode());
    }

    #[Test]
    public function returns422OnInvalidCodeFormat(): void
    {
        $response = $this->handle($this->jsonRequest('POST', '/v3/auth/confirm', [
            'verification_id' => 'whatever',
            'code' => 'abc',
        ]));
        self::assertSame(422, $response->getStatusCode());
    }
}
