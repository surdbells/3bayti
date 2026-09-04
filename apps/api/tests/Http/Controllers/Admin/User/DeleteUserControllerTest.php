<?php

declare(strict_types=1);

namespace Bayti\Api\Tests\Http\Controllers\Admin\User;

use Bayti\Api\Domain\Audit\AuditEmitter;
use Bayti\Api\Domain\Audit\AuditLog;
use Bayti\Api\Domain\User\HardDeleteUserService;
use Bayti\Api\Domain\User\User;
use Bayti\Api\Domain\User\UserRepository;
use Bayti\Api\Infrastructure\Auth\JwtService;
use Bayti\Api\Tests\Http\HttpTestCase;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\Test;
use Psr\Http\Message\ResponseInterface;

/**
 * DELETE /v3/admin/users/{id} — permanent (hard) delete of a customer account.
 *
 * The mocked-EM harness has no real PostgreSQL, so the actual cascade SQL in
 * HardDeleteUserService is verified against the real schema (see that class's
 * FK rationale), not here. These tests cover the controller's authorization +
 * guard logic: only a permitted admin, never yourself, never a staff/vendor
 * account, 404 for a missing customer — and that a permitted delete invokes the
 * erase service + records the audit event.
 */
final class DeleteUserControllerTest extends HttpTestCase
{
    /** @var array<int, AuditLog> */
    private array $recordedAuditLogs = [];
    /** @var array<int, int> user ids the hard-delete service was asked to erase */
    private array $erasedUserIds = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->recordedAuditLogs = [];
        $this->erasedUserIds = [];
    }

    #[Test]
    public function permanentlyDeletesACustomerAccount(): void
    {
        $admin = $this->makeAdminUser(99);
        $customer = $this->makeUser(id: 42, email: 'gone@example.com');

        $this->bindEm($admin, $customer);
        $response = $this->makeDelete($admin, '/v3/admin/users/42');

        self::assertSame(204, $response->getStatusCode(), (string) $response->getBody());
        // The erase service was asked to remove exactly the target customer.
        self::assertSame([42], $this->erasedUserIds);
        // And the removal was audited.
        self::assertCount(1, $this->recordedAuditLogs);
    }

    #[Test]
    public function refusesToDeleteYourOwnAccount(): void
    {
        $admin = $this->makeAdminUser(99);

        $this->bindEm($admin, $admin);
        $response = $this->makeDelete($admin, '/v3/admin/users/99');

        self::assertSame(422, $response->getStatusCode());
        self::assertSame([], $this->erasedUserIds);
    }

    #[Test]
    public function refusesToDeleteAStaffAccount(): void
    {
        $admin = $this->makeAdminUser(99);
        $staff = $this->makeUser(id: 50);
        $staff->setRoles(support: true); // isStaff() === true

        $this->bindEm($admin, $staff);
        $response = $this->makeDelete($admin, '/v3/admin/users/50');

        self::assertSame(422, $response->getStatusCode());
        self::assertSame([], $this->erasedUserIds);
    }

    #[Test]
    public function refusesToDeleteAVendorAccount(): void
    {
        $admin = $this->makeAdminUser(99);
        $vendor = $this->makeUser(id: 60);
        $vendor->setRoles(vendor: true);

        $this->bindEm($admin, $vendor);
        $response = $this->makeDelete($admin, '/v3/admin/users/60');

        self::assertSame(422, $response->getStatusCode());
        self::assertSame([], $this->erasedUserIds);
    }

    #[Test]
    public function returns404WhenTheCustomerDoesNotExist(): void
    {
        $admin = $this->makeAdminUser(99);

        $this->bindEm($admin, null);
        $response = $this->makeDelete($admin, '/v3/admin/users/12345');

        self::assertSame(404, $response->getStatusCode());
        self::assertSame([], $this->erasedUserIds);
    }

    #[Test]
    public function requiresTheUsersDeletePermission(): void
    {
        // A regular signed-in customer holds no admin permissions.
        $regular = $this->makeUser(id: 7);
        $this->bindEm($regular, $this->makeUser(id: 8));

        self::assertSame(403, $this->makeDelete($regular, '/v3/admin/users/8')->getStatusCode());
        self::assertSame([], $this->erasedUserIds);
    }

    #[Test]
    public function requiresAuthentication(): void
    {
        $response = $this->handle($this->jsonRequest('DELETE', '/v3/admin/users/8'));
        self::assertSame(401, $response->getStatusCode());
    }

    // ===================== Helpers =====================

    private function makeAdminUser(int $id): User
    {
        $user = $this->makeUser(id: $id);
        $user->setRoles(admin: true);
        return $user;
    }

    /**
     * Bind an EM whose user lookup is id-aware (actor vs target), a spy
     * hard-delete service that records the erased id instead of touching a DB,
     * and a real AuditEmitter sinking to $recordedAuditLogs.
     */
    private function bindEm(User $actor, ?User $target): EntityManagerInterface
    {
        $userRepo = $this->createMock(UserRepository::class);
        $userRepo->method('findById')->willReturnCallback(
            function (int $id) use ($actor, $target): ?User {
                if ($id === $actor->getId()) {
                    return $actor;
                }
                if ($target !== null && $id === $target->getId()) {
                    return $target;
                }
                return null;
            },
        );

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

        return $em;
    }

    private function makeDelete(User $user, string $uri): ResponseInterface
    {
        return $this->handle($this->jsonRequest('DELETE', $uri, [], $this->headers($user)));
    }

    /** @return array<string,string> */
    private function headers(User $user): array
    {
        $jwt = $this->app->getContainer()->get(JwtService::class);
        $pair = $jwt->issueTokenPair($user);
        return ['Authorization' => 'Bearer ' . $pair->accessToken];
    }
}
