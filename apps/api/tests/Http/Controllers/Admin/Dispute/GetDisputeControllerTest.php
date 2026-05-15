<?php

declare(strict_types=1);

namespace Bayti\Api\Tests\Http\Controllers\Admin\Dispute;

use Bayti\Api\Domain\Audit\AuditEmitter;
use Bayti\Api\Domain\Audit\AuditLog;
use Bayti\Api\Domain\Order\OrderDispute;
use Bayti\Api\Domain\User\User;
use Bayti\Api\Domain\User\UserRepository;
use Bayti\Api\Http\Controllers\Admin\Dispute\GetDisputeController;
use Bayti\Api\Infrastructure\Auth\JwtService;
use Bayti\Api\Tests\Http\HttpTestCase;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use Psr\Http\Message\ResponseInterface;
use Psr\Log\NullLogger;

#[CoversClass(GetDisputeController::class)]
final class GetDisputeControllerTest extends HttpTestCase
{
    /** @var array<int, AuditLog> */
    private array $recordedAudits = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->recordedAudits = [];
    }

    #[Test]
    public function returnsDisputeWithRawEventAndEmitsAudit(): void
    {
        $admin = $this->makeAdminUser(99);
        $dispute = $this->makeDispute(id: 42, providerOrderRef: 'NOON-REF-A', rawEvent: [
            'eventType' => 'CHARGEBACK_OPENED',
            'disputeId' => 'DIS-001',
            'amount' => 299.00,
        ]);

        $this->bindEnv($admin, $dispute);

        $response = $this->makeGet($admin, '/v3/admin/disputes/42');
        self::assertSame(200, $response->getStatusCode());

        $body = $this->jsonBody($response);
        self::assertSame(42, $body['dispute']['id']);
        self::assertSame('NOON-REF-A', $body['dispute']['provider_order_ref']);
        // raw_event included on detail view
        self::assertSame('DIS-001', $body['dispute']['raw_event']['disputeId']);

        // ACTION_VIEWED audit emitted
        self::assertCount(1, $this->recordedAudits);
        self::assertSame(AuditLog::ACTION_VIEWED, $this->recordedAudits[0]->getAction());
        self::assertSame('OrderDispute', $this->recordedAudits[0]->getSubjectType());
        self::assertSame(42, $this->recordedAudits[0]->getSubjectId());
    }

    #[Test]
    public function returns404ForNonexistentDispute(): void
    {
        $admin = $this->makeAdminUser(99);
        $this->bindEnv($admin, null);

        $response = $this->makeGet($admin, '/v3/admin/disputes/9999');
        self::assertSame(404, $response->getStatusCode());
        // No audit on 404 (no subject existed)
        self::assertCount(0, $this->recordedAudits);
    }

    // Route regex {id:[0-9]+} guards against non-numeric ids at the
    // routing layer; we don't unit-test the routing here.

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

    private function makeGet(User $user, string $uri): ResponseInterface
    {
        $jwt = $this->app->getContainer()->get(JwtService::class);
        $pair = $jwt->issueTokenPair($user);
        return $this->handle($this->jsonRequest('GET', $uri, [], [
            'Authorization' => 'Bearer ' . $pair->accessToken,
        ]));
    }

    private function makeAdminUser(int $id): User
    {
        $u = $this->makeUser(id: $id);
        $u->setRoles(admin: true);
        return $u;
    }

    /**
     * @param array<string, mixed> $rawEvent
     */
    private function makeDispute(int $id, string $providerOrderRef, array $rawEvent = []): OrderDispute
    {
        $d = new OrderDispute(
            providerOrderRef: $providerOrderRef,
            eventType: 'CHARGEBACK_OPENED',
            rawEvent: $rawEvent !== [] ? $rawEvent : ['eventType' => 'CHARGEBACK_OPENED'],
        );
        $ref = new \ReflectionProperty(OrderDispute::class, 'id');
        $ref->setAccessible(true);
        $ref->setValue($d, $id);
        return $d;
    }
}
