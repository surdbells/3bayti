<?php

declare(strict_types=1);

namespace Bayti\Api\Tests\Http\Controllers\Vendor\Onboarding;

use Bayti\Api\Domain\Audit\AuditEmitter;
use Bayti\Api\Domain\Audit\AuditLog;
use Bayti\Api\Domain\Catalog\Vendor;
use Bayti\Api\Domain\Catalog\VendorRepository;
use Bayti\Api\Domain\User\User;
use Bayti\Api\Domain\User\UserRepository;
use Bayti\Api\Http\Controllers\Vendor\Onboarding\Dto\SubmitOnboardingInput;
use Bayti\Api\Http\Controllers\Vendor\Onboarding\GetOnboardingStatusController;
use Bayti\Api\Http\Controllers\Vendor\Onboarding\SubmitOnboardingDisabledController;
use Bayti\Api\Infrastructure\Auth\JwtService;
use Bayti\Api\Tests\Http\HttpTestCase;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use Psr\Http\Message\ResponseInterface;
use Psr\Log\NullLogger;

/**
 * Coverage for vendor self-serve onboarding endpoints (M3.2.X.6-D):
 *
 *   POST /v3/vendor/onboarding/submit
 *   GET  /v3/vendor/onboarding/status
 *
 * Verifies:
 *   - Submit is CLOSED: returns 410 Gone and performs no side effects
 *     (no vendor persisted, is_vendor not flipped, no audit emitted).
 *     Self-serve creation was replaced by the admin-approved
 *     POST /v3/vendor-applications flow, so the old /submit route now
 *     resolves to SubmitOnboardingDisabledController.
 *   - Status endpoint accessible to PENDING vendors (key design
 *     point, Option I locked: separate route group bypasses
 *     VendorAuthMiddleware lifecycle gate)
 *   - Status endpoint accessible to SUSPENDED vendors too
 *   - Status endpoint returns 403 for non-vendor users (inline check)
 *   - Status endpoint returns 401 for unauthenticated users
 *   - Status endpoint shows multi-vendor users all their stores
 */
#[CoversClass(SubmitOnboardingDisabledController::class)]
#[CoversClass(GetOnboardingStatusController::class)]
#[CoversClass(SubmitOnboardingInput::class)]
final class OnboardingControllersTest extends HttpTestCase
{
    /** @var array<int, AuditLog> */
    private array $recordedAuditLogs = [];
    /** @var list<Vendor> Captured persisted entities */
    private array $persistedVendors = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->recordedAuditLogs = [];
        $this->persistedVendors = [];
    }

    // -----------------------------------------------------------------
    // SubmitOnboardingController
    // -----------------------------------------------------------------

    #[Test]
    public function submitCreatesPendingVendorAndFlipsIsVendor(): void
    {
        // Self-serve onboarding is CLOSED (SubmitOnboardingDisabledController):
        // the /submit route now returns 410 Gone and creates NOTHING. Sellers
        // apply via POST /v3/vendor-applications and an admin provisions the
        // vendor. So submit no longer creates a pending vendor or flips
        // is_vendor.
        $user = $this->makeUser(id: 42);
        self::assertFalse($user->isVendor(), 'precondition: user is not a vendor');

        $this->bindEmForSubmit($user, slugTaken: false);

        $response = $this->makePost($user, '/v3/vendor/onboarding/submit', [
            'slug' => 'almas-fashion',
            'name' => 'Almas Fashion',
            'contact_email' => 'store@almas.example',
            'description' => 'Premium kaftans and abayas',
            'contact_phone' => '+971501234567',
            'legal_name' => 'Almas Trading LLC',
        ]);

        self::assertSame(410, $response->getStatusCode());

        // No vendor persisted and is_vendor NOT flipped: the endpoint is
        // disabled and short-circuits before any write.
        self::assertCount(0, $this->persistedVendors);
        self::assertFalse(
            $user->isVendor(),
            'is_vendor stays false: self-serve vendor creation is closed',
        );
    }

    #[Test]
    public function submitWithSlugCollisionReturns409(): void
    {
        $user = $this->makeUser(id: 42);

        $this->bindEmForSubmit($user, slugTaken: true);

        $response = $this->makePost($user, '/v3/vendor/onboarding/submit', [
            'slug' => 'taken-slug',
            'name' => 'New Store',
            'contact_email' => 'new@store.example',
        ]);

        // Endpoint is disabled (410 Gone) and short-circuits before the old
        // slug-collision check ever runs, so nothing is persisted.
        self::assertSame(410, $response->getStatusCode());
        self::assertCount(0, $this->persistedVendors);
        self::assertFalse($user->isVendor());
    }

    #[Test]
    public function submitWithInvalidEmailReturns422(): void
    {
        $user = $this->makeUser(id: 42);

        $this->bindEmForSubmit($user, slugTaken: false);

        $response = $this->makePost($user, '/v3/vendor/onboarding/submit', [
            'slug' => 'valid-slug',
            'name' => 'New Store',
            'contact_email' => 'not-an-email',
        ]);

        // Disabled endpoint returns 410 before validation runs; nothing persists.
        self::assertSame(410, $response->getStatusCode());
        self::assertCount(0, $this->persistedVendors);
    }

    #[Test]
    public function submitWithMissingRequiredFieldsReturns422(): void
    {
        $user = $this->makeUser(id: 42);

        $this->bindEmForSubmit($user, slugTaken: false);

        $response = $this->makePost($user, '/v3/vendor/onboarding/submit', [
            // slug missing
            'name' => 'New Store',
            'contact_email' => 'store@example.com',
        ]);

        // Disabled endpoint returns 410 before validation runs; nothing persists.
        self::assertSame(410, $response->getStatusCode());
        self::assertCount(0, $this->persistedVendors);
    }

    #[Test]
    public function submitWithInvalidSlugFormatReturns422(): void
    {
        $user = $this->makeUser(id: 42);

        $this->bindEmForSubmit($user, slugTaken: false);

        // slug must be lowercase kebab-case
        $response = $this->makePost($user, '/v3/vendor/onboarding/submit', [
            'slug' => 'Invalid Slug With Spaces',
            'name' => 'New Store',
            'contact_email' => 'store@example.com',
        ]);

        // Disabled endpoint returns 410 before validation runs; nothing persists.
        self::assertSame(410, $response->getStatusCode());
        self::assertCount(0, $this->persistedVendors);
    }

    #[Test]
    public function submitWithoutAuthTokenReturns401(): void
    {
        // No Authorization header, AuthMiddleware should reject
        $response = $this->handle(
            $this->jsonRequest('POST', '/v3/vendor/onboarding/submit', [
                'slug' => 'almas-fashion',
                'name' => 'Almas Fashion',
                'contact_email' => 'store@almas.example',
            ]),
        );

        self::assertSame(401, $response->getStatusCode());
    }

    #[Test]
    public function submitEmitsAuditCreated(): void
    {
        $user = $this->makeUser(id: 42);

        $this->bindEmForSubmit($user, slugTaken: false);

        $response = $this->makePost($user, '/v3/vendor/onboarding/submit', [
            'slug' => 'almas-fashion',
            'name' => 'Almas Fashion',
            'contact_email' => 'store@almas.example',
        ]);

        // The disabled endpoint creates no Vendor, so it emits NO audit row.
        self::assertSame(410, $response->getStatusCode());
        self::assertCount(
            0,
            $this->recordedAuditLogs,
            'disabled endpoint performs no create, so no audit row is emitted',
        );
    }

    // -----------------------------------------------------------------
    // GetOnboardingStatusController, KEY: pending vendors must access
    // -----------------------------------------------------------------

    #[Test]
    public function statusEndpointAccessibleToPendingVendors(): void
    {
        // CRITICAL design point (Option I from M3.2.X.6 plan): a
        // pending vendor user must be able to hit this endpoint to
        // check their onboarding status. The standard VendorAuth
        // middleware lifecycle gate would block them; the separate
        // route group (AuthMiddleware only) lets them through.
        $user = $this->makeUser(id: 42);
        $user->setRoles(vendor: true);

        $pendingVendor = $this->makeVendor(100, 'almas-fashion', 'Almas Fashion');
        // Vendor defaults to pending, no transition needed

        $this->bindEmForStatus($user, [$pendingVendor]);

        $response = $this->makeGet($user, '/v3/vendor/onboarding/status');

        self::assertSame(
            200,
            $response->getStatusCode(),
            'Pending vendors MUST be able to access their own status endpoint',
        );

        $body = $this->jsonBody($response);
        self::assertCount(1, $body['vendors']);
        self::assertSame('pending', $body['vendors'][0]['status']);
    }

    #[Test]
    public function statusEndpointAccessibleToSuspendedVendors(): void
    {
        // Equally important: a suspended vendor must be able to see
        // their own status (including the suspension reason).
        $user = $this->makeUser(id: 42);
        $user->setRoles(vendor: true);

        $suspendedVendor = $this->makeVendor(100, 'almas-fashion', 'Almas Fashion');
        $suspendedVendor->approve();
        $suspendedVendor->suspend('Quality complaints from customers');

        $this->bindEmForStatus($user, [$suspendedVendor]);

        $response = $this->makeGet($user, '/v3/vendor/onboarding/status');

        self::assertSame(200, $response->getStatusCode());
        $body = $this->jsonBody($response);
        self::assertSame('suspended', $body['vendors'][0]['status']);
        self::assertSame(
            'Quality complaints from customers',
            $body['vendors'][0]['status_reason'],
            'Suspension reason surfaced so vendor understands the state',
        );
    }

    #[Test]
    public function statusEndpointShowsMultipleVendorsForMultiStoreUser(): void
    {
        // Multi-vendor users see all their stores in one response.
        $user = $this->makeUser(id: 42);
        $user->setRoles(vendor: true);

        $approvedStore = $this->makeVendor(100, 'almas-fashion', 'Almas Fashion');
        $approvedStore->approve();
        $pendingStore = $this->makeVendor(101, 'almas-home', 'Almas Home');

        $this->bindEmForStatus($user, [$approvedStore, $pendingStore]);

        $response = $this->makeGet($user, '/v3/vendor/onboarding/status');

        self::assertSame(200, $response->getStatusCode());
        $body = $this->jsonBody($response);
        self::assertCount(2, $body['vendors']);
        $statuses = array_map(static fn ($v) => $v['status'], $body['vendors']);
        self::assertContains('approved', $statuses);
        self::assertContains('pending', $statuses);
    }

    #[Test]
    public function statusEndpointReturns403ForNonVendorUsers(): void
    {
        // Plain customer (is_vendor=false) hitting the status endpoint.
        // The inline is_vendor check rejects them with FORBIDDEN.
        // They should submit onboarding first.
        $regularUser = $this->makeUser(id: 42);
        // No setRoles(vendor: true)

        $this->bindEmForStatus($regularUser, []);

        $response = $this->makeGet($regularUser, '/v3/vendor/onboarding/status');

        self::assertSame(403, $response->getStatusCode());
    }

    #[Test]
    public function statusEndpointReturns401WithoutAuth(): void
    {
        $response = $this->handle(
            $this->jsonRequest('GET', '/v3/vendor/onboarding/status', []),
        );

        self::assertSame(401, $response->getStatusCode());
    }

    #[Test]
    public function statusEndpointReturnsEmptyArrayForVendorWithoutStores(): void
    {
        // Edge case: User has is_vendor=true (admin set it somehow)
        // but has never submitted onboarding, so owns zero stores.
        // Returns 200 with empty array, empty is legitimate, not 404.
        $user = $this->makeUser(id: 42);
        $user->setRoles(vendor: true);

        $this->bindEmForStatus($user, []);

        $response = $this->makeGet($user, '/v3/vendor/onboarding/status');

        self::assertSame(200, $response->getStatusCode());
        $body = $this->jsonBody($response);
        self::assertSame([], $body['vendors']);
    }

    // ===== Helpers =====

    private function makeVendor(int $id, string $slug, string $name): Vendor
    {
        $vendor = new Vendor($slug, $name, 'vendor@example.test');
        $ref = new \ReflectionProperty(Vendor::class, 'id');
        $ref->setAccessible(true);
        $ref->setValue($vendor, $id);
        return $vendor;
    }

    private function bindEmForSubmit(User $user, bool $slugTaken): void
    {
        $userRepo = $this->createMock(UserRepository::class);
        $userRepo->method('findById')->willReturn($user);

        $vendorRepo = $this->createMock(VendorRepository::class);
        $vendorRepo->method('slugExists')->willReturn($slugTaken);

        $auditRepo = new class($this->recordedAuditLogs) extends \Doctrine\ORM\EntityRepository {
            public function __construct(private array &$sink) {}
            public function save(AuditLog $log): void { $this->sink[] = $log; }
            public function getClassName(): string { return AuditLog::class; }
        };

        $persistedSink = &$this->persistedVendors;
        $em = $this->stubEm(function ($em) use ($userRepo, $vendorRepo, $auditRepo, &$persistedSink) {
            $em->method('getRepository')->willReturnMap([
                [User::class, $userRepo],
                [Vendor::class, $vendorRepo],
                [AuditLog::class, $auditRepo],
            ]);
            // Capture persist() calls for the new Vendor
            $em->method('persist')->willReturnCallback(function ($entity) use (&$persistedSink): void {
                if ($entity instanceof Vendor) {
                    // Simulate Doctrine's IDENTITY assignment so the
                    // serializer's getId() call works in the test.
                    $ref = new \ReflectionProperty(Vendor::class, 'id');
                    $ref->setAccessible(true);
                    if ($ref->getValue($entity) === null) {
                        $ref->setValue($entity, count($persistedSink) + 1000);
                    }
                    $persistedSink[] = $entity;
                }
            });
        });

        $this->bind(EntityManagerInterface::class, $em);
        $this->bind(AuditEmitter::class, new AuditEmitter($em, new NullLogger()));
    }

    /**
     * @param list<Vendor> $userVendors
     */
    private function bindEmForStatus(User $user, array $userVendors): void
    {
        $userRepo = $this->createMock(UserRepository::class);
        $userRepo->method('findById')->willReturn($user);

        $vendorRepo = $this->createMock(VendorRepository::class);
        $vendorRepo->method('findByOwnerUser')->willReturn($userVendors);
        // existsApprovedForOwnerUser is consulted by VendorAuthMiddleware
        // for routes IT gates, but the onboarding status endpoint is in
        // a SEPARATE route group (Option I) without that middleware.
        // So this method is irrelevant for the status endpoint path.

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

    private function makeGet(User $user, string $uri): ResponseInterface
    {
        $jwt = $this->app->getContainer()->get(JwtService::class);
        $pair = $jwt->issueTokenPair($user);
        return $this->handle($this->jsonRequest('GET', $uri, [], [
            'Authorization' => 'Bearer ' . $pair->accessToken,
        ]));
    }
}
