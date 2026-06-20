<?php

declare(strict_types=1);

namespace Bayti\Api\Tests\Http\Controllers\Auth;

use Bayti\Api\Domain\User\OtpAttempt;
use Bayti\Api\Domain\User\OtpAttemptRepository;
use Bayti\Api\Http\Controllers\Auth\Dto\RegisterVerifyPhoneInput;
use Bayti\Api\Http\Controllers\Auth\RegisterVerifyPhoneController;
use Bayti\Api\Http\Errors\ErrorCodes;
use Bayti\Api\Infrastructure\Auth\JwtService;
use Bayti\Api\Infrastructure\Otp\InMemoryOtpProvider;
use Bayti\Api\Infrastructure\Otp\OtpProvider;
use Bayti\Api\Tests\Http\HttpTestCase;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;

#[CoversClass(RegisterVerifyPhoneController::class)]
#[CoversClass(RegisterVerifyPhoneInput::class)]
final class RegisterVerifyPhoneControllerTest extends HttpTestCase
{
    private InMemoryOtpProvider $otpProvider;

    protected function setUp(): void
    {
        parent::setUp();
        $this->otpProvider = new InMemoryOtpProvider();
        $this->bind(OtpProvider::class, $this->otpProvider);
    }

    private function bindAttempt(?OtpAttempt $attempt): void
    {
        $otpRepo = $this->createMock(OtpAttemptRepository::class);
        $otpRepo->method('findByVerificationId')->willReturn($attempt);
        $em = $this->stubEm(fn ($em) =>
            $em->method('getRepository')->with(OtpAttempt::class)->willReturn($otpRepo));
        $this->bind(EntityManagerInterface::class, $em);
    }

    #[Test]
    public function happyPathMintsRegistrationToken(): void
    {
        $vid = $this->otpProvider->send('+971501234567');
        $attempt = new OtpAttempt(
            verificationId: $vid,
            phone: '+971501234567',
            purpose: OtpAttempt::PURPOSE_REGISTRATION,
            expiresAt: (new DateTimeImmutable())->modify('+5 minutes'),
            channel: OtpAttempt::CHANNEL_SMS,
        );
        $this->bindAttempt($attempt);

        $response = $this->handle($this->jsonRequest('POST', '/v3/auth/register/verify-phone', [
            'verification_id' => $vid,
            'code' => '000000',
        ]));

        self::assertSame(200, $response->getStatusCode());
        $token = $this->jsonBody($response)['registration_token'];
        self::assertNotEmpty($token);

        // The token must decode to the verified phone.
        $jwt = $this->app->getContainer()->get(JwtService::class);
        $claims = $jwt->verifyRegistrationToken($token);
        self::assertNotNull($claims);
        self::assertSame('+971501234567', $claims['phone']);

        // ...and must NOT validate as an access token (distinct audience).
        self::assertNull($jwt->verifyAccessToken($token));
    }

    #[Test]
    public function returns401ForWrongCode(): void
    {
        $vid = $this->otpProvider->send('+971501234567');
        $attempt = new OtpAttempt(
            verificationId: $vid,
            phone: '+971501234567',
            purpose: OtpAttempt::PURPOSE_REGISTRATION,
            expiresAt: (new DateTimeImmutable())->modify('+5 minutes'),
            channel: OtpAttempt::CHANNEL_SMS,
        );
        $this->bindAttempt($attempt);

        $response = $this->handle($this->jsonRequest('POST', '/v3/auth/register/verify-phone', [
            'verification_id' => $vid,
            'code' => '123456',
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
        $vid = $this->otpProvider->send('+971501234567');
        $attempt = new OtpAttempt(
            verificationId: $vid,
            phone: '+971501234567',
            purpose: OtpAttempt::PURPOSE_REGISTRATION,
            expiresAt: (new DateTimeImmutable())->modify('-1 second'),
            channel: OtpAttempt::CHANNEL_SMS,
        );
        $this->bindAttempt($attempt);

        $response = $this->handle($this->jsonRequest('POST', '/v3/auth/register/verify-phone', [
            'verification_id' => $vid,
            'code' => '000000',
        ]));

        self::assertSame(401, $response->getStatusCode());
    }

    #[Test]
    public function returns401ForUnknownVerificationId(): void
    {
        $this->bindAttempt(null);

        $response = $this->handle($this->jsonRequest('POST', '/v3/auth/register/verify-phone', [
            'verification_id' => 'nope',
            'code' => '000000',
        ]));

        self::assertSame(401, $response->getStatusCode());
    }

    #[Test]
    public function returns401ForEmailChannelAttempt(): void
    {
        // An email-channel row submitted to the phone-verify endpoint
        // must be rejected (cross-channel guard).
        $attempt = new OtpAttempt(
            verificationId: 'em-abc',
            phone: '+971501234567',
            purpose: OtpAttempt::PURPOSE_REGISTRATION,
            expiresAt: (new DateTimeImmutable())->modify('+5 minutes'),
            channel: OtpAttempt::CHANNEL_EMAIL,
            codeHash: password_hash('000000', PASSWORD_BCRYPT),
            email: 'a@example.com',
        );
        $this->bindAttempt($attempt);

        $response = $this->handle($this->jsonRequest('POST', '/v3/auth/register/verify-phone', [
            'verification_id' => 'em-abc',
            'code' => '000000',
        ]));

        self::assertSame(401, $response->getStatusCode());
    }
}
