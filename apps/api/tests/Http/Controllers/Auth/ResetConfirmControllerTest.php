<?php

declare(strict_types=1);

namespace Bayti\Api\Tests\Http\Controllers\Auth;

use Bayti\Api\Domain\User\OtpAttempt;
use Bayti\Api\Domain\User\OtpAttemptRepository;
use Bayti\Api\Domain\User\RefreshToken;
use Bayti\Api\Domain\User\RefreshTokenRepository;
use Bayti\Api\Domain\User\User;
use Bayti\Api\Http\Controllers\Auth\Dto\ResetConfirmInput;
use Bayti\Api\Http\Controllers\Auth\ResetConfirmController;
use Bayti\Api\Http\Errors\ErrorCodes;
use Bayti\Api\Infrastructure\Auth\JwtService;
use Bayti\Api\Infrastructure\Otp\InMemoryOtpProvider;
use Bayti\Api\Infrastructure\Otp\OtpProvider;
use Bayti\Api\Tests\Http\HttpTestCase;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;

#[CoversClass(ResetConfirmController::class)]
#[CoversClass(ResetConfirmInput::class)]
final class ResetConfirmControllerTest extends HttpTestCase
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
    public function happyPathSetsNewPasswordRevokesAllAndIssuesTokens(): void
    {
        $user = $this->makeUser(id: 7, passwordPlain: 'oldpass123');
        $oldHash = $user->getPasswordHash();

        $verificationId = $this->otpProvider->send($user->getPhone());

        $attempt = new OtpAttempt(
            verificationId: $verificationId,
            phone: $user->getPhone(),
            purpose: OtpAttempt::PURPOSE_PASSWORD_RESET,
            expiresAt: (new DateTimeImmutable())->modify('+5 minutes'),
            user: $user,
        );

        $otpRepo = $this->createMock(OtpAttemptRepository::class);
        $otpRepo->method('findByVerificationId')->willReturn($attempt);

        $refreshRepo = $this->createMock(RefreshTokenRepository::class);
        // Logout-everywhere: revokeAllForUser MUST be called.
        $refreshRepo->expects(self::once())
            ->method('revokeAllForUser')
            ->with($user, 'password_changed');
        // New refresh row MUST be persisted.
        $refreshRepo->expects(self::once())->method('save');

        $em = $this->stubEm(fn ($em) =>
            $em->method('getRepository')->willReturnMap([
                [OtpAttempt::class, $otpRepo],
                [RefreshToken::class, $refreshRepo],
            ]));
        $this->bind(EntityManagerInterface::class, $em);

        $response = $this->handle($this->jsonRequest('POST', '/v3/auth/reset/confirm', [
            'verification_id' => $verificationId,
            'code' => '000000',
            'new_password' => 'newSecret123',
        ]));

        self::assertSame(200, $response->getStatusCode());

        $body = $this->jsonBody($response);
        self::assertNotEmpty($body['access_token']);
        self::assertNotEmpty($body['refresh_token']);
        self::assertSame(7, $body['user']['id']);

        // Side effects:
        // - User's password hash changed
        self::assertNotSame($oldHash, $user->getPasswordHash());
        // - Old password no longer verifies
        self::assertFalse(password_verify('oldpass123', $user->getPasswordHash()));
        // - New password DOES verify
        self::assertTrue(password_verify('newSecret123', $user->getPasswordHash()));
        // - password_changed_at was bumped
        self::assertNotNull($user->getPasswordChangedAt());

        // Round-trip: issued token verifies via real JwtService
        $jwt = $this->app->getContainer()->get(JwtService::class);
        $claims = $jwt->verifyAccessToken($body['access_token']);
        self::assertNotNull($claims);
        self::assertSame(7, $claims->userId);
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

        $response = $this->handle($this->jsonRequest('POST', '/v3/auth/reset/confirm', [
            'verification_id' => 'unknown',
            'code' => '000000',
            'new_password' => 'newSecret123',
        ]));

        self::assertSame(401, $response->getStatusCode());
        self::assertSame(
            ErrorCodes::OTP_VERIFICATION_FAILED,
            $this->jsonBody($response)['error']['code'],
        );
    }

    #[Test]
    public function returns401ForCrossPurposeAttempt(): void
    {
        // OTP exists but its purpose is registration, not password_reset.
        $user = $this->makeUser();
        $attempt = new OtpAttempt(
            verificationId: 'mc-reg',
            phone: $user->getPhone(),
            purpose: OtpAttempt::PURPOSE_REGISTRATION, // wrong purpose
            expiresAt: (new DateTimeImmutable())->modify('+5 minutes'),
            user: $user,
        );

        $otpRepo = $this->createMock(OtpAttemptRepository::class);
        $otpRepo->method('findByVerificationId')->willReturn($attempt);

        $em = $this->stubEm(fn ($em) =>
            $em->method('getRepository')->with(OtpAttempt::class)->willReturn($otpRepo));
        $this->bind(EntityManagerInterface::class, $em);

        $response = $this->handle($this->jsonRequest('POST', '/v3/auth/reset/confirm', [
            'verification_id' => 'mc-reg',
            'code' => '000000',
            'new_password' => 'newSecret123',
        ]));

        self::assertSame(401, $response->getStatusCode());
        // Password should NOT have been changed.
        self::assertFalse(password_verify('newSecret123', $user->getPasswordHash()));
    }

    #[Test]
    public function returns401ForWrongCode(): void
    {
        $user = $this->makeUser(passwordPlain: 'oldpass123');
        $oldHash = $user->getPasswordHash();
        $verificationId = $this->otpProvider->send($user->getPhone());

        $attempt = new OtpAttempt(
            verificationId: $verificationId,
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

        $response = $this->handle($this->jsonRequest('POST', '/v3/auth/reset/confirm', [
            'verification_id' => $verificationId,
            'code' => '999999', // wrong; in-memory accepts '000000'
            'new_password' => 'newSecret123',
        ]));

        self::assertSame(401, $response->getStatusCode());
        // Password unchanged.
        self::assertSame($oldHash, $user->getPasswordHash());
    }

    #[Test]
    public function returns422OnShortNewPassword(): void
    {
        $response = $this->handle($this->jsonRequest('POST', '/v3/auth/reset/confirm', [
            'verification_id' => 'whatever',
            'code' => '000000',
            'new_password' => 'short',
        ]));

        self::assertSame(422, $response->getStatusCode());
        self::assertArrayHasKey(
            'new_password',
            $this->jsonBody($response)['error']['details']['fields'],
        );
    }

    #[Test]
    public function returns422OnInvalidCodeFormat(): void
    {
        $response = $this->handle($this->jsonRequest('POST', '/v3/auth/reset/confirm', [
            'verification_id' => 'whatever',
            'code' => 'abcdef',
            'new_password' => 'newSecret123',
        ]));

        self::assertSame(422, $response->getStatusCode());
    }
}
