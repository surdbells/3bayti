<?php

declare(strict_types=1);

namespace Bayti\Api\Tests\Http\Controllers\Auth;

use Bayti\Api\Domain\User\OtpAttempt;
use Bayti\Api\Domain\User\OtpAttemptRepository;
use Bayti\Api\Domain\User\RefreshToken;
use Bayti\Api\Domain\User\RefreshTokenRepository;
use Bayti\Api\Domain\User\User;
use Bayti\Api\Http\Controllers\Auth\Dto\OtpLoginVerifyInput;
use Bayti\Api\Http\Controllers\Auth\OtpLoginVerifyController;
use Bayti\Api\Http\Errors\ErrorCodes;
use Bayti\Api\Infrastructure\Auth\JwtService;
use Bayti\Api\Infrastructure\Otp\InMemoryOtpProvider;
use Bayti\Api\Infrastructure\Otp\OtpProvider;
use Bayti\Api\Tests\Http\HttpTestCase;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;

#[CoversClass(OtpLoginVerifyController::class)]
#[CoversClass(OtpLoginVerifyInput::class)]
final class OtpLoginVerifyControllerTest extends HttpTestCase
{
    private InMemoryOtpProvider $otpProvider;

    protected function setUp(): void
    {
        parent::setUp();
        $this->otpProvider = new InMemoryOtpProvider();
        $this->bind(OtpProvider::class, $this->otpProvider);
    }

    // -------------------------------------------------------------------
    // Happy path — SMS (phone) channel
    // -------------------------------------------------------------------

    #[Test]
    public function validCodeLogsInAndReturnsEnvelope(): void
    {
        $user = $this->makeUser(id: 77, active: true, phoneVerified: true);

        // Pre-stage the SMS OTP; provider accepts '000000' by default.
        $verificationId = $this->otpProvider->send($user->getPhone());

        $attempt = new OtpAttempt(
            verificationId: $verificationId,
            phone: $user->getPhone(),
            purpose: OtpAttempt::PURPOSE_LOGIN_2FA,
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

        $response = $this->handle($this->jsonRequest('POST', '/v3/auth/otp-login/verify', [
            'verification_id' => $verificationId,
            'code' => '000000',
        ]));

        self::assertSame(200, $response->getStatusCode());

        $body = $this->jsonBody($response);
        self::assertNotEmpty($body['access_token']);
        self::assertNotEmpty($body['refresh_token']);
        self::assertSame(77, $body['user']['id']);

        // Issued access token round-trips through JwtService.
        $jwt = $this->app->getContainer()->get(JwtService::class);
        $claims = $jwt->verifyAccessToken($body['access_token']);
        self::assertNotNull($claims);
        self::assertSame(77, $claims->userId);
    }

    // -------------------------------------------------------------------
    // Happy path — email channel
    // -------------------------------------------------------------------

    #[Test]
    public function validEmailOtpLogsIn(): void
    {
        $user = $this->makeUser(id: 88, active: true, phoneVerified: true);

        // Build an email-channel attempt with a known code hash.
        $attempt = new OtpAttempt(
            verificationId: 'em-login-1',
            phone: $user->getPhone(),
            purpose: OtpAttempt::PURPOSE_LOGIN_2FA,
            expiresAt: (new DateTimeImmutable())->modify('+5 minutes'),
            user: $user,
            channel: OtpAttempt::CHANNEL_EMAIL,
            codeHash: password_hash('123456', PASSWORD_BCRYPT),
            email: $user->getEmail(),
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

        $response = $this->handle($this->jsonRequest('POST', '/v3/auth/otp-login/verify', [
            'verification_id' => 'em-login-1',
            'code' => '123456',
        ]));

        self::assertSame(200, $response->getStatusCode());
        $body = $this->jsonBody($response);
        self::assertSame(88, $body['user']['id']);
        self::assertNotEmpty($body['access_token']);
        // Email OTP consumed (single-use).
        self::assertTrue($attempt->isConsumed());
    }

    // -------------------------------------------------------------------
    // Failure modes — all collapse to a single 401
    // -------------------------------------------------------------------

    #[Test]
    public function returns401ForUnknownVerificationId(): void
    {
        $otpRepo = $this->createMock(OtpAttemptRepository::class);
        $otpRepo->method('findByVerificationId')->willReturn(null);

        $em = $this->stubEm(fn ($em) =>
            $em->method('getRepository')->with(OtpAttempt::class)->willReturn($otpRepo));
        $this->bind(EntityManagerInterface::class, $em);

        $response = $this->handle($this->jsonRequest('POST', '/v3/auth/otp-login/verify', [
            'verification_id' => 'fake-deadbeef',
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
        $user = $this->makeUser(active: true, phoneVerified: true);
        $verificationId = $this->otpProvider->send($user->getPhone());

        $attempt = new OtpAttempt(
            verificationId: $verificationId,
            phone: $user->getPhone(),
            purpose: OtpAttempt::PURPOSE_LOGIN_2FA,
            expiresAt: (new DateTimeImmutable())->modify('+5 minutes'),
            user: $user,
        );

        $otpRepo = $this->createMock(OtpAttemptRepository::class);
        $otpRepo->method('findByVerificationId')->willReturn($attempt);

        $em = $this->stubEm(fn ($em) =>
            $em->method('getRepository')->with(OtpAttempt::class)->willReturn($otpRepo));
        $this->bind(EntityManagerInterface::class, $em);

        $response = $this->handle($this->jsonRequest('POST', '/v3/auth/otp-login/verify', [
            'verification_id' => $verificationId,
            'code' => '111111', // wrong; default accept is '000000'
        ]));

        self::assertSame(401, $response->getStatusCode());
        self::assertSame(
            ErrorCodes::OTP_VERIFICATION_FAILED,
            $this->jsonBody($response)['error']['code'],
        );
    }

    #[Test]
    public function returns401ForExpiredOtp(): void
    {
        $user = $this->makeUser(active: true, phoneVerified: true);
        $verificationId = $this->otpProvider->send($user->getPhone());

        $attempt = new OtpAttempt(
            verificationId: $verificationId,
            phone: $user->getPhone(),
            purpose: OtpAttempt::PURPOSE_LOGIN_2FA,
            expiresAt: (new DateTimeImmutable())->modify('-1 second'), // expired
            user: $user,
        );

        $otpRepo = $this->createMock(OtpAttemptRepository::class);
        $otpRepo->method('findByVerificationId')->willReturn($attempt);

        $em = $this->stubEm(fn ($em) =>
            $em->method('getRepository')->with(OtpAttempt::class)->willReturn($otpRepo));
        $this->bind(EntityManagerInterface::class, $em);

        $response = $this->handle($this->jsonRequest('POST', '/v3/auth/otp-login/verify', [
            'verification_id' => $verificationId,
            'code' => '000000',
        ]));

        self::assertSame(401, $response->getStatusCode());
    }

    #[Test]
    public function returns401ForCrossPurposeAttempt(): void
    {
        // OTP exists but its purpose is registration, not login_2fa.
        // Must reject — cross-flow abuse.
        $user = $this->makeUser(active: true, phoneVerified: true);
        $attempt = new OtpAttempt(
            verificationId: 'mc-register',
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

        $response = $this->handle($this->jsonRequest('POST', '/v3/auth/otp-login/verify', [
            'verification_id' => 'mc-register',
            'code' => '000000',
        ]));

        self::assertSame(401, $response->getStatusCode());
    }

    #[Test]
    public function returns401WhenUserBecameInactive(): void
    {
        // Active at send time, disabled before verify. Correct code, but
        // we must refuse to mint a session.
        $user = $this->makeUser(active: false, phoneVerified: true);
        $verificationId = $this->otpProvider->send($user->getPhone());

        $attempt = new OtpAttempt(
            verificationId: $verificationId,
            phone: $user->getPhone(),
            purpose: OtpAttempt::PURPOSE_LOGIN_2FA,
            expiresAt: (new DateTimeImmutable())->modify('+5 minutes'),
            user: $user,
        );

        $otpRepo = $this->createMock(OtpAttemptRepository::class);
        $otpRepo->method('findByVerificationId')->willReturn($attempt);

        $em = $this->stubEm(fn ($em) =>
            $em->method('getRepository')->with(OtpAttempt::class)->willReturn($otpRepo));
        $this->bind(EntityManagerInterface::class, $em);

        $response = $this->handle($this->jsonRequest('POST', '/v3/auth/otp-login/verify', [
            'verification_id' => $verificationId,
            'code' => '000000',
        ]));

        self::assertSame(401, $response->getStatusCode());
    }

    #[Test]
    public function returns422OnInvalidCodeFormat(): void
    {
        $response = $this->handle($this->jsonRequest('POST', '/v3/auth/otp-login/verify', [
            'verification_id' => 'whatever',
            'code' => 'xyz',
        ]));
        self::assertSame(422, $response->getStatusCode());
    }
}
