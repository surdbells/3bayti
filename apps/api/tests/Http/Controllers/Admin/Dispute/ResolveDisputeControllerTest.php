<?php

declare(strict_types=1);

namespace Bayti\Api\Tests\Http\Controllers\Admin\Dispute;

use Bayti\Api\Domain\Audit\AuditEmitter;
use Bayti\Api\Domain\Audit\AuditLog;
use Bayti\Api\Domain\Order\OrderDispute;
use Bayti\Api\Domain\User\User;
use Bayti\Api\Domain\User\UserRepository;
use Bayti\Api\Http\Controllers\Admin\Dispute\ResolveDisputeController;
use Bayti\Api\Infrastructure\Auth\JwtService;
use Bayti\Api\Tests\Http\HttpTestCase;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use Psr\Http\Message\ResponseInterface;
use Psr\Log\NullLogger;

#[CoversClass(ResolveDisputeController::class)]
final class ResolveDisputeControllerTest extends HttpTestCase
{
    /** @var array<int, AuditLog> */
    private array $recordedAudits = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->recordedAudits = [];
    }

    #[Test]
    public function transitionsToInReview(): void
    {
        $admin = $this->makeAdminUser(99);
        $dispute = $this->makeDispute(id: 42, status: OrderDispute::STATUS_OPEN);
        $this->bindEnv($admin, $dispute);

        $response = $this->makePatch($admin, '/v3/admin/disputes/42', [
            'status' => OrderDispute::STATUS_IN_REVIEW,
        ]);
        self::assertSame(200, $response->getStatusCode());

        $body = $this->jsonBody($response);
        self::assertSame(OrderDispute::STATUS_IN_REVIEW, $body['dispute']['status']);
        self::assertSame(OrderDispute::STATUS_IN_REVIEW, $dispute->getStatus());

        // ACTION_OVERRIDDEN audit emitted
        self::assertCount(1, $this->recordedAudits);
        $audit = $this->recordedAudits[0];
        self::assertSame(AuditLog::ACTION_OVERRIDDEN, $audit->getAction());
        $changes = $audit->getChanges();
        self::assertSame(OrderDispute::STATUS_OPEN, $changes['before']['status']);
        self::assertSame(OrderDispute::STATUS_IN_REVIEW, $changes['after']['status']);
    }

    #[Test]
    public function resolveWonRequiresNote(): void
    {
        $admin = $this->makeAdminUser(99);
        $dispute = $this->makeDispute(id: 42, status: OrderDispute::STATUS_IN_REVIEW);
        $this->bindEnv($admin, $dispute);

        $response = $this->makePatch($admin, '/v3/admin/disputes/42', [
            'status' => OrderDispute::STATUS_RESOLVED_WON,
            // missing resolution_note
        ]);
        self::assertSame(422, $response->getStatusCode());
        $body = $this->jsonBody($response);
        self::assertSame('resolution_note_required', $body['error']['code']);

        // Dispute unchanged
        self::assertSame(OrderDispute::STATUS_IN_REVIEW, $dispute->getStatus());
    }

    #[Test]
    public function resolveWonWithNoteSucceeds(): void
    {
        $admin = $this->makeAdminUser(99);
        $dispute = $this->makeDispute(id: 42, status: OrderDispute::STATUS_IN_REVIEW);
        $this->bindEnv($admin, $dispute);

        $response = $this->makePatch($admin, '/v3/admin/disputes/42', [
            'status' => OrderDispute::STATUS_RESOLVED_WON,
            'resolution_note' => 'Evidence submitted; bank ruled in our favor.',
        ]);
        self::assertSame(200, $response->getStatusCode());

        self::assertSame(OrderDispute::STATUS_RESOLVED_WON, $dispute->getStatus());
        self::assertSame(99, $dispute->getResolvedByUserId());
        self::assertNotNull($dispute->getResolvedAt());
        self::assertStringContainsString('Evidence', $dispute->getResolutionNote() ?? '');
    }

    #[Test]
    public function rejectsResolutionOnAlreadyTerminalDispute(): void
    {
        $admin = $this->makeAdminUser(99);
        $dispute = $this->makeDispute(id: 42, status: OrderDispute::STATUS_RESOLVED_WON);
        $this->bindEnv($admin, $dispute);

        $response = $this->makePatch($admin, '/v3/admin/disputes/42', [
            'status' => OrderDispute::STATUS_RESOLVED_LOST,
            'resolution_note' => 'reverting',
        ]);
        self::assertSame(422, $response->getStatusCode());
        $body = $this->jsonBody($response);
        self::assertSame('dispute_not_mutable', $body['error']['code']);

        // Dispute unchanged
        self::assertSame(OrderDispute::STATUS_RESOLVED_WON, $dispute->getStatus());
    }

    #[Test]
    public function invalidStatusReturns422(): void
    {
        $admin = $this->makeAdminUser(99);
        $dispute = $this->makeDispute(id: 42, status: OrderDispute::STATUS_OPEN);
        $this->bindEnv($admin, $dispute);

        $response = $this->makePatch($admin, '/v3/admin/disputes/42', [
            'status' => 'bogus_state',
        ]);
        self::assertSame(422, $response->getStatusCode());
    }

    #[Test]
    public function returns404ForNonexistentDispute(): void
    {
        $admin = $this->makeAdminUser(99);
        $this->bindEnv($admin, null);

        $response = $this->makePatch($admin, '/v3/admin/disputes/9999', [
            'status' => OrderDispute::STATUS_IN_REVIEW,
        ]);
        self::assertSame(404, $response->getStatusCode());
    }

    #[Test]
    public function withdrawnIsTerminal(): void
    {
        $admin = $this->makeAdminUser(99);
        $dispute = $this->makeDispute(id: 42, status: OrderDispute::STATUS_OPEN);
        $this->bindEnv($admin, $dispute);

        $response = $this->makePatch($admin, '/v3/admin/disputes/42', [
            'status' => OrderDispute::STATUS_WITHDRAWN,
            'resolution_note' => 'Customer withdrew claim',
        ]);
        self::assertSame(200, $response->getStatusCode());
        self::assertSame(OrderDispute::STATUS_WITHDRAWN, $dispute->getStatus());
        self::assertTrue($dispute->isTerminal());
    }

    // ===== Helpers =====

    private function bindEnv(User $admin, ?OrderDispute $dispute): void
    {
        $userRepo = $this->createMock(UserRepository::class);
        $userRepo->method('findById')->willReturn($admin);

        $auditRepo = new class($this->recordedAudits) extends \Doctrine\ORM\EntityRepository {
            public function __construct(private array &$sink) {}
            public function save(AuditLog $log): void { $this->sink[] = $log; }
            public function getClassName(): string { return AuditLog::class; }
        };

        $em = $this->stubEm(function ($em) use ($userRepo, $dispute, $auditRepo) {
            $em->method('getRepository')->willReturnMap([
                [User::class, $userRepo],
                [AuditLog::class, $auditRepo],
            ]);
            $em->method('find')->willReturnCallback(function (string $class, mixed $id) use ($dispute) {
                if ($class === OrderDispute::class) {
                    return $dispute;
                }
                return null;
            });
        });
        $this->bind(EntityManagerInterface::class, $em);
        $this->bind(AuditEmitter::class, new AuditEmitter($em, new NullLogger()));
    }

    private function makePatch(User $user, string $uri, array $body): ResponseInterface
    {
        $jwt = $this->app->getContainer()->get(JwtService::class);
        $pair = $jwt->issueTokenPair($user);
        return $this->handle($this->jsonRequest('PATCH', $uri, $body, [
            'Authorization' => 'Bearer ' . $pair->accessToken,
        ]));
    }

    private function makeAdminUser(int $id): User
    {
        $u = $this->makeUser(id: $id);
        $u->setRoles(admin: true);
        return $u;
    }

    private function makeDispute(int $id, string $status = OrderDispute::STATUS_OPEN): OrderDispute
    {
        $d = new OrderDispute(
            providerOrderRef: 'NOON-REF-A',
            eventType: 'CHARGEBACK_OPENED',
            rawEvent: ['eventType' => 'CHARGEBACK_OPENED'],
        );
        $idRef = new \ReflectionProperty(OrderDispute::class, 'id');
        $idRef->setAccessible(true);
        $idRef->setValue($d, $id);
        // Set status directly via reflection to skip transition validation in setup
        $statusRef = new \ReflectionProperty(OrderDispute::class, 'status');
        $statusRef->setAccessible(true);
        $statusRef->setValue($d, $status);
        return $d;
    }
}
