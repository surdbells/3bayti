<?php

declare(strict_types=1);

namespace Bayti\Api\Tests\Http\Controllers\Admin\Vendor;

use Bayti\Api\Domain\Audit\AuditEmitter;
use Bayti\Api\Domain\Audit\AuditLog;
use Bayti\Api\Domain\Catalog\Vendor;
use Bayti\Api\Domain\Catalog\VendorRepository;
use Bayti\Api\Domain\User\User;
use Bayti\Api\Domain\User\UserRepository;
use Bayti\Api\Http\Controllers\Admin\Vendor\ApproveVendorController;
use Bayti\Api\Http\Controllers\Admin\Vendor\ReactivateVendorController;
use Bayti\Api\Http\Controllers\Admin\Vendor\SuspendVendorController;
use Bayti\Api\Http\Controllers\Admin\Vendor\Dto\VendorTransitionInput;
use Bayti\Api\Infrastructure\Auth\JwtService;
use Bayti\Api\Tests\Http\HttpTestCase;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use Psr\Http\Message\ResponseInterface;
use Psr\Log\NullLogger;

/**
 * Coverage for vendor state transition endpoints (M3.2.X.6-C):
 *
 *   POST /v3/admin/vendors/{id}/approve
 *   POST /v3/admin/vendors/{id}/suspend
 *   POST /v3/admin/vendors/{id}/reactivate
 *
 * Verifies:
 *   - Each happy path transitions the vendor correctly
 *   - is_store_approved stays in sync per Q-LegacyFlags=A invariants
 *   - Invalid transitions return 422 (e.g. suspending a pending vendor)
 *   - 404 for unknown vendor ids
 *   - 403 for non-admin callers
 *   - Reason field surfaces in status_reason + audit changes
 *   - Audit ACTION_UPDATED row written with status diff
 *   - adminShape response includes new status fields
 */
#[CoversClass(ApproveVendorController::class)]
#[CoversClass(SuspendVendorController::class)]
#[CoversClass(ReactivateVendorController::class)]
#[CoversClass(VendorTransitionInput::class)]
final class VendorTransitionControllersTest extends HttpTestCase
{
    /** @var array<int, AuditLog> */
    private array $recordedAuditLogs = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->recordedAuditLogs = [];
    }

    // -----------------------------------------------------------------
    // ApproveVendorController
    // -----------------------------------------------------------------

    #[Test]
    public function approveTransitionsPendingVendorToApproved(): void
    {
        $admin = $this->makeAdminUser(99);
        $vendor = $this->makeVendor(42, 'almas-fashion', 'Almas Fashion');
        self::assertTrue($vendor->isPending(), 'precondition: vendor is pending');

        $this->bindEm($admin, $vendor);

        $response = $this->makePost($admin, '/v3/admin/vendors/42/approve', [
            'reason' => 'Initial KYC review passed',
        ]);

        self::assertSame(200, $response->getStatusCode());
        self::assertTrue($vendor->isApproved());
        self::assertSame('Initial KYC review passed', $vendor->getStatusReason());
        self::assertTrue(
            $vendor->isStoreApproved(),
            'Q-LegacyFlags=A: is_store_approved set atomically with status',
        );

        $body = $this->jsonBody($response);
        self::assertSame('approved', $body['vendor']['status']);
        self::assertSame('Initial KYC review passed', $body['vendor']['status_reason']);
        self::assertNotNull($body['vendor']['status_changed_at']);
    }

    #[Test]
    public function approveWithoutReasonStoresNull(): void
    {
        $admin = $this->makeAdminUser(99);
        $vendor = $this->makeVendor(42, 'almas-fashion', 'Almas Fashion');

        $this->bindEm($admin, $vendor);

        $response = $this->makePost($admin, '/v3/admin/vendors/42/approve', []);

        self::assertSame(200, $response->getStatusCode());
        self::assertTrue($vendor->isApproved());
        self::assertNull($vendor->getStatusReason());
    }

    #[Test]
    public function approveAlreadyApprovedVendorReturns422(): void
    {
        $admin = $this->makeAdminUser(99);
        $vendor = $this->makeVendor(42, 'almas-fashion', 'Almas Fashion');
        $vendor->approve(); // pre-approve

        $this->bindEm($admin, $vendor);

        $response = $this->makePost($admin, '/v3/admin/vendors/42/approve', []);

        self::assertSame(422, $response->getStatusCode());
    }

    #[Test]
    public function approveUnknownVendorReturns404(): void
    {
        $admin = $this->makeAdminUser(99);
        // Note: NO vendor mock setup; the repo's find() returns null

        $userRepo = $this->createMock(UserRepository::class);
        $userRepo->method('findById')->willReturn($admin);
        $vendorRepo = $this->createMock(VendorRepository::class);
        $vendorRepo->method('find')->willReturn(null);
        $auditRepo = new class($this->recordedAuditLogs) extends \Doctrine\ORM\EntityRepository {
            public function __construct(private array &$sink) {}
            public function save(AuditLog $log): void { $this->sink[] = $log; }
            public function getClassName(): string { return AuditLog::class; }
        };
        $em = $this->stubEm(function ($em) use ($userRepo, $vendorRepo, $auditRepo) {
            $em->method('getRepository')->willReturnMap([
                [User::class, $userRepo],
                [Vendor::class, $vendorRepo],
                [AuditLog::class, $auditRepo],
            ]);
        });
        $this->bind(EntityManagerInterface::class, $em);
        $this->bind(AuditEmitter::class, new AuditEmitter($em, new NullLogger()));

        $response = $this->makePost($admin, '/v3/admin/vendors/9999/approve', []);

        self::assertSame(404, $response->getStatusCode());
    }

    #[Test]
    public function approveByNonAdminReturns403(): void
    {
        $regularUser = $this->makeUser(id: 42);
        // No setRoles(admin: true)
        $vendor = $this->makeVendor(42, 'almas-fashion', 'Almas Fashion');

        $this->bindEm($regularUser, $vendor);

        $response = $this->makePost($regularUser, '/v3/admin/vendors/42/approve', []);

        self::assertSame(403, $response->getStatusCode());
    }

    #[Test]
    public function approveEmitsAuditUpdatedWithStatusDiff(): void
    {
        $admin = $this->makeAdminUser(99);
        $vendor = $this->makeVendor(42, 'almas-fashion', 'Almas Fashion');

        $this->bindEm($admin, $vendor);

        $this->makePost($admin, '/v3/admin/vendors/42/approve', [
            'reason' => 'KYC verified',
        ]);

        self::assertGreaterThan(0, count($this->recordedAuditLogs));
        $audit = end($this->recordedAuditLogs);
        self::assertSame(AuditLog::ACTION_UPDATED, $audit->getAction());
        self::assertSame('Vendor', $audit->getSubjectType());
        self::assertSame(42, $audit->getSubjectId());

        $changes = $audit->getChanges();
        self::assertSame('pending', $changes['before']['status']);
        self::assertSame('approved', $changes['after']['status']);
        self::assertNull($changes['before']['status_reason']);
        self::assertSame('KYC verified', $changes['after']['status_reason']);
        // is_store_approved diff also captured (atomic invariant)
        self::assertFalse($changes['before']['is_store_approved'] ?? null);
        self::assertTrue($changes['after']['is_store_approved'] ?? null);
    }

    // -----------------------------------------------------------------
    // SuspendVendorController
    // -----------------------------------------------------------------

    #[Test]
    public function suspendTransitionsApprovedVendorToSuspended(): void
    {
        $admin = $this->makeAdminUser(99);
        $vendor = $this->makeVendor(42, 'almas-fashion', 'Almas Fashion');
        $vendor->approve(); // precondition

        $this->bindEm($admin, $vendor);

        $response = $this->makePost($admin, '/v3/admin/vendors/42/suspend', [
            'reason' => 'Quality complaints from 5 customers',
        ]);

        self::assertSame(200, $response->getStatusCode());
        self::assertTrue($vendor->isSuspended());
        self::assertSame('Quality complaints from 5 customers', $vendor->getStatusReason());
        self::assertTrue(
            $vendor->isStoreApproved(),
            'CRITICAL invariant: is_store_approved preserved on suspension '
            . '(vendor was historically approved; suspension is the more-recent state)',
        );

        $body = $this->jsonBody($response);
        self::assertSame('suspended', $body['vendor']['status']);
    }

    #[Test]
    public function suspendPendingVendorReturns422(): void
    {
        $admin = $this->makeAdminUser(99);
        $vendor = $this->makeVendor(42, 'almas-fashion', 'Almas Fashion');
        // Vendor is still pending; suspend not valid

        $this->bindEm($admin, $vendor);

        $response = $this->makePost($admin, '/v3/admin/vendors/42/suspend', [
            'reason' => 'never approved',
        ]);

        self::assertSame(422, $response->getStatusCode());
    }

    #[Test]
    public function suspendAlreadySuspendedVendorReturns422(): void
    {
        $admin = $this->makeAdminUser(99);
        $vendor = $this->makeVendor(42, 'almas-fashion', 'Almas Fashion');
        $vendor->approve();
        $vendor->suspend();

        $this->bindEm($admin, $vendor);

        $response = $this->makePost($admin, '/v3/admin/vendors/42/suspend', []);

        self::assertSame(422, $response->getStatusCode());
    }

    // -----------------------------------------------------------------
    // ReactivateVendorController
    // -----------------------------------------------------------------

    #[Test]
    public function reactivateTransitionsSuspendedVendorToApproved(): void
    {
        $admin = $this->makeAdminUser(99);
        $vendor = $this->makeVendor(42, 'almas-fashion', 'Almas Fashion');
        $vendor->approve();
        $vendor->suspend('Quality issues');

        $this->bindEm($admin, $vendor);

        $response = $this->makePost($admin, '/v3/admin/vendors/42/reactivate', [
            'reason' => 'Issues resolved after training',
        ]);

        self::assertSame(200, $response->getStatusCode());
        self::assertTrue($vendor->isApproved());
        self::assertSame('Issues resolved after training', $vendor->getStatusReason());
        self::assertTrue($vendor->isStoreApproved());
    }

    #[Test]
    public function reactivatePendingVendorReturns422(): void
    {
        $admin = $this->makeAdminUser(99);
        $vendor = $this->makeVendor(42, 'almas-fashion', 'Almas Fashion');
        // Pending — never suspended; reactivate not valid

        $this->bindEm($admin, $vendor);

        $response = $this->makePost($admin, '/v3/admin/vendors/42/reactivate', []);

        self::assertSame(422, $response->getStatusCode());
    }

    #[Test]
    public function reactivateApprovedVendorReturns422(): void
    {
        $admin = $this->makeAdminUser(99);
        $vendor = $this->makeVendor(42, 'almas-fashion', 'Almas Fashion');
        $vendor->approve();

        $this->bindEm($admin, $vendor);

        $response = $this->makePost($admin, '/v3/admin/vendors/42/reactivate', []);

        self::assertSame(422, $response->getStatusCode());
    }

    #[Test]
    public function suspendAndReactivateProduceDistinctAuditTrails(): void
    {
        // Verifies that the SEPARATE audit ACTION_UPDATED rows for
        // suspend → reactivate let ops reconstruct the lifecycle
        // history (not just current status).
        $admin = $this->makeAdminUser(99);
        $vendor = $this->makeVendor(42, 'almas-fashion', 'Almas Fashion');
        $vendor->approve();

        $this->bindEm($admin, $vendor);

        $this->makePost($admin, '/v3/admin/vendors/42/suspend', [
            'reason' => 'Quality complaints',
        ]);
        $this->makePost($admin, '/v3/admin/vendors/42/reactivate', [
            'reason' => 'Resolved',
        ]);

        self::assertGreaterThanOrEqual(2, count($this->recordedAuditLogs));

        // The two most recent rows are the suspend + reactivate
        $reactivateAudit = end($this->recordedAuditLogs);
        $suspendAudit = prev($this->recordedAuditLogs);

        self::assertNotFalse($suspendAudit);
        self::assertSame('approved', $suspendAudit->getChanges()['before']['status']);
        self::assertSame('suspended', $suspendAudit->getChanges()['after']['status']);
        self::assertSame('Quality complaints', $suspendAudit->getChanges()['after']['status_reason']);

        self::assertSame('suspended', $reactivateAudit->getChanges()['before']['status']);
        self::assertSame('approved', $reactivateAudit->getChanges()['after']['status']);
        self::assertSame('Resolved', $reactivateAudit->getChanges()['after']['status_reason']);
    }

    // ===== Helpers =====

    private function makeAdminUser(int $id): User
    {
        $user = $this->makeUser(id: $id);
        $user->setRoles(admin: true);
        return $user;
    }

    private function makeVendor(int $id, string $slug, string $name): Vendor
    {
        $vendor = new Vendor($slug, $name, 'vendor@example.test');
        $ref = new \ReflectionProperty(Vendor::class, 'id');
        $ref->setAccessible(true);
        $ref->setValue($vendor, $id);
        return $vendor;
    }

    private function bindEm(User $user, Vendor $vendor): void
    {
        $userRepo = $this->createMock(UserRepository::class);
        $userRepo->method('findById')->willReturn($user);

        $vendorRepo = $this->createMock(VendorRepository::class);
        $vendorRepo->method('find')->with($vendor->getId())->willReturn($vendor);

        $auditRepo = new class($this->recordedAuditLogs) extends \Doctrine\ORM\EntityRepository {
            public function __construct(private array &$sink) {}
            public function save(AuditLog $log): void { $this->sink[] = $log; }
            public function getClassName(): string { return AuditLog::class; }
        };

        $em = $this->stubEm(function ($em) use ($userRepo, $vendorRepo, $auditRepo) {
            $em->method('getRepository')->willReturnMap([
                [User::class, $userRepo],
                [Vendor::class, $vendorRepo],
                [AuditLog::class, $auditRepo],
            ]);
        });
        $this->bind(EntityManagerInterface::class, $em);
        $this->bind(AuditEmitter::class, new AuditEmitter($em, new NullLogger()));
    }

    /**
     * @param array<string, mixed> $body
     */
    private function makePost(User $user, string $uri, array $body): ResponseInterface
    {
        $jwt = $this->app->getContainer()->get(JwtService::class);
        $pair = $jwt->issueTokenPair($user);
        return $this->handle($this->jsonRequest('POST', $uri, $body, [
            'Authorization' => 'Bearer ' . $pair->accessToken,
        ]));
    }
}
