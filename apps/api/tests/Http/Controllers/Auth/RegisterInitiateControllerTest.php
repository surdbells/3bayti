<?php

declare(strict_types=1);

namespace Bayti\Api\Tests\Http\Controllers\Auth;

use Bayti\Api\Domain\User\OtpAttempt;
use Bayti\Api\Domain\User\User;
use Bayti\Api\Domain\User\UserRepository;
use Bayti\Api\Http\Controllers\Auth\Dto\RegisterInitiateInput;
use Bayti\Api\Http\Controllers\Auth\RegisterInitiateController;
use Bayti\Api\Http\Errors\ErrorCodes;
use Bayti\Api\Infrastructure\Otp\InMemoryOtpProvider;
use Bayti\Api\Infrastructure\Otp\OtpProvider;
use Bayti\Api\Tests\Http\HttpTestCase;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;

#[CoversClass(RegisterInitiateController::class)]
#[CoversClass(RegisterInitiateInput::class)]
final class RegisterInitiateControllerTest extends HttpTestCase
{
    private InMemoryOtpProvider $otpProvider;

    protected function setUp(): void
    {
        parent::setUp();
        $this->otpProvider = new InMemoryOtpProvider();
        $this->bind(OtpProvider::class, $this->otpProvider);
    }

    #[Test]
    public function happyPathSendsSmsOtp(): void
    {
        $userRepo = $this->createMock(UserRepository::class);
        $userRepo->method('isPhoneAvailable')->willReturn(true);

        $otpRepo = $this->createMock(\Bayti\Api\Domain\User\OtpAttemptRepository::class);
        $otpRepo->method('countRecentSendsForPhone')->willReturn(0);

        $em = $this->stubEm(fn ($em) =>
            $em->method('getRepository')->willReturnMap([
                [User::class, $userRepo],
                [OtpAttempt::class, $otpRepo],
            ]));
        $this->bind(EntityManagerInterface::class, $em);

        $response = $this->handle($this->jsonRequest('POST', '/v3/auth/register/initiate', [
            'phone' => '+971501234567',
        ]));

        self::assertSame(200, $response->getStatusCode());
        $body = $this->jsonBody($response);
        self::assertNotEmpty($body['verification_id']);
        self::assertNotEmpty($this->otpProvider->allIssued());
    }

    #[Test]
    public function returns409WhenPhoneTaken(): void
    {
        $userRepo = $this->createMock(UserRepository::class);
        $userRepo->method('isPhoneAvailable')->willReturn(false);

        $em = $this->stubEm(fn ($em) =>
            $em->method('getRepository')->with(User::class)->willReturn($userRepo));
        $this->bind(EntityManagerInterface::class, $em);

        $response = $this->handle($this->jsonRequest('POST', '/v3/auth/register/initiate', [
            'phone' => '+971501234567',
        ]));

        self::assertSame(409, $response->getStatusCode());
        self::assertSame(
            ErrorCodes::CONFLICT_PHONE_TAKEN,
            $this->jsonBody($response)['error']['code'],
        );
        // No OTP should have been sent.
        self::assertEmpty($this->otpProvider->allIssued());
    }

    #[Test]
    public function returns422OnMissingPhone(): void
    {
        $response = $this->handle($this->jsonRequest('POST', '/v3/auth/register/initiate', []));
        self::assertSame(422, $response->getStatusCode());
        self::assertArrayHasKey('phone', $this->jsonBody($response)['error']['details']['fields']);
    }
}
