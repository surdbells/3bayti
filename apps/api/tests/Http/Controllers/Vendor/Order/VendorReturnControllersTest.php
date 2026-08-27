<?php

declare(strict_types=1);

namespace Bayti\Api\Tests\Http\Controllers\Vendor\Order;

use Bayti\Api\Domain\Catalog\Product;
use Bayti\Api\Domain\Catalog\Vendor;
use Bayti\Api\Domain\Catalog\VendorRepository;
use Bayti\Api\Domain\Order\Order;
use Bayti\Api\Domain\Order\OrderItem;
use Bayti\Api\Domain\Order\OrderReturnRequest;
use Bayti\Api\Domain\Order\OrderReturnRequestItem;
use Bayti\Api\Domain\Order\OrderReturnRequestRepository;
use Bayti\Api\Domain\User\User;
use Bayti\Api\Http\Controllers\Vendor\Order\ConfirmReceiptController;
use Bayti\Api\Http\Controllers\Vendor\Order\GetVendorReturnController;
use Bayti\Api\Http\Controllers\Vendor\Order\ListVendorReturnsController;
use Bayti\Api\Http\Serializers\ReturnRequestSerializer;
use Bayti\Api\Infrastructure\Auth\JwtService;
use Bayti\Api\Tests\Http\HttpTestCase;
use DateTimeImmutable;
use DateTimeZone;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use Psr\Http\Message\ResponseInterface;

/**
 * Coverage for the 3 M3.2.X.18-E vendor return endpoints.
 *
 *   GET  /v3/vendor/returns
 *   GET  /v3/vendor/returns/{id}
 *   POST /v3/vendor/returns/{id}/confirm-receipt
 *
 * Vendor authorization considerations:
 *   - User must have isVendor=true (User flag)
 *   - User must own at least one approved vendor
 *     (VendorRepository::existsApprovedForOwnerUser)
 *   - The return must contain at least one item from one of the
 *     user's vendors (intersection check in controller)
 *
 * Tests wire a vendor user, a VendorRepository that returns the
 * user's vendor IDs, and an OrderReturnRequestRepository that
 * serves the test fixture returns.
 */
#[CoversClass(ListVendorReturnsController::class)]
#[CoversClass(GetVendorReturnController::class)]
#[CoversClass(ConfirmReceiptController::class)]
#[CoversClass(ReturnRequestSerializer::class)]
final class VendorReturnControllersTest extends HttpTestCase
{
    // =================================================================
    // GET /v3/vendor/returns, list
    // =================================================================

    #[Test]
    public function listReturnsPagedReturnsForSingleVendorUser(): void
    {
        $vendorUser = $this->makeVendorUser(id: 50);
        $vendor = $this->makeVendor(101);
        $customer = $this->makeUser(id: 42);
        $order = $this->makeOrder(customer: $customer);

        $rr1 = new OrderReturnRequest(
            order: $order, customer: $customer,
            reason: OrderReturnRequest::REASON_DEFECTIVE,
            customerNotes: 'a',
        );
        $rr1->addItem(new OrderReturnRequestItem(
            $this->makeDeliveredItem($order, $vendor, id: 501, qty: 1, unitPrice: '50.00'),
            1,
        ));
        $this->setEntityId($rr1, 1);

        $this->bindVendorEm(
            vendorUser: $vendorUser,
            ownedVendorIds: [101],
            paginatedResult: ['items' => [$rr1], 'total' => 1],
        );

        $response = $this->vendorGet($vendorUser, '/v3/vendor/returns');
        self::assertSame(200, $response->getStatusCode(), (string) $response->getBody());
        $body = $this->jsonBody($response);
        self::assertSame(1, $body['meta']['total']);
        self::assertCount(1, $body['data']);
        self::assertSame(1, $body['data'][0]['id']);
    }

    #[Test]
    public function listReturns422ForMultiVendorUserWithoutVendorIdParam(): void
    {
        $vendorUser = $this->makeVendorUser(id: 50);
        $this->bindVendorEm(
            vendorUser: $vendorUser,
            ownedVendorIds: [101, 202],
        );

        $response = $this->vendorGet($vendorUser, '/v3/vendor/returns');
        self::assertSame(422, $response->getStatusCode());
        $body = $this->jsonBody($response);
        self::assertSame('VENDOR_ID_REQUIRED', $body['error']['code']);
    }

    #[Test]
    public function listReturnsEmptyWhenRequestingUnownedVendor(): void
    {
        $vendorUser = $this->makeVendorUser(id: 50);
        $this->bindVendorEm(
            vendorUser: $vendorUser,
            ownedVendorIds: [101],
            paginatedResult: ['items' => [], 'total' => 0],
        );

        // Ask for vendor 999 the user doesn't own.
        $response = $this->vendorGet($vendorUser, '/v3/vendor/returns?vendor_id=999');
        self::assertSame(200, $response->getStatusCode());
        $body = $this->jsonBody($response);
        self::assertSame(0, $body['meta']['total']);
    }

    #[Test]
    public function listForwardsStatusFilter(): void
    {
        $vendorUser = $this->makeVendorUser(id: 50);
        $vendor = $this->makeVendor(101);
        $customer = $this->makeUser(id: 42);
        $order = $this->makeOrder(customer: $customer);

        $approved = new OrderReturnRequest(
            order: $order, customer: $customer,
            reason: OrderReturnRequest::REASON_DEFECTIVE,
            customerNotes: 'b',
        );
        $approved->addItem(new OrderReturnRequestItem(
            $this->makeDeliveredItem($order, $vendor, id: 501, qty: 1, unitPrice: '10.00'),
            1,
        ));
        $admin = $this->makeUser(id: 1);
        $admin->setRoles(admin: true);
        $approved->approve($admin);
        $this->setEntityId($approved, 7);

        $capturedFilters = [];
        $this->bindVendorEm(
            vendorUser: $vendorUser,
            ownedVendorIds: [101],
            paginatedResult: ['items' => [$approved], 'total' => 1],
            captureFilters: function (int $vendorId, array $filters) use (&$capturedFilters): void {
                $capturedFilters[] = ['vendor_id' => $vendorId, 'filters' => $filters];
            },
        );

        $response = $this->vendorGet($vendorUser, '/v3/vendor/returns?status=approved&limit=10');
        self::assertSame(200, $response->getStatusCode());
        self::assertSame('approved', $capturedFilters[0]['filters']['status']);
        self::assertSame(10, $capturedFilters[0]['filters']['limit']);
    }

    // =================================================================
    // GET /v3/vendor/returns/{id}, detail
    // =================================================================

    #[Test]
    public function detailReturnsVendorShapeFilteredToVendorsItems(): void
    {
        $vendorUser = $this->makeVendorUser(id: 50);
        $vendorA = $this->makeVendor(101);  // user owns this
        $vendorB = $this->makeVendor(202);  // user does NOT own this

        $customer = $this->makeUser(id: 42);
        $order = $this->makeOrder(customer: $customer);
        $itemA = $this->makeDeliveredItem($order, $vendorA, id: 501, qty: 1, unitPrice: '50.00');
        $itemB = $this->makeDeliveredItem($order, $vendorB, id: 502, qty: 1, unitPrice: '30.00');

        $rr = new OrderReturnRequest(
            order: $order, customer: $customer,
            reason: OrderReturnRequest::REASON_DEFECTIVE,
            customerNotes: 'multi-vendor return',
        );
        $rrItemA = new OrderReturnRequestItem($itemA, 1);
        $this->setEntityId($rrItemA, 10);
        $rrItemB = new OrderReturnRequestItem($itemB, 1);
        $this->setEntityId($rrItemB, 11);
        $rr->addItem($rrItemA);
        $rr->addItem($rrItemB);
        $this->setEntityId($rr, 7);

        $this->bindVendorEm(
            vendorUser: $vendorUser,
            ownedVendorIds: [101],
            returnRequestById: $rr,
        );

        $response = $this->vendorGet($vendorUser, '/v3/vendor/returns/7');
        self::assertSame(200, $response->getStatusCode(), (string) $response->getBody());
        $body = $this->jsonBody($response);
        self::assertSame(7, $body['data']['id']);
        // Vendor only sees their item (501), not the other vendor's (502).
        self::assertCount(1, $body['data']['items']);
        self::assertSame(501, $body['data']['items'][0]['order_item_id']);
        // No admin_notes or refund block in vendor shape.
        self::assertArrayNotHasKey('admin_notes', $body['data']);
        self::assertArrayNotHasKey('refund', $body['data']);
    }

    #[Test]
    public function detailReturns404WhenVendorHasNoItemsInReturn(): void
    {
        $vendorUser = $this->makeVendorUser(id: 50);
        $someOtherVendor = $this->makeVendor(202);
        $customer = $this->makeUser(id: 42);
        $order = $this->makeOrder(customer: $customer);

        $rr = new OrderReturnRequest(
            order: $order, customer: $customer,
            reason: OrderReturnRequest::REASON_DEFECTIVE,
            customerNotes: 'x',
        );
        $rr->addItem(new OrderReturnRequestItem(
            $this->makeDeliveredItem($order, $someOtherVendor, id: 502, qty: 1, unitPrice: '30.00'),
            1,
        ));
        $this->setEntityId($rr, 7);

        $this->bindVendorEm(
            vendorUser: $vendorUser,
            ownedVendorIds: [101],  // 101 has nothing in this return
            returnRequestById: $rr,
        );

        $response = $this->vendorGet($vendorUser, '/v3/vendor/returns/7');
        self::assertSame(404, $response->getStatusCode());
    }

    // =================================================================
    // POST /v3/vendor/returns/{id}/confirm-receipt
    // =================================================================

    #[Test]
    public function confirmReceiptTransitionsPickedUpToDeliveredToVendor(): void
    {
        $vendorUser = $this->makeVendorUser(id: 50);
        $vendor = $this->makeVendor(101);
        $customer = $this->makeUser(id: 42);
        $admin = $this->makeUser(id: 1);
        $admin->setRoles(admin: true);
        $order = $this->makeOrder(customer: $customer);

        $rr = new OrderReturnRequest(
            order: $order, customer: $customer,
            reason: OrderReturnRequest::REASON_DEFECTIVE,
            customerNotes: 'x',
        );
        $rr->addItem(new OrderReturnRequestItem(
            $this->makeDeliveredItem($order, $vendor, id: 501, qty: 1, unitPrice: '50.00'),
            1,
        ));
        $rr->approve($admin);
        $rr->markPickedUp();
        $this->setEntityId($rr, 7);

        $this->bindVendorEm(
            vendorUser: $vendorUser,
            ownedVendorIds: [101],
            returnRequestById: $rr,
        );

        $response = $this->vendorPost($vendorUser, '/v3/vendor/returns/7/confirm-receipt');
        self::assertSame(200, $response->getStatusCode(), (string) $response->getBody());
        $body = $this->jsonBody($response);
        self::assertSame(OrderReturnRequest::STATUS_DELIVERED_TO_VENDOR, $body['data']['status']);
        self::assertSame(OrderReturnRequest::STATUS_DELIVERED_TO_VENDOR, $rr->getStatus());
        self::assertNotNull($rr->getDeliveredToVendorAt());
    }

    #[Test]
    public function confirmReceiptReturns422FromPendingState(): void
    {
        // Pending → can't confirm receipt; must be picked_up first.
        $vendorUser = $this->makeVendorUser(id: 50);
        $vendor = $this->makeVendor(101);
        $customer = $this->makeUser(id: 42);
        $order = $this->makeOrder(customer: $customer);

        $rr = new OrderReturnRequest(
            order: $order, customer: $customer,
            reason: OrderReturnRequest::REASON_DEFECTIVE,
            customerNotes: 'x',
        );
        $rr->addItem(new OrderReturnRequestItem(
            $this->makeDeliveredItem($order, $vendor, id: 501, qty: 1, unitPrice: '50.00'),
            1,
        ));
        $this->setEntityId($rr, 7);

        $this->bindVendorEm(
            vendorUser: $vendorUser,
            ownedVendorIds: [101],
            returnRequestById: $rr,
        );

        $response = $this->vendorPost($vendorUser, '/v3/vendor/returns/7/confirm-receipt');
        self::assertSame(422, $response->getStatusCode());
        $body = $this->jsonBody($response);
        self::assertSame('RETURN_CANNOT_CONFIRM_RECEIPT', $body['error']['code']);
    }

    #[Test]
    public function confirmReceiptReturns404ForReturnWithNoVendorItems(): void
    {
        $vendorUser = $this->makeVendorUser(id: 50);
        $someOtherVendor = $this->makeVendor(202);
        $customer = $this->makeUser(id: 42);
        $order = $this->makeOrder(customer: $customer);

        $rr = new OrderReturnRequest(
            order: $order, customer: $customer,
            reason: OrderReturnRequest::REASON_DEFECTIVE,
            customerNotes: 'x',
        );
        $rr->addItem(new OrderReturnRequestItem(
            $this->makeDeliveredItem($order, $someOtherVendor, id: 502, qty: 1, unitPrice: '30.00'),
            1,
        ));
        $this->setEntityId($rr, 7);

        $this->bindVendorEm(
            vendorUser: $vendorUser,
            ownedVendorIds: [101],
            returnRequestById: $rr,
        );

        $response = $this->vendorPost($vendorUser, '/v3/vendor/returns/7/confirm-receipt');
        self::assertSame(404, $response->getStatusCode());
    }

    // =================================================================
    // VendorAuthMiddleware enforcement
    // =================================================================

    #[Test]
    public function nonVendorUserGets403(): void
    {
        // Not flagged as vendor, VendorAuthMiddleware should 403.
        $regularUser = $this->makeUser(id: 99);
        $this->bindVendorEm(vendorUser: $regularUser, ownedVendorIds: []);

        $response = $this->vendorGet($regularUser, '/v3/vendor/returns');
        self::assertSame(403, $response->getStatusCode());
    }

    #[Test]
    public function vendorRoutesReturn401WithoutAuth(): void
    {
        $response = $this->handle($this->jsonRequest('GET', '/v3/vendor/returns'));
        self::assertSame(401, $response->getStatusCode());
    }

    // =================================================================
    // Helpers
    // =================================================================

    /**
     * @param list<int> $ownedVendorIds
     * @param array{items: list<OrderReturnRequest>, total: int}|null $paginatedResult
     */
    private function bindVendorEm(
        User $vendorUser,
        array $ownedVendorIds,
        ?array $paginatedResult = null,
        ?OrderReturnRequest $returnRequestById = null,
        ?callable $captureFilters = null,
    ): void {
        $userRepo = $this->createMock(\Bayti\Api\Domain\User\UserRepository::class);
        $userRepo->method('findById')->willReturn($vendorUser);

        $vendorRepo = $this->createMock(VendorRepository::class);
        $vendorRepo->method('findIdsByOwnerUser')->willReturn($ownedVendorIds);
        // Approved-vendor lifecycle gate: true when the user owns
        // any vendor (the test setup doesn't model approval status).
        $vendorRepo->method('existsApprovedForOwnerUser')->willReturn($ownedVendorIds !== []);

        $returnRepo = new class(
            $paginatedResult ?? ['items' => [], 'total' => 0],
            $returnRequestById,
            $captureFilters,
        ) extends OrderReturnRequestRepository {
            /**
             * @param array{items: list<OrderReturnRequest>, total: int} $paginatedResult
             */
            public function __construct(
                public readonly array $paginatedResult,
                public readonly ?OrderReturnRequest $returnRequestById,
                public readonly mixed $captureFilters,
            ) {}
            public function findForVendorPaginated(int $vendorId, array $filters = []): array
            {
                if ($this->captureFilters !== null) {
                    ($this->captureFilters)($vendorId, $filters);
                }
                return $this->paginatedResult;
            }
            public function findById(int $id): ?OrderReturnRequest
            {
                return $this->returnRequestById;
            }
            public function save(OrderReturnRequest $rr): void
            {
                // No-op for vendor tests, state changes are inspected
                // via the entity in scope.
            }
            public function getClassName(): string { return OrderReturnRequest::class; }
        };

        $em = $this->stubEm(function ($em) use ($userRepo, $vendorRepo, $returnRepo) {
            $em->method('getRepository')->willReturnMap([
                [User::class, $userRepo],
                [Vendor::class, $vendorRepo],
                [OrderReturnRequest::class, $returnRepo],
            ]);
            $em->method('flush');
            $em->method('persist');
        });

        $this->bind(EntityManagerInterface::class, $em);
    }

    private function makeVendorUser(int $id): User
    {
        $u = $this->makeUser(id: $id);
        $u->setRoles(vendor: true);
        return $u;
    }

    private function makeVendor(int $id): Vendor
    {
        $v = (new \ReflectionClass(Vendor::class))->newInstanceWithoutConstructor();
        $this->setProp($v, 'id', $id);
        $this->setProp($v, 'name', "Vendor {$id}");
        $this->setProp($v, 'contactEmail', "vendor{$id}@example.com");
        return $v;
    }

    private function makeOrder(User $customer): Order
    {
        $order = new Order(
            user: $customer,
            orderReference: 'V3-RET-001',
            subtotal: '100.00',
        );
        $this->setEntityId($order, 100);
        $this->setProp($order, 'paidAt', new DateTimeImmutable('-3 days', new DateTimeZone('UTC')));
        $this->setProp($order, 'status', Order::STATUS_DELIVERED);
        return $order;
    }

    private function makeDeliveredItem(Order $order, Vendor $vendor, int $id, int $qty, string $unitPrice): OrderItem
    {
        $p = (new \ReflectionClass(Product::class))->newInstanceWithoutConstructor();
        $this->setProp($p, 'id', random_int(200, 999));
        $this->setProp($p, 'name', 'Test product');
        $this->setProp($p, 'vendor', $vendor);
        $item = new OrderItem(
            product: $p, vendor: $vendor,
            quantity: $qty, unitPrice: $unitPrice,
            productNameSnapshot: 'Test product', productImageSnapshot: 'x.jpg',
        );
        $this->setEntityId($item, $id);
        $this->setProp($item, 'itemStatus', OrderItem::ITEM_STATUS_DELIVERED);
        $order->addItem($item);
        return $item;
    }

    private function tokenFor(User $user): string
    {
        $jwt = $this->app->getContainer()->get(JwtService::class);
        return $jwt->issueTokenPair($user)->accessToken;
    }

    private function vendorGet(User $user, string $uri): ResponseInterface
    {
        return $this->handle($this->jsonRequest('GET', $uri, [], [
            'Authorization' => 'Bearer ' . $this->tokenFor($user),
        ]));
    }

    private function vendorPost(User $user, string $uri): ResponseInterface
    {
        return $this->handle($this->jsonRequest('POST', $uri, [], [
            'Authorization' => 'Bearer ' . $this->tokenFor($user),
        ]));
    }

    private function setEntityId(object $entity, int $id): void
    {
        $this->setProp($entity, 'id', $id);
    }

    private function setProp(object $entity, string $prop, mixed $value): void
    {
        $ref = new \ReflectionProperty($entity::class, $prop);
        $ref->setAccessible(true);
        $ref->setValue($entity, $value);
    }
}
