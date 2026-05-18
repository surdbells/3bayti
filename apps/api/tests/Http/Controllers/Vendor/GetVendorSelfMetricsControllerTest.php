<?php

declare(strict_types=1);

namespace Bayti\Api\Tests\Http\Controllers\Vendor;

use Bayti\Api\Domain\Catalog\Vendor;
use Bayti\Api\Domain\Catalog\VendorMetricsCalculator;
use Bayti\Api\Domain\Catalog\VendorRepository;
use Bayti\Api\Domain\User\User;
use Bayti\Api\Domain\User\UserRepository;
use Bayti\Api\Http\Controllers\Vendor\GetVendorSelfMetricsController;
use Bayti\Api\Http\Serializers\VendorMetricsSerializer;
use Bayti\Api\Infrastructure\Auth\JwtService;
use Bayti\Api\Tests\Http\HttpTestCase;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use Psr\Http\Message\ResponseInterface;

/**
 * HTTP-level tests for GET /v3/vendor/metrics (M3.2.X.14-C).
 *
 * Auth posture:
 *   - AuthMiddleware extracts the user via JWT
 *   - VendorAuthMiddleware verifies user.roles.vendor + the user has
 *     at least one approved vendor (existsApprovedForOwnerUser true)
 *   - Controller resolves which vendor's metrics to return:
 *       single-store → that one
 *       multi-store + ?vendor_id=N (owned) → that one
 *       multi-store + missing/wrong vendor_id → 422 or 404
 */
#[CoversClass(GetVendorSelfMetricsController::class)]
#[CoversClass(VendorMetricsSerializer::class)]
final class GetVendorSelfMetricsControllerTest extends HttpTestCase
{
    /** @var int Captured window_days forwarded to the calculator */
    private int $capturedWindowDays = 0;

    /** @var int Captured vendor_id forwarded to the calculator */
    private int $capturedVendorId = 0;

    protected function setUp(): void
    {
        parent::setUp();
        $this->capturedWindowDays = 0;
        $this->capturedVendorId = 0;
    }

    // =================================================================
    // Single-store happy path
    // =================================================================

    #[Test]
    public function singleStoreVendorGetsTheirMetrics(): void
    {
        $user = $this->makeVendorUser(50);
        $vendor = $this->makeVendor(101, 'almas-fashion', 'Almas Fashion');
        $this->bindDeps($user, ownedVendorIds: [101], vendorsById: [101 => $vendor], metrics: $this->cannedMetrics(30));

        $response = $this->makeGet($user, '/v3/vendor/metrics');

        self::assertSame(200, $response->getStatusCode(), (string) $response->getBody());
        $body = $this->jsonBody($response);

        self::assertSame(101, $body['data']['vendor_id']);
        self::assertSame('almas-fashion', $body['data']['vendor_slug']);
        self::assertSame(30, $body['data']['window']['days']);
        self::assertSame(0.95, $body['data']['metrics']['fulfillment_rate']['value']);

        // Calculator was called with the vendor's own id
        self::assertSame(101, $this->capturedVendorId);
    }

    #[Test]
    public function singleStoreWindowDaysCustomizable(): void
    {
        $user = $this->makeVendorUser(50);
        $vendor = $this->makeVendor(101, 'almas', 'Almas');
        $this->bindDeps($user, [101], [101 => $vendor], $this->cannedMetrics(90));

        $this->makeGet($user, '/v3/vendor/metrics?days=90');

        self::assertSame(90, $this->capturedWindowDays);
    }

    #[Test]
    public function windowDaysClampedTo7Min(): void
    {
        $user = $this->makeVendorUser(50);
        $vendor = $this->makeVendor(101, 'almas', 'Almas');
        $this->bindDeps($user, [101], [101 => $vendor], $this->cannedMetrics(7));

        $this->makeGet($user, '/v3/vendor/metrics?days=2');

        self::assertSame(7, $this->capturedWindowDays);
    }

    // =================================================================
    // Multi-store routing
    // =================================================================

    #[Test]
    public function multiStoreUserWithoutVendorIdGets422Ambiguous(): void
    {
        $user = $this->makeVendorUser(50);
        $v1 = $this->makeVendor(101, 'almas', 'Almas');
        $v2 = $this->makeVendor(202, 'noor', 'Noor');
        $this->bindDeps(
            $user,
            ownedVendorIds: [101, 202],
            vendorsById: [101 => $v1, 202 => $v2],
            metrics: $this->cannedMetrics(30),
        );

        $response = $this->makeGet($user, '/v3/vendor/metrics');

        self::assertSame(422, $response->getStatusCode());
        $body = $this->jsonBody($response);
        self::assertSame('VENDOR_AMBIGUOUS', $body['error']['code']);
        self::assertSame([101, 202], $body['error']['details']['available_vendor_ids']);
        // Calculator NOT invoked — control flow stopped at ambiguity
        self::assertSame(0, $this->capturedVendorId);
    }

    #[Test]
    public function multiStoreUserWithOwnedVendorIdGetsTheirMetrics(): void
    {
        $user = $this->makeVendorUser(50);
        $v1 = $this->makeVendor(101, 'almas', 'Almas');
        $v2 = $this->makeVendor(202, 'noor', 'Noor');
        $this->bindDeps($user, [101, 202], [101 => $v1, 202 => $v2], $this->cannedMetrics(30));

        $response = $this->makeGet($user, '/v3/vendor/metrics?vendor_id=202');

        self::assertSame(200, $response->getStatusCode());
        $body = $this->jsonBody($response);
        self::assertSame(202, $body['data']['vendor_id']);
        self::assertSame('noor', $body['data']['vendor_slug']);
        // Calculator was invoked for the chosen vendor
        self::assertSame(202, $this->capturedVendorId);
    }

    #[Test]
    public function multiStoreUserWithUnownedVendorIdGets404(): void
    {
        // User owns 101+202, asks for 999 → cross-tenant attempt,
        // opaque 404 (standard existence-leak prevention).
        $user = $this->makeVendorUser(50);
        $v1 = $this->makeVendor(101, 'almas', 'Almas');
        $v2 = $this->makeVendor(202, 'noor', 'Noor');
        $this->bindDeps($user, [101, 202], [101 => $v1, 202 => $v2], $this->cannedMetrics(30));

        $response = $this->makeGet($user, '/v3/vendor/metrics?vendor_id=999');

        self::assertSame(404, $response->getStatusCode());
        self::assertSame(0, $this->capturedVendorId);
    }

    #[Test]
    public function nonNumericVendorIdGets404(): void
    {
        $user = $this->makeVendorUser(50);
        $v1 = $this->makeVendor(101, 'almas', 'Almas');
        $v2 = $this->makeVendor(202, 'noor', 'Noor');
        $this->bindDeps($user, [101, 202], [101 => $v1, 202 => $v2], $this->cannedMetrics(30));

        $response = $this->makeGet($user, '/v3/vendor/metrics?vendor_id=not-a-number');

        self::assertSame(404, $response->getStatusCode());
    }

    #[Test]
    public function singleStoreUserWithMatchingVendorIdStillWorks(): void
    {
        // Single-store user passing their own vendor_id (redundant
        // but client-friendly) — must succeed, not 422.
        $user = $this->makeVendorUser(50);
        $vendor = $this->makeVendor(101, 'almas', 'Almas');
        $this->bindDeps($user, [101], [101 => $vendor], $this->cannedMetrics(30));

        $response = $this->makeGet($user, '/v3/vendor/metrics?vendor_id=101');

        self::assertSame(200, $response->getStatusCode());
        self::assertSame(101, $this->capturedVendorId);
    }

    #[Test]
    public function singleStoreUserWithDifferentVendorIdGets404(): void
    {
        $user = $this->makeVendorUser(50);
        $vendor = $this->makeVendor(101, 'almas', 'Almas');
        $this->bindDeps($user, [101], [101 => $vendor], $this->cannedMetrics(30));

        $response = $this->makeGet($user, '/v3/vendor/metrics?vendor_id=999');

        self::assertSame(404, $response->getStatusCode());
    }

    // =================================================================
    // Auth posture
    // =================================================================

    #[Test]
    public function unauthenticatedReturns401(): void
    {
        $user = $this->makeVendorUser(50);
        $vendor = $this->makeVendor(101, 'almas', 'Almas');
        $this->bindDeps($user, [101], [101 => $vendor], $this->cannedMetrics(30));

        // No Authorization header
        $response = $this->handle($this->jsonRequest('GET', '/v3/vendor/metrics'));

        self::assertSame(401, $response->getStatusCode());
    }

    #[Test]
    public function nonVendorUserBlockedByVendorAuthMiddleware(): void
    {
        // Authenticated user but NOT a vendor — VendorAuthMiddleware
        // rejects upstream of the controller.
        $user = $this->makeUser(id: 50);  // no vendor role
        $vendor = $this->makeVendor(101, 'almas', 'Almas');
        $this->bindDeps($user, [], [101 => $vendor], $this->cannedMetrics(30));

        $response = $this->makeGet($user, '/v3/vendor/metrics');

        // VendorAuthMiddleware returns 403 (or 401 — implementation
        // detail). Either way, NOT 200.
        self::assertNotSame(200, $response->getStatusCode());
        self::assertContains($response->getStatusCode(), [401, 403]);
    }

    #[Test]
    public function vendorUserWithNoApprovedStoresBlockedByMiddleware(): void
    {
        // VendorAuthMiddleware checks existsApprovedForOwnerUser.
        // No approved stores → upstream rejection, controller never runs.
        $user = $this->makeVendorUser(50);
        $vendor = $this->makeVendor(101, 'almas', 'Almas');
        $this->bindDeps($user, ownedVendorIds: [], vendorsById: [101 => $vendor], metrics: $this->cannedMetrics(30));

        $response = $this->makeGet($user, '/v3/vendor/metrics');

        self::assertNotSame(200, $response->getStatusCode());
        self::assertContains($response->getStatusCode(), [401, 403]);
    }

    // =================================================================
    // Helpers
    // =================================================================

    private function makeVendorUser(int $id): User
    {
        $user = $this->makeUser(id: $id);
        $user->setRoles(vendor: true);
        return $user;
    }

    private function makeVendor(int $id, string $slug, string $name): Vendor
    {
        $vendor = new Vendor($slug, $name, "{$slug}@example.com");
        $ref = new \ReflectionProperty(Vendor::class, 'id');
        $ref->setAccessible(true);
        $ref->setValue($vendor, $id);
        return $vendor;
    }

    /**
     * @param list<int> $ownedVendorIds Vendor ids the user owns.
     *        Empty list models a non-vendor / no-approved-stores user;
     *        VendorAuthMiddleware will reject upstream.
     * @param array<int, Vendor> $vendorsById
     * @param array{window: array<string, mixed>, metrics: array<string, array<string, mixed>>} $metrics
     */
    private function bindDeps(
        User $user,
        array $ownedVendorIds,
        array $vendorsById,
        array $metrics,
    ): void {
        $userRepo = $this->createMock(UserRepository::class);
        $userRepo->method('findById')->willReturn($user);

        $vendorRepo = $this->createMock(VendorRepository::class);
        $vendorRepo->method('findIdsByOwnerUser')->willReturn($ownedVendorIds);
        $vendorRepo->method('existsApprovedForOwnerUser')->willReturn($ownedVendorIds !== []);
        $vendorRepo->method('find')->willReturnCallback(
            fn(int $id) => $vendorsById[$id] ?? null,
        );

        $em = $this->stubEm(function ($em) use ($userRepo, $vendorRepo) {
            $em->method('getRepository')->willReturnMap([
                [User::class, $userRepo],
                [Vendor::class, $vendorRepo],
            ]);
        });
        $this->bind(EntityManagerInterface::class, $em);

        $calculator = $this->createMock(VendorMetricsCalculator::class);
        $calculator->method('computeForVendor')->willReturnCallback(
            function (int $vendorId, int $days) use ($metrics): array {
                $this->capturedVendorId = $vendorId;
                $this->capturedWindowDays = $days;
                return $metrics;
            },
        );
        $this->bind(VendorMetricsCalculator::class, $calculator);
    }

    private function makeGet(User $user, string $uri): ResponseInterface
    {
        $jwt = $this->app->getContainer()->get(JwtService::class);
        $pair = $jwt->issueTokenPair($user);
        return $this->handle($this->jsonRequest('GET', $uri, [], [
            'Authorization' => 'Bearer ' . $pair->accessToken,
        ]));
    }

    /**
     * @return array{window: array{days: int, since: string, until: string}, metrics: array<string, array<string, mixed>>}
     */
    private function cannedMetrics(int $windowDays): array
    {
        $until = new \DateTimeImmutable();
        $since = $until->modify("-{$windowDays} days");
        return [
            'window' => [
                'days' => $windowDays,
                'since' => $since->format(\DateTimeInterface::ATOM),
                'until' => $until->format(\DateTimeInterface::ATOM),
            ],
            'metrics' => [
                'fulfillment_rate' => ['value' => 0.95, 'fulfilled_items' => 95, 'total_items' => 100],
                'cancellation_rate' => ['value' => 0.03, 'rejected_items' => 3, 'total_items' => 100],
                'return_rate' => ['value' => 0.02, 'approved_returns' => 2, 'total_items' => 100],
                'dispute_rate' => ['value' => 0.0125, 'disputed_orders' => 1, 'total_orders' => 80],
            ],
        ];
    }
}
