<?php

declare(strict_types=1);

namespace Bayti\Api\Tests\Http\Controllers\Auth;

use Bayti\Api\Domain\User\OtpAttempt;
use Bayti\Api\Domain\User\User;
use Bayti\Api\Domain\User\UserRepository;
use Bayti\Api\Http\Controllers\Auth\Dto\RegisterSubmitInput;
use Bayti\Api\Http\Controllers\Auth\RegisterSubmitController;
use Bayti\Api\Http\Errors\ErrorCodes;
use Bayti\Api\Infrastructure\Auth\JwtService;
use Bayti\Api\Tests\Http\HttpTestCase;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;

#[CoversClass(RegisterSubmitController::class)]
#[CoversClass(RegisterSubmitInput::class)]
final class RegisterSubmitControllerTest extends HttpTestCase
{
    private function registrationToken(string $phone = '+971501234567'): string
    {
        $jwt = $this->app->getContainer()->get(JwtService::class);
        return $jwt->issueRegistrationToken($phone, 'AE');
    }

    #[Test]
    public function happyPathCreatesUserAndSendsEmailOtp(): void
    {
        $persistedUsers = [];
        $persistedOtps = [];

        $userRepo = $this->createMock(UserRepository::class);
        $userRepo->method('isEmailAvailable')->willReturn(true);
        $userRepo->method('isPhoneAvailable')->willReturn(true);
        $userRepo->method('save')->willReturnCallback(function (User $u) use (&$persistedUsers) {
            $persistedUsers[] = $u;
            $ref = new \ReflectionProperty(User::class, 'id');
            $ref->setAccessible(true);
            $ref->setValue($u, 7);
        });

        $otpRepo = $this->createMock(\Bayti\Api\Domain\User\OtpAttemptRepository::class);
        $otpRepo->method('save')->willReturnCallback(function (OtpAttempt $a) use (&$persistedOtps) {
            $persistedOtps[] = $a;
        });
        $otpRepo->method('countRecentSendsForPhone')->willReturn(0);

        $em = $this->stubEm(fn ($em) =>
            $em->method('getRepository')->willReturnMap([
                [User::class, $userRepo],
                [OtpAttempt::class, $otpRepo],
            ]));
        $this->bind(EntityManagerInterface::class, $em);

        $response = $this->handle($this->jsonRequest('POST', '/v3/auth/register/submit', [
            'registration_token' => $this->registrationToken('+971501234567'),
            'email' => 'newuser@example.com',
            'password' => 'p4ssword!',
            'first_name' => 'Alice',
        ]));

        self::assertSame(200, $response->getStatusCode());
        $body = $this->jsonBody($response);
        self::assertNotEmpty($body['verification_id']);
        // Email-OTP verification ids carry the 'em-' prefix.
        self::assertStringStartsWith('em-', $body['verification_id']);

        // User created: phone from token + verified, email NOT verified.
        self::assertCount(1, $persistedUsers);
        $user = $persistedUsers[0];
        self::assertSame('+971501234567', $user->getPhone());
        self::assertTrue($user->isPhoneVerified());
        self::assertFalse($user->isEmailVerified());
        self::assertTrue(password_verify('p4ssword!', $user->getPasswordHash()));

        // Email-channel OTP row persisted with a code hash + email set.
        self::assertCount(1, $persistedOtps);
        $otp = $persistedOtps[0];
        self::assertTrue($otp->isEmailChannel());
        self::assertSame('newuser@example.com', $otp->getEmail());
        self::assertNotNull($otp->getCodeHash());
    }

    #[Test]
    public function returns401ForBadRegistrationToken(): void
    {
        $response = $this->handle($this->jsonRequest('POST', '/v3/auth/register/submit', [
            'registration_token' => 'not-a-real-token',
            'email' => 'newuser@example.com',
            'password' => 'p4ssword!',
        ]));

        self::assertSame(401, $response->getStatusCode());
        self::assertSame(
            ErrorCodes::AUTH_INVALID_TOKEN,
            $this->jsonBody($response)['error']['code'],
        );
    }

    #[Test]
    public function returns409WhenEmailTaken(): void
    {
        $userRepo = $this->createMock(UserRepository::class);
        $userRepo->method('isEmailAvailable')->willReturn(false);
        $userRepo->method('isPhoneAvailable')->willReturn(true);

        $em = $this->stubEm(fn ($em) =>
            $em->method('getRepository')->with(User::class)->willReturn($userRepo));
        $this->bind(EntityManagerInterface::class, $em);

        $response = $this->handle($this->jsonRequest('POST', '/v3/auth/register/submit', [
            'registration_token' => $this->registrationToken(),
            'email' => 'taken@example.com',
            'password' => 'p4ssword!',
        ]));

        self::assertSame(409, $response->getStatusCode());
        self::assertSame(
            ErrorCodes::CONFLICT_EMAIL_TAKEN,
            $this->jsonBody($response)['error']['code'],
        );
    }

    #[Test]
    public function returns422OnShortPassword(): void
    {
        $response = $this->handle($this->jsonRequest('POST', '/v3/auth/register/submit', [
            'registration_token' => $this->registrationToken(),
            'email' => 'user@example.com',
            'password' => 'short',
        ]));

        self::assertSame(422, $response->getStatusCode());
        self::assertArrayHasKey('password', $this->jsonBody($response)['error']['details']['fields']);
    }
}
