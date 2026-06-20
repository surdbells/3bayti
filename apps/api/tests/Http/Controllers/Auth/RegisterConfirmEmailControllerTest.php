<?php

declare(strict_types=1);

namespace Bayti\Api\Tests\Http\Controllers\Auth;

use Bayti\Api\Domain\User\OtpAttempt;
use Bayti\Api\Domain\User\OtpAttemptRepository;
use Bayti\Api\Domain\User\RefreshToken;
use Bayti\Api\Domain\User\RefreshTokenRepository;
use Bayti\Api\Domain\User\User;
use Bayti\Api\Http\Controllers\Auth\Dto\RegisterConfirmEmailInput;
use Bayti\Api\Http\Controllers\Auth\RegisterConfirmEmailController;
use Bayti\Api\Http\Errors\ErrorCodes;
use Bayti\Api\Infrastructure\Auth\JwtService;
use Bayti\Api\Tests\Http\HttpTestCase;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;

#[CoversClass(RegisterConfirmEmailController::class)]
#[CoversClass(RegisterConfirmEmailInput::class)]
final class RegisterConfirmEmailControllerTest extends HttpTestCase
{
    /**
     * Build an email-channel registration OtpAttempt with a known code,
     * bound to $user, and wire up the EM so the controller + OtpService
     * see it.
     */
    private function bindEmailAttempt(
        User $user,
        string $code = '123456',
        string $expires = '+5 minutes',
        int $attempts = 0,
    ): OtpAttempt {
        $attempt = new OtpAttempt(
            verificationId: 'em-test',
            phone: $user->getPhone() ?? '',
            purpose: OtpAttempt::PURPOSE_REGISTRATION,
            expiresAt: (new DateTimeImmutable())->modify($expires),
            user: $user,
            channel: OtpAttempt::CHANNEL_EMAIL,
            codeHash: password_hash($code, PASSWORD_BCRYPT),
            email: $user->getEmail(),
        );
        for ($i = 0; $i < $attempts; $i++) {
            $attempt->incrementAttempts();
        }

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

        return $attempt;
    }

    #[Test]
    public function happyPathVerifiesEmailAndIssuesTokens(): void
    {
        $user = $this->makeUser(id: 55, phoneVerified: true);
        $this->bindEmailAttempt($user, code: '123456');

        $response = $this->handle($this->jsonRequest('POST', '/v3/auth/register/confirm-email', [
            'verification_id' => 'em-test',
            'code' => '123456',
        ]));

        self::assertSame(200, $response->getStatusCode());
        $body = $this->jsonBody($response);
        self::assertNotEmpty($body['access_token']);
        self::assertNotEmpty($body['refresh_token']);
        self::assertSame(55, $body['user']['id']);

        // Side effect: email marked verified.
        self::assertTrue($user->isEmailVerified());

        // The access token round-trips.
        $jwt = $this->app->getContainer()->get(JwtService::class);
        $claims = $jwt->verifyAccessToken($body['access_token']);
        self::assertNotNull($claims);
        self::assertSame(55, $claims->userId);
    }

    #[Test]
    public function returns401ForWrongCode(): void
    {
        $user = $this->makeUser(phoneVerified: true);
        $attempt = $this->bindEmailAttempt($user, code: '123456');

        $response = $this->handle($this->jsonRequest('POST', '/v3/auth/register/confirm-email', [
            'verification_id' => 'em-test',
            'code' => '000000', // wrong
        ]));

        self::assertSame(401, $response->getStatusCode());
        self::assertSame(
            ErrorCodes::OTP_VERIFICATION_FAILED,
            $this->jsonBody($response)['error']['code'],
        );
        self::assertFalse($user->isEmailVerified());
        // The failed attempt was counted.
        self::assertSame(1, $attempt->getAttempts());
    }

    #[Test]
    public function returns401ForExpiredOtp(): void
    {
        $user = $this->makeUser(phoneVerified: true);
        $this->bindEmailAttempt($user, code: '123456', expires: '-1 second');

        $response = $this->handle($this->jsonRequest('POST', '/v3/auth/register/confirm-email', [
            'verification_id' => 'em-test',
            'code' => '123456',
        ]));

        self::assertSame(401, $response->getStatusCode());
        self::assertFalse($user->isEmailVerified());
    }

    #[Test]
    public function returns401WhenAttemptCapReached(): void
    {
        // Already at the cap (5 failed tries). Even the CORRECT code is
        // refused — the row is burned.
        $user = $this->makeUser(phoneVerified: true);
        $this->bindEmailAttempt($user, code: '123456', attempts: OtpAttempt::MAX_EMAIL_ATTEMPTS);

        $response = $this->handle($this->jsonRequest('POST', '/v3/auth/register/confirm-email', [
            'verification_id' => 'em-test',
            'code' => '123456', // correct, but capped
        ]));

        self::assertSame(401, $response->getStatusCode());
        self::assertFalse($user->isEmailVerified());
    }

    #[Test]
    public function returns401ForSmsChannelAttempt(): void
    {
        // An SMS-channel row submitted to confirm-email must be rejected.
        $user = $this->makeUser(phoneVerified: true);
        $attempt = new OtpAttempt(
            verificationId: 'mc-sms',
            phone: $user->getPhone() ?? '',
            purpose: OtpAttempt::PURPOSE_REGISTRATION,
            expiresAt: (new DateTimeImmutable())->modify('+5 minutes'),
            user: $user,
            channel: OtpAttempt::CHANNEL_SMS,
        );
        $otpRepo = $this->createMock(OtpAttemptRepository::class);
        $otpRepo->method('findByVerificationId')->willReturn($attempt);
        $em = $this->stubEm(fn ($em) =>
            $em->method('getRepository')->with(OtpAttempt::class)->willReturn($otpRepo));
        $this->bind(EntityManagerInterface::class, $em);

        $response = $this->handle($this->jsonRequest('POST', '/v3/auth/register/confirm-email', [
            'verification_id' => 'mc-sms',
            'code' => '000000',
        ]));

        self::assertSame(401, $response->getStatusCode());
    }

    #[Test]
    public function returns422OnInvalidCodeFormat(): void
    {
        $response = $this->handle($this->jsonRequest('POST', '/v3/auth/register/confirm-email', [
            'verification_id' => 'em-test',
            'code' => 'abc',
        ]));
        self::assertSame(422, $response->getStatusCode());
    }
}
