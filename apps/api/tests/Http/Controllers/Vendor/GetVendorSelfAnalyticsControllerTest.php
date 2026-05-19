<?php

declare(strict_types=1);

namespace Bayti\Api\Tests\Http\Controllers\Vendor;

use Bayti\Api\Domain\Catalog\Vendor;
use Bayti\Api\Domain\Catalog\VendorAnalyticsCalculator;
use Bayti\Api\Domain\Catalog\VendorRepository;
use Bayti\Api\Domain\User\User;
use Bayti\Api\Domain\User\UserRepository;
use Bayti\Api\Http\Controllers\Vendor\GetVendorSelfAnalyticsController;
use Bayti\Api\Http\Serializers\VendorAnalyticsSerializer;
use Bayti\Api\Infrastructure\Auth\JwtService;
use Bayti\Api\Tests\Http\HttpTestCase;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use Psr\Http\Message\ResponseInterface;

/**
 * HTTP-level tests for GET /v3/vendor/analytics (M3.2.X.13-E).
 *
 * Multi-store routing mirrors GetVendorSelfMetricsController:
 *   - Single-store user: no ?vendor_id needed
 *   - Multi-store user without ?vendor_id: 422 VENDOR_AMBIGUOUS
 *   - ?vendor_id pointing to a store the user owns: that one
 *   - ?vendor_id pointing to a store the user doesn't own: 404
 *
 * No audit emission tested — vendors viewing own data is non-
 * auditable (matches the X.14 self-metrics pattern).
 */
#[CoversClass(GetVendorSelfAnalyticsController::class)]
#[CoversClass(VendorAnalyticsSerializer::class)]
final class GetVendorSelfAnalyticsControllerTest extends HttpTestCase
{
    private int $capturedVendorId = 0;
    private int $capturedWindowDays = 0;

    protected function setUp(): void
    {
        parent::setUp();
        $this->capturedVendorId = 0;
        $this->capturedWindowDays = 0;
    }

    // =================================================================
    // Single-store user — no vendor_id needed
    // =================================================================

    #[Test]
    public function singleStoreUserGetsAnalytics(): void
    {
        $user = $this->makeVendorUser(id: 100);
        $vendor = $this->makeVendor(101, 'almas', 'Almas');
        $this->bindDeps($user, [101], [101 => $vendor], $this->cannedAnalytics(30));

        $response = $this->makeGet($user, '/v3/vendor/analytics');

        self::assertSame(200, $response->getStatusCode(), (string) $response->getBody());
        $body = $this->jsonBody($response);
        self::assertSame(101, $body['data']['vendor']['id']);
        self::assertSame(101, $this->capturedVendorId);
    }

    // =================================================================
    // Multi-store user routing
    // =================================================================

    #[Test]
    public function multiStoreUserWithoutVendorIdGets422Ambiguous(): void
    {
        $user = $this->makeVendorUser(id: 100);
        $vendor1 = $this->makeVendor(101, 'almas', 'Almas');
        $vendor2 = $this->makeVendor(202, 'cedar', 'Cedar');
        $this->bindDeps($user, [101, 202], [101 => $vendor1, 202 => $vendor2], $this->cannedAnalytics(30));

        $response = $this->makeGet($user, '/v3/vendor/analytics');

        self::assertSame(422, $response->getStatusCode());
        $body = $this->jsonBody($response);
        self::assertSame('VENDOR_AMBIGUOUS', $body['error']['code']);
        self::assertContains(101, $body['error']['details']['available_vendor_ids']);
        self::assertContains(202, $body['error']['details']['available_vendor_ids']);
    }

    #[Test]
    public function multiStoreUserWithOwnedVendorIdGetsThat(): void
    {
        $user = $this->makeVendorUser(id: 100);
        $vendor1 = $this->makeVendor(101, 'almas', 'Almas');
        $vendor2 = $this->makeVendor(202, 'cedar', 'Cedar');
        $this->bindDeps($user, [101, 202], [101 => $vendor1, 202 => $vendor2], $this->cannedAnalytics(30));

        $response = $this->makeGet($user, '/v3/vendor/analytics?vendor_id=202');

        self::assertSame(200, $response->getStatusCode());
        $body = $this->jsonBody($response);
        self::assertSame(202, $body['data']['vendor']['id']);
        self::assertSame(202, $this->capturedVendorId);
    }

    #[Test]
    public function unownedVendorIdReturns404(): void
    {
        $user = $this->makeVendorUser(id: 100);
        $vendor = $this->makeVendor(101, 'almas', 'Almas');
        $this->bindDeps($user, [101], [101 => $vendor], $this->cannedAnalytics(30));

        // Request a vendor id NOT in the user's owned set
        $response = $this->makeGet($user, '/v3/vendor/analytics?vendor_id=999');

        // Opaque 404 (no enumeration leak across tenants)
        self::assertSame(404, $response->getStatusCode());
    }

    #[Test]
    public function nonNumericVendorIdReturns404(): void
    {
        $user = $this->makeVendorUser(id: 100);
        $vendor = $this->makeVendor(101, 'almas', 'Almas');
        $this->bindDeps($user, [101], [101 => $vendor], $this->cannedAnalytics(30));

        $response = $this->makeGet($user, '/v3/vendor/analytics?vendor_id=not-a-number');
        self::assertSame(404, $response->getStatusCode());
    }

    // =================================================================
    // Window param handling
    // =================================================================

    #[Test]
    public function customDaysParamForwarded(): void
    {
        $user = $this->makeVendorUser(id: 100);
        $vendor = $this->makeVendor(101, 'almas', 'Almas');
        $this->bindDeps($user, [101], [101 => $vendor], $this->cannedAnalytics(60));

        $this->makeGet($user, '/v3/vendor/analytics?days=60');
        self::assertSame(60, $this->capturedWindowDays);
    }

    #[Test]
    public function daysClampedToRange(): void
    {
        $user = $this->makeVendorUser(id: 100);
        $vendor = $this->makeVendor(101, 'almas', 'Almas');
        $this->bindDeps($user, [101], [101 => $vendor], $this->cannedAnalytics(7));

        $this->makeGet($user, '/v3/vendor/analytics?days=0');
        self::assertSame(7, $this->capturedWindowDays);

        $this->bindDeps($user, [101], [101 => $vendor], $this->cannedAnalytics(365));
        $this->makeGet($user, '/v3/vendor/analytics?days=9999');
        self::assertSame(365, $this->capturedWindowDays);
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
        // VendorAuthMiddleware checks status; flip to approved so it
        // doesn't reject the request before our controller runs.
        $vendor->approve();
        $ref = new \ReflectionProperty(Vendor::class, 'id');
        $ref->setAccessible(true);
        $ref->setValue($vendor, $id);
        return $vendor;
    }

    /**
     * @param list<int> $ownedVendorIds
     * @param array<int, Vendor> $vendorsById
     * @param array<string, mixed> $analytics
     */
    private function bindDeps(
        User $user,
        array $ownedVendorIds,
        array $vendorsById,
        array $analytics,
    ): void {
        $userRepo = $this->createMock(UserRepository::class);
        $userRepo->method('findById')->willReturn($user);

        $vendorRepo = $this->createMock(VendorRepository::class);
        $vendorRepo->method('findIdsByOwnerUser')->willReturn($ownedVendorIds);
        $vendorRepo->method('existsApprovedForOwnerUser')->willReturn($ownedVendorIds !== []);
        $vendorRepo->method('find')->willReturnCallback(
            fn (int $id) => $vendorsById[$id] ?? null,
        );

        $em = $this->stubEm(function ($em) use ($userRepo, $vendorRepo) {
            $em->method('getRepository')->willReturnMap([
                [User::class, $userRepo],
                [Vendor::class, $vendorRepo],
            ]);
        });
        $this->bind(EntityManagerInterface::class, $em);

        $calculator = $this->createMock(VendorAnalyticsCalculator::class);
        $calculator->method('computeForVendor')->willReturnCallback(
            function (int $vendorId, int $days) use ($analytics): array {
                $this->capturedVendorId = $vendorId;
                $this->capturedWindowDays = $days;
                return $analytics;
            },
        );
        $this->bind(VendorAnalyticsCalculator::class, $calculator);
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
     * @return array<string, mixed>
     */
    private function cannedAnalytics(int $windowDays): array
    {
        $until = new \DateTimeImmutable();
        $since = $until->modify("-{$windowDays} days");
        return [
            'window' => [
                'days' => $windowDays,
                'since' => $since->format(\DateTimeInterface::ATOM),
                'until' => $until->format(\DateTimeInterface::ATOM),
            ],
            'totals' => [
                'revenue_aed' => '500.00',
                'orders' => 3,
                'items' => 4,
                'aov_aed' => '166.67',
                'unique_customers' => 2,
            ],
            'revenue_series' => [],
            'top_products_by_units' => [],
            'top_products_by_revenue' => [],
            'customer_mix' => ['new' => 1, 'returning' => 1, 'total' => 2],
            'status_mix' => ['delivered' => 4, 'cancelled' => 0, 'returned' => 0, 'total' => 4],
        ];
    }
}
