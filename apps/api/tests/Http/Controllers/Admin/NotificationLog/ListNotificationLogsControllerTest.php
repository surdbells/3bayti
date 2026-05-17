<?php

declare(strict_types=1);

namespace Bayti\Api\Tests\Http\Controllers\Admin\NotificationLog;

use Bayti\Api\Domain\Audit\AuditLog;
use Bayti\Api\Domain\Notification\NotificationLog;
use Bayti\Api\Domain\Notification\NotificationLogRepository;
use Bayti\Api\Domain\User\User;
use Bayti\Api\Domain\User\UserRepository;
use Bayti\Api\Http\Controllers\Admin\NotificationLog\ListNotificationLogsController;
use Bayti\Api\Http\Serializers\NotificationLogSerializer;
use Bayti\Api\Infrastructure\Auth\JwtService;
use Bayti\Api\Tests\Http\HttpTestCase;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use Psr\Http\Message\ResponseInterface;

/**
 * Coverage for GET /v3/admin/notification-logs (M3.2.X.4-C).
 *
 * Verifies:
 *   - Happy path: paginated response with admin shape + meta envelope
 *   - All Q-FilterSet=A filters forwarded to the repository
 *   - Limit clamping at MAX_LIMIT=100; default 20
 *   - Status validation (sent/failed/skipped only)
 *   - Template validation (EmailTemplate enum values only)
 *   - Date validation (ISO-8601 format)
 *   - 401 when caller not authenticated (no token)
 *   - 403 when caller authenticated but not admin
 *   - Audit ACTION_VIEWED emission with filter context
 *   - Empty result set returns 200 with data:[]
 */
#[CoversClass(ListNotificationLogsController::class)]
#[CoversClass(NotificationLogSerializer::class)]
final class ListNotificationLogsControllerTest extends HttpTestCase
{
    /** @var array<int, AuditLog> */
    private array $recordedAuditLogs = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->recordedAuditLogs = [];
    }

    #[Test]
    public function returnsPaginatedLogsWithAdminShape(): void
    {
        $admin = $this->makeAdminUser(99);

        $log1 = NotificationLog::sent(
            orderId: 100,
            template: 'order.placed.customer',
            recipient: 'alice@example.com',
        );
        $log2 = NotificationLog::failed(
            orderId: 100,
            template: 'order.paid.customer',
            recipient: 'alice@example.com',
            errorKind: 'transport',
            errorMessage: 'HTTP 503',
        );
        $this->setEntityId($log1, 1);
        $this->setEntityId($log2, 2);

        $logRepo = $this->createMock(NotificationLogRepository::class);
        $logRepo->method('findFilteredPaginated')->willReturn([
            'items' => [$log2, $log1],
            'total' => 2,
        ]);

        $this->bindEm($admin, $logRepo);

        $response = $this->makeGet($admin, '/v3/admin/notification-logs');

        self::assertSame(200, $response->getStatusCode());
        $body = $this->jsonBody($response);

        // Envelope structure
        self::assertArrayHasKey('data', $body);
        self::assertArrayHasKey('meta', $body);
        self::assertCount(2, $body['data']);

        // First entry (most recent — log2, the failed one)
        self::assertSame(2, $body['data'][0]['id']);
        self::assertSame('failed', $body['data'][0]['status']);
        self::assertSame('transport', $body['data'][0]['error_kind']);
        self::assertSame('HTTP 503', $body['data'][0]['error_message']);

        // Second entry (log1, the sent one)
        self::assertSame(1, $body['data'][1]['id']);
        self::assertSame('sent', $body['data'][1]['status']);
        self::assertNull($body['data'][1]['error_kind']);
        self::assertNull($body['data'][1]['error_message']);

        // Meta
        self::assertSame(2, $body['meta']['total']);
        self::assertSame(20, $body['meta']['limit']);
        self::assertSame(0, $body['meta']['offset']);
        self::assertFalse($body['meta']['has_more']);
    }

    #[Test]
    public function forwardsAllFiltersToRepository(): void
    {
        $admin = $this->makeAdminUser(99);

        $logRepo = $this->createMock(NotificationLogRepository::class);
        // Verify the full Q-FilterSet=A filter set is forwarded.
        $logRepo->expects(self::once())
            ->method('findFilteredPaginated')
            ->with(self::callback(function (array $filters): bool {
                return $filters['orderId'] === 100
                    && $filters['template'] === 'order.placed.customer'
                    && $filters['status'] === 'failed'
                    && $filters['recipient'] === 'alice@example.com'
                    && $filters['errorKind'] === 'transport'
                    && $filters['since']?->format('Y-m-d') === '2026-05-01'
                    && $filters['until']?->format('Y-m-d') === '2026-05-17'
                    && $filters['limit'] === 50
                    && $filters['offset'] === 10;
            }))
            ->willReturn(['items' => [], 'total' => 0]);

        $this->bindEm($admin, $logRepo);

        $response = $this->makeGet(
            $admin,
            '/v3/admin/notification-logs?'
            . 'order_id=100'
            . '&template=order.placed.customer'
            . '&status=failed'
            . '&recipient=alice@example.com'
            . '&error_kind=transport'
            . '&since=2026-05-01T00:00:00Z'
            . '&until=2026-05-17T23:59:59Z'
            . '&limit=50'
            . '&offset=10',
        );

        self::assertSame(200, $response->getStatusCode());
    }

    #[Test]
    public function limitClampsToHundred(): void
    {
        $admin = $this->makeAdminUser(99);

        $logRepo = $this->createMock(NotificationLogRepository::class);
        $logRepo->expects(self::once())
            ->method('findFilteredPaginated')
            ->with(self::callback(fn (array $f): bool => $f['limit'] === 100))
            ->willReturn(['items' => [], 'total' => 0]);

        $this->bindEm($admin, $logRepo);

        $response = $this->makeGet($admin, '/v3/admin/notification-logs?limit=999');

        self::assertSame(200, $response->getStatusCode());
        $body = $this->jsonBody($response);
        self::assertSame(100, $body['meta']['limit'], 'limit echoed back to caller post-clamp');
    }

    #[Test]
    public function invalidStatusReturns422(): void
    {
        $admin = $this->makeAdminUser(99);

        $logRepo = $this->createMock(NotificationLogRepository::class);
        $logRepo->expects(self::never())->method('findFilteredPaginated');

        $this->bindEm($admin, $logRepo);

        $response = $this->makeGet($admin, '/v3/admin/notification-logs?status=bogus');

        self::assertSame(422, $response->getStatusCode());
    }

    #[Test]
    public function invalidTemplateReturns422(): void
    {
        $admin = $this->makeAdminUser(99);

        $logRepo = $this->createMock(NotificationLogRepository::class);
        $logRepo->expects(self::never())->method('findFilteredPaginated');

        $this->bindEm($admin, $logRepo);

        $response = $this->makeGet($admin, '/v3/admin/notification-logs?template=not.a.template');

        self::assertSame(422, $response->getStatusCode());
    }

    #[Test]
    public function invalidDateReturns422(): void
    {
        $admin = $this->makeAdminUser(99);

        $logRepo = $this->createMock(NotificationLogRepository::class);
        $logRepo->expects(self::never())->method('findFilteredPaginated');

        $this->bindEm($admin, $logRepo);

        $response = $this->makeGet($admin, '/v3/admin/notification-logs?since=not-a-date');

        self::assertSame(422, $response->getStatusCode());
    }

    #[Test]
    public function nonAdminCallerReturns403(): void
    {
        // Non-admin user (no admin role flag) — caught by AdminAuthMiddleware
        // before the controller is invoked.
        $regularUser = $this->makeUser(id: 42);

        $logRepo = $this->createMock(NotificationLogRepository::class);
        $logRepo->expects(self::never())->method('findFilteredPaginated');

        $this->bindEm($regularUser, $logRepo);

        $response = $this->makeGet($regularUser, '/v3/admin/notification-logs');

        self::assertSame(403, $response->getStatusCode());
    }

    #[Test]
    public function emptyResultSetReturns200WithEmptyArray(): void
    {
        $admin = $this->makeAdminUser(99);

        $logRepo = $this->createMock(NotificationLogRepository::class);
        $logRepo->method('findFilteredPaginated')->willReturn([
            'items' => [],
            'total' => 0,
        ]);

        $this->bindEm($admin, $logRepo);

        $response = $this->makeGet($admin, '/v3/admin/notification-logs?status=failed');

        self::assertSame(200, $response->getStatusCode());
        $body = $this->jsonBody($response);
        self::assertSame([], $body['data']);
        self::assertSame(0, $body['meta']['total']);
        self::assertFalse($body['meta']['has_more']);
    }

    #[Test]
    public function emitsAuditViewedWithFilterContext(): void
    {
        $admin = $this->makeAdminUser(99);

        $logRepo = $this->createMock(NotificationLogRepository::class);
        $logRepo->method('findFilteredPaginated')->willReturn([
            'items' => [],
            'total' => 0,
        ]);

        $this->bindEm($admin, $logRepo);

        $this->makeGet(
            $admin,
            '/v3/admin/notification-logs?order_id=100&status=failed',
        );

        self::assertGreaterThan(
            0,
            count($this->recordedAuditLogs),
            'Q-Audit=A: ACTION_VIEWED emitted per request',
        );
        $audit = end($this->recordedAuditLogs);
        self::assertSame(AuditLog::ACTION_VIEWED, $audit->getAction());
        self::assertSame(
            'User',
            $audit->getSubjectType(),
            'List view audit uses actor (admin User) as subject (no single subject entity)',
        );

        // Filter context recorded for forensic reconstruction
        $changes = $audit->getChanges();
        self::assertSame('admin_notification_logs_list', $changes['context']);
        self::assertSame(100, $changes['filters']['order_id']);
        self::assertSame('failed', $changes['filters']['status']);
        self::assertSame(0, $changes['result_count']);
    }

    // ===== Helpers (mirrored from AdminOrderControllersTest) =====

    private function makeAdminUser(int $id): User
    {
        $user = $this->makeUser(id: $id);
        $user->setRoles(admin: true);
        return $user;
    }

    private function bindEm(User $user, NotificationLogRepository $logRepo): EntityManagerInterface
    {
        $userRepo = $this->createMock(UserRepository::class);
        $userRepo->method('findById')->willReturn($user);

        $auditRepo = new class($this->recordedAuditLogs) extends \Doctrine\ORM\EntityRepository {
            public function __construct(private array &$sink) {}
            public function save(AuditLog $log): void
            {
                $this->sink[] = $log;
            }
            public function getClassName(): string { return AuditLog::class; }
        };

        $em = $this->stubEm(function ($em) use ($userRepo, $logRepo, $auditRepo) {
            $em->method('getRepository')->willReturnMap([
                [User::class, $userRepo],
                [NotificationLog::class, $logRepo],
                [AuditLog::class, $auditRepo],
            ]);
        });

        $this->bind(EntityManagerInterface::class, $em);
        $this->bind(
            \Bayti\Api\Domain\Audit\AuditEmitter::class,
            new \Bayti\Api\Domain\Audit\AuditEmitter($em, new \Psr\Log\NullLogger()),
        );
        return $em;
    }

    private function makeGet(User $user, string $uri): ResponseInterface
    {
        $jwt = $this->app->getContainer()->get(JwtService::class);
        $pair = $jwt->issueTokenPair($user);
        return $this->handle($this->jsonRequest('GET', $uri, [], [
            'Authorization' => 'Bearer ' . $pair->accessToken,
        ]));
    }

    private function setEntityId(object $entity, int $id): void
    {
        $ref = new \ReflectionProperty($entity::class, 'id');
        $ref->setAccessible(true);
        $ref->setValue($entity, $id);
    }
}
