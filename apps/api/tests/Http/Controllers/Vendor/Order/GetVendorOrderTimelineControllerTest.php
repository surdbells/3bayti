<?php

declare(strict_types=1);

namespace Bayti\Api\Tests\Http\Controllers\Vendor\Order;

use Bayti\Api\Domain\Catalog\Vendor;
use Bayti\Api\Domain\Catalog\VendorRepository;
use Bayti\Api\Domain\Order\Order;
use Bayti\Api\Domain\Order\OrderRepository;
use Bayti\Api\Domain\Order\OrderTimelineBuilder;
use Bayti\Api\Domain\User\User;
use Bayti\Api\Domain\User\UserRepository;
use Bayti\Api\Http\Controllers\Vendor\Order\GetVendorOrderTimelineController;
use Bayti\Api\Http\Serializers\OrderTimelineSerializer;
use Bayti\Api\Infrastructure\Auth\JwtService;
use Bayti\Api\Tests\Http\HttpTestCase;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use Psr\Http\Message\ResponseInterface;

/**
 * HTTP-level tests for GET /v3/vendor/orders/{id}/timeline (M3.2.X.17-D).
 *
 * Auth posture: VendorAuthMiddleware → AuthMiddleware (group middleware).
 * The controller resolves the calling vendor user, picks the
 * appropriate vendor scope, then forwards the order id + chosen
 * vendor_id to the OrderTimelineBuilder.
 *
 * Builder is mocked; integration coverage of the actual filtering
 * logic is in OrderTimelineBuilderTest (X.17-A).
 */
#[CoversClass(GetVendorOrderTimelineController::class)]
#[CoversClass(OrderTimelineSerializer::class)]
final class GetVendorOrderTimelineControllerTest extends HttpTestCase
{
    /** @var array{vendorIdFilter: ?int, order: string, limit: int, offset: int} */
    private array $capturedArgs = ['vendorIdFilter' => null, 'order' => '', 'limit' => 0, 'offset' => 0];

    protected function setUp(): void
    {
        parent::setUp();
        $this->capturedArgs = ['vendorIdFilter' => null, 'order' => '', 'limit' => 0, 'offset' => 0];
    }

    // =================================================================
    // Single-store happy path
    // =================================================================

    #[Test]
    public function singleStoreVendorGetsScopedTimeline(): void
    {
        $user = $this->makeVendorUser(50);
        $order = $this->makeOrder(1234, 'V3-X');
        $this->bindDeps($user, ownedVendorIds: [101], order: $order, builderResult: [
            'events' => [
                ['id' => 'order:created', 'type' => 'order.created',
                 'occurred_at' => '2026-05-01T08:00:00+00:00',
                 'actor' => ['type' => 'system'], 'summary' => 'Order created',
                 'details' => []],
            ],
            'total' => 1,
        ]);

        $response = $this->makeGet($user, '/v3/vendor/orders/1234/timeline');

        self::assertSame(200, $response->getStatusCode(), (string) $response->getBody());
        $body = $this->jsonBody($response);

        self::assertSame(1, $body['meta']['total']);
        self::assertSame(1234, $body['meta']['order_id']);
        self::assertSame('V3-X', $body['meta']['order_reference']);

        // Builder received the vendor's own id as the filter
        self::assertSame(101, $this->capturedArgs['vendorIdFilter']);
    }

    #[Test]
    public function defaultsToDescOrderAndLimit50(): void
    {
        $user = $this->makeVendorUser(50);
        $order = $this->makeOrder(1234, 'V3-X');
        $this->bindDeps($user, [101], $order, ['events' => [], 'total' => 0]);

        $this->makeGet($user, '/v3/vendor/orders/1234/timeline');

        self::assertSame('desc', $this->capturedArgs['order']);
        self::assertSame(50, $this->capturedArgs['limit']);
        self::assertSame(0, $this->capturedArgs['offset']);
    }

    #[Test]
    public function ascAndPaginationForwarded(): void
    {
        $user = $this->makeVendorUser(50);
        $order = $this->makeOrder(1234, 'V3-X');
        $this->bindDeps($user, [101], $order, ['events' => [], 'total' => 0]);

        $this->makeGet($user, '/v3/vendor/orders/1234/timeline?order=asc&limit=10&offset=20');

        self::assertSame('asc', $this->capturedArgs['order']);
        self::assertSame(10, $this->capturedArgs['limit']);
        self::assertSame(20, $this->capturedArgs['offset']);
    }

    // =================================================================
    // Multi-store routing
    // =================================================================

    #[Test]
    public function multiStoreUserWithoutVendorIdGets422Ambiguous(): void
    {
        $user = $this->makeVendorUser(50);
        $order = $this->makeOrder(1234, 'V3-X');
        $this->bindDeps($user, ownedVendorIds: [101, 202], order: $order, builderResult: ['events' => [], 'total' => 0]);

        $response = $this->makeGet($user, '/v3/vendor/orders/1234/timeline');

        self::assertSame(422, $response->getStatusCode());
        $body = $this->jsonBody($response);
        self::assertSame('VENDOR_AMBIGUOUS', $body['error']['code']);
        self::assertSame([101, 202], $body['error']['details']['available_vendor_ids']);
        // Builder never invoked
        self::assertNull($this->capturedArgs['vendorIdFilter']);
        self::assertSame(0, $this->capturedArgs['limit']);
    }

    #[Test]
    public function multiStoreUserWithOwnedVendorIdGetsTimeline(): void
    {
        $user = $this->makeVendorUser(50);
        $order = $this->makeOrder(1234, 'V3-X');
        $this->bindDeps($user, [101, 202], $order, ['events' => [], 'total' => 0]);

        $response = $this->makeGet($user, '/v3/vendor/orders/1234/timeline?vendor_id=202');

        self::assertSame(200, $response->getStatusCode());
        self::assertSame(202, $this->capturedArgs['vendorIdFilter']);
    }

    #[Test]
    public function multiStoreUserWithUnownedVendorIdGets404(): void
    {
        // Cross-tenant attempt — opaque 404, not 403.
        $user = $this->makeVendorUser(50);
        $order = $this->makeOrder(1234, 'V3-X');
        $this->bindDeps($user, [101, 202], $order, ['events' => [], 'total' => 0]);

        $response = $this->makeGet($user, '/v3/vendor/orders/1234/timeline?vendor_id=999');

        self::assertSame(404, $response->getStatusCode());
        self::assertNull($this->capturedArgs['vendorIdFilter']);
    }

    #[Test]
    public function nonNumericVendorIdGets404(): void
    {
        $user = $this->makeVendorUser(50);
        $order = $this->makeOrder(1234, 'V3-X');
        $this->bindDeps($user, [101, 202], $order, ['events' => [], 'total' => 0]);

        $response = $this->makeGet($user, '/v3/vendor/orders/1234/timeline?vendor_id=not-a-number');

        self::assertSame(404, $response->getStatusCode());
    }

    #[Test]
    public function singleStoreUserWithMatchingVendorIdStillWorks(): void
    {
        // Redundant but valid: single-store user passes their own
        // vendor_id explicitly. Must succeed, not 422.
        $user = $this->makeVendorUser(50);
        $order = $this->makeOrder(1234, 'V3-X');
        $this->bindDeps($user, [101], $order, ['events' => [], 'total' => 0]);

        $response = $this->makeGet($user, '/v3/vendor/orders/1234/timeline?vendor_id=101');

        self::assertSame(200, $response->getStatusCode());
        self::assertSame(101, $this->capturedArgs['vendorIdFilter']);
    }

    // =================================================================
    // Order ownership scoping
    // =================================================================

    #[Test]
    public function orderNotTouchingVendorReturns404(): void
    {
        // findForVendorIds returns null when the order doesn't have
        // any items from the caller's vendor set.
        $user = $this->makeVendorUser(50);
        $this->bindDeps($user, ownedVendorIds: [101], order: null, builderResult: ['events' => [], 'total' => 0]);

        $response = $this->makeGet($user, '/v3/vendor/orders/9999/timeline');

        self::assertSame(404, $response->getStatusCode());
        // Builder never invoked (we 404'd before calling it)
        self::assertNull($this->capturedArgs['vendorIdFilter']);
    }

    // =================================================================
    // Auth posture
    // =================================================================

    #[Test]
    public function unauthenticatedReturns401(): void
    {
        $user = $this->makeVendorUser(50);
        $order = $this->makeOrder(1234, 'V3-X');
        $this->bindDeps($user, [101], $order, ['events' => [], 'total' => 0]);

        $response = $this->handle($this->jsonRequest('GET', '/v3/vendor/orders/1234/timeline'));

        self::assertSame(401, $response->getStatusCode());
    }

    #[Test]
    public function nonVendorUserBlockedByVendorAuthMiddleware(): void
    {
        $user = $this->makeUser(id: 50);  // no vendor role
        $order = $this->makeOrder(1234, 'V3-X');
        $this->bindDeps($user, [], $order, ['events' => [], 'total' => 0]);

        $response = $this->makeGet($user, '/v3/vendor/orders/1234/timeline');

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

    private function makeOrder(int $id, string $reference): Order
    {
        $order = (new \ReflectionClass(Order::class))->newInstanceWithoutConstructor();
        $idRef = new \ReflectionProperty(Order::class, 'id');
        $idRef->setAccessible(true);
        $idRef->setValue($order, $id);
        $refRef = new \ReflectionProperty(Order::class, 'orderReference');
        $refRef->setAccessible(true);
        $refRef->setValue($order, $reference);
        return $order;
    }

    /**
     * @param list<int> $ownedVendorIds
     * @param array{events: list<array<string, mixed>>, total: int} $builderResult
     */
    private function bindDeps(User $user, array $ownedVendorIds, ?Order $order, array $builderResult): void
    {
        $userRepo = $this->createMock(UserRepository::class);
        $userRepo->method('findById')->willReturn($user);

        $vendorRepo = $this->createMock(VendorRepository::class);
        $vendorRepo->method('findIdsByOwnerUser')->willReturn($ownedVendorIds);
        $vendorRepo->method('existsApprovedForOwnerUser')->willReturn($ownedVendorIds !== []);

        $orderRepo = $this->createMock(OrderRepository::class);
        $orderRepo->method('findForVendorIds')->willReturn($order);

        $em = $this->stubEm(function ($em) use ($userRepo, $vendorRepo, $orderRepo) {
            $em->method('getRepository')->willReturnMap([
                [User::class, $userRepo],
                [Vendor::class, $vendorRepo],
                [Order::class, $orderRepo],
            ]);
        });
        $this->bind(EntityManagerInterface::class, $em);

        $builder = $this->createMock(OrderTimelineBuilder::class);
        $builder->method('build')->willReturnCallback(
            function (int $_orderId, ?int $vendorIdFilter, string $order, int $limit, int $offset) use ($builderResult): array {
                $this->capturedArgs = [
                    'vendorIdFilter' => $vendorIdFilter,
                    'order' => $order,
                    'limit' => $limit,
                    'offset' => $offset,
                ];
                return $builderResult;
            },
        );
        $this->bind(OrderTimelineBuilder::class, $builder);
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
