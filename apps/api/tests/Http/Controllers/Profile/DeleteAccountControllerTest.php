<?php

declare(strict_types=1);

namespace Bayti\Api\Tests\Http\Controllers\Profile;

use Bayti\Api\Domain\User\RefreshToken;
use Bayti\Api\Domain\User\RefreshTokenRepository;
use Bayti\Api\Domain\User\User;
use Bayti\Api\Domain\User\UserRepository;
use Bayti\Api\Http\Controllers\Profile\DeleteAccountController;
use Bayti\Api\Http\Controllers\Profile\Dto\DeleteAccountInput;
use Bayti\Api\Infrastructure\Auth\JwtService;
use Bayti\Api\Tests\Http\HttpTestCase;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;

#[CoversClass(DeleteAccountController::class)]
#[CoversClass(DeleteAccountInput::class)]
final class DeleteAccountControllerTest extends HttpTestCase
{
    // ---------------------------------------------------------------
    // Happy path
    // ---------------------------------------------------------------

    #[Test]
    public function returns204AndDeactivatesPlusSoftDeletesOnSuccess(): void
    {
        $user = $this->makeUser(id: 200, passwordPlain: 'MyPass123');
        self::assertTrue($user->isActive(), 'precondition: active');
        self::assertFalse($user->isDeleted(), 'precondition: not deleted');

        $userRepo = $this->createMock(UserRepository::class);
        $userRepo->method('findById')->willReturn($user);

        $refreshRepo = $this->createMock(RefreshTokenRepository::class);
        $refreshRepo->expects(self::once())
            ->method('revokeAllForUser')
            ->with($user, 'account_deleted');

        $em = $this->stubEm(function ($em) use ($userRepo, $refreshRepo) {
            $em->method('getRepository')->willReturnMap([
                [User::class, $userRepo],
                [RefreshToken::class, $refreshRepo],
            ]);
        });
        $this->bind(EntityManagerInterface::class, $em);

        $jwt = $this->app->getContainer()->get(JwtService::class);
        $pair = $jwt->issueTokenPair($user);

        $response = $this->handle(
            $this->jsonRequest('DELETE', '/v3/me', [
                'current_password' => 'MyPass123',
            ], [
                'Authorization' => 'Bearer ' . $pair->accessToken,
            ])
        );

        self::assertSame(204, $response->getStatusCode());
        self::assertSame('', (string) $response->getBody());

        // Q6.1: BOTH flags set.
        self::assertFalse($user->isActive(), 'account should be deactivated');
        self::assertTrue($user->isDeleted(), 'account should be soft-deleted');
        self::assertNotNull($user->getDeletedAt());
    }

    // ---------------------------------------------------------------
    // Re-auth failures
    // ---------------------------------------------------------------

    #[Test]
    public function returns401WhenCurrentPasswordIsWrong(): void
    {
        $user = $this->makeUser(id: 201, passwordPlain: 'MyPass123');

        $userRepo = $this->createMock(UserRepository::class);
        $userRepo->method('findById')->willReturn($user);

        // Must NOT revoke tokens on a failed re-auth.
        $refreshRepo = $this->createMock(RefreshTokenRepository::class);
        $refreshRepo->expects(self::never())->method('revokeAllForUser');

        $em = $this->stubEm(function ($em) use ($userRepo, $refreshRepo) {
            $em->method('getRepository')->willReturnMap([
                [User::class, $userRepo],
                [RefreshToken::class, $refreshRepo],
            ]);
        });
        $this->bind(EntityManagerInterface::class, $em);

        $jwt = $this->app->getContainer()->get(JwtService::class);
        $pair = $jwt->issueTokenPair($user);

        $response = $this->handle(
            $this->jsonRequest('DELETE', '/v3/me', [
                'current_password' => 'WrongPass999',
            ], [
                'Authorization' => 'Bearer ' . $pair->accessToken,
            ])
        );

        self::assertSame(401, $response->getStatusCode());
        $body = $this->jsonBody($response);
        self::assertSame('AUTH_INVALID_CREDENTIALS', $body['error']['code']);

        // Account untouched.
        self::assertTrue($user->isActive());
        self::assertFalse($user->isDeleted());
    }

    #[Test]
    public function returns422WhenCurrentPasswordMissing(): void
    {
        $user = $this->makeUser(id: 202, passwordPlain: 'MyPass123');

        $userRepo = $this->createMock(UserRepository::class);
        $userRepo->method('findById')->willReturn($user);

        $em = $this->stubEm(function ($em) use ($userRepo) {
            $em->method('getRepository')->willReturnMap([
                [User::class, $userRepo],
            ]);
        });
        $this->bind(EntityManagerInterface::class, $em);

        $jwt = $this->app->getContainer()->get(JwtService::class);
        $pair = $jwt->issueTokenPair($user);

        $response = $this->handle(
            $this->jsonRequest('DELETE', '/v3/me', [], [
                'Authorization' => 'Bearer ' . $pair->accessToken,
            ])
        );

        self::assertSame(422, $response->getStatusCode());
        $body = $this->jsonBody($response);
        self::assertSame('VALIDATION_FAILED', $body['error']['code']);
    }

    // ---------------------------------------------------------------
    // Auth required
    // ---------------------------------------------------------------

    #[Test]
    public function returns401WhenUnauthenticated(): void
    {
        $response = $this->handle(
            $this->jsonRequest('DELETE', '/v3/me', [
                'current_password' => 'MyPass123',
            ])
        );

        self::assertSame(401, $response->getStatusCode());
    }
}
