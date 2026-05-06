<?php

declare(strict_types=1);

namespace Bayti\Api\Tests\Http\Controllers\Auth;

use Bayti\Api\Domain\User\OtpAttempt;
use Bayti\Api\Domain\User\User;
use Bayti\Api\Domain\User\UserRepository;
use Bayti\Api\Http\Controllers\Auth\Dto\RegisterInput;
use Bayti\Api\Http\Controllers\Auth\RegisterController;
use Bayti\Api\Http\Errors\ErrorCodes;
use Bayti\Api\Infrastructure\Otp\InMemoryOtpProvider;
use Bayti\Api\Infrastructure\Otp\OtpProvider;
use Bayti\Api\Tests\Http\HttpTestCase;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;

#[CoversClass(RegisterController::class)]
#[CoversClass(RegisterInput::class)]
final class RegisterControllerTest extends HttpTestCase
{
    private InMemoryOtpProvider $otpProvider;

    protected function setUp(): void
    {
        parent::setUp();
        // Replace the DI's bound OtpProvider with a known instance
        // we can inspect from tests.
        $this->otpProvider = new InMemoryOtpProvider();
        $this->bind(OtpProvider::class, $this->otpProvider);
    }

    // -------------------------------------------------------------------
    // Happy path
    // -------------------------------------------------------------------

    #[Test]
    public function happyPathReturnsVerificationIdAndCreatesUser(): void
    {
        $persistedUsers = [];
        $persistedOtps = [];

        $userRepo = $this->createMock(UserRepository::class);
        $userRepo->method('isEmailAvailable')->willReturn(true);
        $userRepo->method('isPhoneAvailable')->willReturn(true);
        $userRepo->method('save')->willReturnCallback(
            function (User $u) use (&$persistedUsers) {
                $persistedUsers[] = $u;
                // Simulate id assignment after save.
                $ref = new \ReflectionProperty(User::class, 'id');
                $ref->setAccessible(true);
                $ref->setValue($u, 1);
            }
        );

        $otpRepo = $this->createMock(\Bayti\Api\Domain\User\OtpAttemptRepository::class);
        $otpRepo->method('save')->willReturnCallback(
            function (OtpAttempt $a) use (&$persistedOtps) {
                $persistedOtps[] = $a;
            }
        );
        $otpRepo->method('countRecentSendsForPhone')->willReturn(0);

        $em = $this->stubEm(fn ($em) =>
            $em->method('getRepository')->willReturnMap([
                [User::class, $userRepo],
                [OtpAttempt::class, $otpRepo],
            ]));
        $this->bind(EntityManagerInterface::class, $em);

        $response = $this->handle($this->jsonRequest('POST', '/v3/auth/register', [
            'email' => 'newuser@example.com',
            'phone' => '+971501234567',
            'password' => 'p4ssword!',
            'country_code' => 'AE',
            'first_name' => 'Alice',
            'last_name' => 'Smith',
        ]));

        self::assertSame(201, $response->getStatusCode());

        $body = $this->jsonBody($response);
        self::assertNotEmpty($body['verification_id']);
        self::assertSame('newuser@example.com', $body['user']['email']);
        self::assertSame('+971501234567', $body['user']['phone']);
        self::assertFalse($body['user']['is_phone_verified']);

        // Verify the side effects: user persisted, OTP provider hit, OTP audit row written.
        self::assertCount(1, $persistedUsers);
        self::assertCount(1, $persistedOtps);
        self::assertSame('Alice', $persistedUsers[0]->getFirstName());
        self::assertSame('Smith', $persistedUsers[0]->getLastName());
        self::assertSame('+971501234567', $persistedUsers[0]->getPhone());
        self::assertFalse($persistedUsers[0]->isPhoneVerified());
        // Password should be hashed, NOT stored in plain text.
        self::assertNotSame('p4ssword!', $persistedUsers[0]->getPasswordHash());
        self::assertTrue(password_verify('p4ssword!', $persistedUsers[0]->getPasswordHash()));
    }

    #[Test]
    public function happyPathWithoutOptionalNames(): void
    {
        $userRepo = $this->createMock(UserRepository::class);
        $userRepo->method('isEmailAvailable')->willReturn(true);
        $userRepo->method('isPhoneAvailable')->willReturn(true);
        $userRepo->method('save')->willReturnCallback(function (User $u) {
            $ref = new \ReflectionProperty(User::class, 'id');
            $ref->setAccessible(true);
            $ref->setValue($u, 1);
        });

        $otpRepo = $this->createMock(\Bayti\Api\Domain\User\OtpAttemptRepository::class);
        $otpRepo->method('countRecentSendsForPhone')->willReturn(0);

        $em = $this->stubEm(fn ($em) =>
            $em->method('getRepository')->willReturnMap([
                [User::class, $userRepo],
                [OtpAttempt::class, $otpRepo],
            ]));
        $this->bind(EntityManagerInterface::class, $em);

        $response = $this->handle($this->jsonRequest('POST', '/v3/auth/register', [
            'email' => 'minimal@example.com',
            'phone' => '+971507777777',
            'password' => 'p4ssword!',
        ]));

        self::assertSame(201, $response->getStatusCode());
    }

    // -------------------------------------------------------------------
    // Conflict cases
    // -------------------------------------------------------------------

    #[Test]
    public function returns409WhenEmailTaken(): void
    {
        $userRepo = $this->createMock(UserRepository::class);
        $userRepo->method('isEmailAvailable')->willReturn(false);
        $userRepo->method('isPhoneAvailable')->willReturn(true);

        $em = $this->stubEm(fn ($em) =>
            $em->method('getRepository')->with(User::class)->willReturn($userRepo));
        $this->bind(EntityManagerInterface::class, $em);

        $response = $this->handle($this->jsonRequest('POST', '/v3/auth/register', [
            'email' => 'taken@example.com',
            'phone' => '+971501234567',
            'password' => 'p4ssword!',
        ]));

        self::assertSame(409, $response->getStatusCode());
        $body = $this->jsonBody($response);
        self::assertSame(ErrorCodes::CONFLICT_EMAIL_TAKEN, $body['error']['code']);
    }

    #[Test]
    public function returns409WhenPhoneTaken(): void
    {
        $userRepo = $this->createMock(UserRepository::class);
        $userRepo->method('isEmailAvailable')->willReturn(true);
        $userRepo->method('isPhoneAvailable')->willReturn(false);

        $em = $this->stubEm(fn ($em) =>
            $em->method('getRepository')->with(User::class)->willReturn($userRepo));
        $this->bind(EntityManagerInterface::class, $em);

        $response = $this->handle($this->jsonRequest('POST', '/v3/auth/register', [
            'email' => 'newuser@example.com',
            'phone' => '+971501234567',
            'password' => 'p4ssword!',
        ]));

        self::assertSame(409, $response->getStatusCode());
        self::assertSame(
            ErrorCodes::CONFLICT_PHONE_TAKEN,
            $this->jsonBody($response)['error']['code'],
        );
    }

    // -------------------------------------------------------------------
    // Validation
    // -------------------------------------------------------------------

    #[Test]
    public function returns422OnEmptyBody(): void
    {
        $response = $this->handle($this->jsonRequest('POST', '/v3/auth/register', []));
        self::assertSame(422, $response->getStatusCode());

        $fields = $this->jsonBody($response)['error']['details']['fields'];
        self::assertArrayHasKey('email', $fields);
        self::assertArrayHasKey('phone', $fields);
        self::assertArrayHasKey('password', $fields);
    }

    #[Test]
    public function returns422OnShortPassword(): void
    {
        $response = $this->handle($this->jsonRequest('POST', '/v3/auth/register', [
            'email' => 'user@example.com',
            'phone' => '+971501234567',
            'password' => 'short', // only 5 chars
        ]));

        self::assertSame(422, $response->getStatusCode());
        self::assertArrayHasKey(
            'password',
            $this->jsonBody($response)['error']['details']['fields'],
        );
    }

    #[Test]
    public function returns422OnLowercaseCountryCode(): void
    {
        // Constructor uppercases, so 'ae' becomes 'AE' which passes
        // the regex. Test explicit 2-letter requirement: 'XYZ' is too long.
        $response = $this->handle($this->jsonRequest('POST', '/v3/auth/register', [
            'email' => 'user@example.com',
            'phone' => '+971501234567',
            'password' => 'p4ssword!',
            'country_code' => 'XYZ',
        ]));

        self::assertSame(422, $response->getStatusCode());
    }
}
