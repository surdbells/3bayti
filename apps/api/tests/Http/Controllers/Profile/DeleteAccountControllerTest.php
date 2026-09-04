<?php

declare(strict_types=1);

namespace Bayti\Api\Tests\Http\Controllers\Profile;

use Bayti\Api\Domain\Audit\AuditEmitter;
use Bayti\Api\Domain\Audit\AuditLog;
use Bayti\Api\Domain\User\HardDeleteUserService;
use Bayti\Api\Domain\User\User;
use Bayti\Api\Domain\User\UserRepository;
use Bayti\Api\Http\Controllers\Profile\DeleteAccountController;
use Bayti\Api\Http\Controllers\Profile\Dto\DeleteAccountInput;
use Bayti\Api\Infrastructure\Auth\JwtService;
use Bayti\Api\Tests\Http\HttpTestCase;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;

/**
 * DELETE /v3/me — the customer's own PERMANENT account deletion.
 *
 * Re-auth (current_password) is checked, then the account + all its data is
 * erased via HardDeleteUserService. The mocked-EM harness has no real DB, so
 * the actual cascade SQL is verified against the real schema (see that class);
 * here we assert the re-auth gate and that a valid delete invokes the erase
 * service + audits it.
 */
#[CoversClass(DeleteAccountController::class)]
#[CoversClass(DeleteAccountInput::class)]
final class DeleteAccountControllerTest extends HttpTestCase
{
    /** @var array<int, AuditLog> */
    private array $recordedAuditLogs = [];
    /** @var array<int, int> ids the erase service was asked to remove */
    private array $erasedUserIds = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->recordedAuditLogs = [];
        $this->erasedUserIds = [];
    }

    #[Test]
    public function permanentlyDeletesTheAccountOnValidPassword(): void
    {
        $user = $this->makeUser(id: 200, passwordPlain: 'MyPass123');
        $this->bindEm($user);

        $response = $this->deleteMe($user, ['current_password' => 'MyPass123']);

        self::assertSame(204, $response->getStatusCode());
        self::assertSame('', (string) $response->getBody());
        self::assertSame([200], $this->erasedUserIds, 'account should be hard-deleted');
        self::assertCount(1, $this->recordedAuditLogs, 'deletion should be audited');
    }

    #[Test]
    public function returns401WhenCurrentPasswordIsWrong(): void
    {
        $user = $this->makeUser(id: 201, passwordPlain: 'MyPass123');
        $this->bindEm($user);

        $response = $this->deleteMe($user, ['current_password' => 'WrongPass999']);

        self::assertSame(401, $response->getStatusCode());
        self::assertSame('AUTH_INVALID_CREDENTIALS', $this->jsonBody($response)['error']['code']);
        // Nothing was erased on a failed re-auth.
        self::assertSame([], $this->erasedUserIds);
    }

    #[Test]
    public function returns422WhenCurrentPasswordMissing(): void
    {
        $user = $this->makeUser(id: 202, passwordPlain: 'MyPass123');
        $this->bindEm($user);

        $response = $this->deleteMe($user, []);

        self::assertSame(422, $response->getStatusCode());
        self::assertSame('VALIDATION_FAILED', $this->jsonBody($response)['error']['code']);
        self::assertSame([], $this->erasedUserIds);
    }

    #[Test]
    public function returns401WhenUnauthenticated(): void
    {
        $response = $this->handle(
            $this->jsonRequest('DELETE', '/v3/me', ['current_password' => 'MyPass123']),
        );

        self::assertSame(401, $response->getStatusCode());
    }

    // ===================== Helpers =====================

    private function bindEm(User $user): void
    {
        $userRepo = $this->createMock(UserRepository::class);
        $userRepo->method('findById')->willReturn($user);

        $auditSink = &$this->recordedAuditLogs;
        $auditRepo = new class($auditSink) extends \Doctrine\ORM\EntityRepository {
            /** @param array<int, AuditLog> $sink */
            public function __construct(private array &$sink)
            {
            }
            public function save(AuditLog $log): void
            {
                $this->sink[] = $log;
            }
            public function getClassName(): string
            {
                return AuditLog::class;
            }
        };

        $em = $this->stubEm(function ($em) use ($userRepo, $auditRepo) {
            $em->method('getRepository')->willReturnMap([
                [User::class, $userRepo],
                [AuditLog::class, $auditRepo],
            ]);
            $em->method('persist');
            $em->method('flush');
        });
        $this->bind(EntityManagerInterface::class, $em);
        $this->bind(AuditEmitter::class, new AuditEmitter($em, new \Psr\Log\NullLogger()));

        // Spy erase service: record the id, never hit the (absent) DB.
        $erasedSink = &$this->erasedUserIds;
        $this->bind(HardDeleteUserService::class, new class($em, $erasedSink) extends HardDeleteUserService {
            /** @param array<int, int> $sink */
            public function __construct(EntityManagerInterface $em, private array &$sink)
            {
                parent::__construct($em);
            }
            public function delete(User $user): void
            {
                $this->sink[] = (int) $user->getId();
            }
        });
    }

    /** @param array<string, mixed> $body */
    private function deleteMe(User $user, array $body): \Psr\Http\Message\ResponseInterface
    {
        $jwt = $this->app->getContainer()->get(JwtService::class);
        $pair = $jwt->issueTokenPair($user);
        return $this->handle($this->jsonRequest('DELETE', '/v3/me', $body, [
            'Authorization' => 'Bearer ' . $pair->accessToken,
        ]));
    }
}
