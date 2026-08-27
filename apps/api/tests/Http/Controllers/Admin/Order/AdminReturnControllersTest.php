<?php

declare(strict_types=1);

namespace Bayti\Api\Tests\Http\Controllers\Admin\Order;

use Bayti\Api\Domain\Catalog\Product;
use Bayti\Api\Domain\Catalog\Vendor;
use Bayti\Api\Domain\Order\Order;
use Bayti\Api\Domain\Order\OrderItem;
use Bayti\Api\Domain\Order\OrderReturnRefund;
use Bayti\Api\Domain\Order\OrderReturnRequest;
use Bayti\Api\Domain\Order\OrderReturnRequestItem;
use Bayti\Api\Domain\Order\OrderReturnRequestRepository;
use Bayti\Api\Domain\User\User;
use Bayti\Api\Http\Controllers\Admin\Order\ApproveReturnController;
use Bayti\Api\Http\Controllers\Admin\Order\DenyReturnController;
use Bayti\Api\Http\Controllers\Admin\Order\GetAdminReturnController;
use Bayti\Api\Http\Controllers\Admin\Order\ListAdminReturnsController;
use Bayti\Api\Http\Controllers\Admin\Order\MarkPickedUpController;
use Bayti\Api\Http\Controllers\Admin\Order\RecordReturnRefundController;
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
 * Coverage for the 6 M3.2.X.18-F admin return endpoints.
 *
 *   GET  /v3/admin/returns                          (paginated list)
 *   GET  /v3/admin/returns/{id}                     (detail + suggested_refund_amount)
 *   POST /v3/admin/returns/{id}/approve             (pending → approved)
 *   POST /v3/admin/returns/{id}/deny                (pending → denied)
 *   POST /v3/admin/returns/{id}/mark-picked-up      (approved → picked_up)
 *   POST /v3/admin/returns/{id}/record-refund       (delivered_to_vendor → refunded)
 *
 * Auth: AdminAuthMiddleware enforces is_admin upstream. Tests use
 * an admin user with isAdmin=true.
 */
#[CoversClass(ListAdminReturnsController::class)]
#[CoversClass(GetAdminReturnController::class)]
#[CoversClass(ApproveReturnController::class)]
#[CoversClass(DenyReturnController::class)]
#[CoversClass(MarkPickedUpController::class)]
#[CoversClass(RecordReturnRefundController::class)]
#[CoversClass(ReturnRequestSerializer::class)]
final class AdminReturnControllersTest extends HttpTestCase
{
    // =================================================================
    // GET /v3/admin/returns, list
    // =================================================================

    #[Test]
    public function listReturnsPagedReturnsForAdmin(): void
    {
        $admin = $this->makeAdmin();
        $customer = $this->makeUser(id: 42);
        $vendor = $this->makeVendor(101);
        $order = $this->makeOrder($customer);
        $rr = $this->makePendingReturn($order, $customer, $vendor, returnId: 1);

        $this->bindAdminEm(
            admin: $admin,
            paginatedResult: ['items' => [$rr], 'total' => 1],
        );

        $response = $this->adminGet($admin, '/v3/admin/returns');
        self::assertSame(200, $response->getStatusCode(), (string) $response->getBody());
        $body = $this->jsonBody($response);
        self::assertSame(1, $body['meta']['total']);
        self::assertCount(1, $body['data']);
        // Admin shape exposes customer_email + customer_user_id
        self::assertArrayHasKey('customer_email', $body['data'][0]);
        self::assertArrayHasKey('customer_user_id', $body['data'][0]);
    }

    #[Test]
    public function listForwardsFilters(): void
    {
        $admin = $this->makeAdmin();
        $capturedFilters = [];

        $this->bindAdminEm(
            admin: $admin,
            paginatedResult: ['items' => [], 'total' => 0],
            captureFilters: function (array $filters) use (&$capturedFilters): void {
                $capturedFilters[] = $filters;
            },
        );

        $response = $this->adminGet(
            $admin,
            '/v3/admin/returns?status=pending&reason=defective&customer_id=42&vendor_id=101&order_id=100&limit=50',
        );
        self::assertSame(200, $response->getStatusCode());
        $filters = $capturedFilters[0];
        self::assertSame('pending', $filters['status']);
        self::assertSame('defective', $filters['reason']);
        self::assertSame(42, $filters['customerId']);
        self::assertSame(101, $filters['vendorId']);
        self::assertSame(100, $filters['orderId']);
        self::assertSame(50, $filters['limit']);
    }

    #[Test]
    public function listIgnoresInvalidStatusAndReason(): void
    {
        $admin = $this->makeAdmin();
        $capturedFilters = [];

        $this->bindAdminEm(
            admin: $admin,
            paginatedResult: ['items' => [], 'total' => 0],
            captureFilters: function (array $filters) use (&$capturedFilters): void {
                $capturedFilters[] = $filters;
            },
        );

        // 'invalid_status' is dropped silently.
        $response = $this->adminGet($admin, '/v3/admin/returns?status=invalid_status&reason=bogus');
        self::assertSame(200, $response->getStatusCode());
        self::assertArrayNotHasKey('status', $capturedFilters[0]);
        self::assertArrayNotHasKey('reason', $capturedFilters[0]);
    }

    // =================================================================
    // GET /v3/admin/returns/{id}, detail with suggested refund amount
    // =================================================================

    #[Test]
    public function detailIncludesSuggestedRefundAmount(): void
    {
        $admin = $this->makeAdmin();
        $customer = $this->makeUser(id: 42);
        $vendor = $this->makeVendor(101);
        // Order subtotal 100, discount 10, refund of 100 worth of items
        // should suggest 90 (pro-rated discount).
        $order = $this->makeOrder($customer, subtotal: '100.00', discount: '10.00');
        $rr = $this->makePendingReturn($order, $customer, $vendor, returnId: 7);

        $this->bindAdminEm(admin: $admin, returnRequestById: $rr);

        $response = $this->adminGet($admin, '/v3/admin/returns/7');
        self::assertSame(200, $response->getStatusCode(), (string) $response->getBody());
        $body = $this->jsonBody($response);
        self::assertSame(7, $body['data']['id']);
        self::assertArrayHasKey('suggested_refund_amount', $body['data']);
        // Item price 100 / order subtotal 100 → full pro-ration of 10 discount
        self::assertSame('90.00', $body['data']['suggested_refund_amount']);
    }

    #[Test]
    public function detailReturns404ForMissingReturn(): void
    {
        $admin = $this->makeAdmin();
        $this->bindAdminEm(admin: $admin, returnRequestById: null);

        $response = $this->adminGet($admin, '/v3/admin/returns/999');
        self::assertSame(404, $response->getStatusCode());
    }

    // =================================================================
    // POST /v3/admin/returns/{id}/approve
    // =================================================================

    #[Test]
    public function approveTransitionsPendingToApproved(): void
    {
        $admin = $this->makeAdmin();
        $customer = $this->makeUser(id: 42);
        $vendor = $this->makeVendor(101);
        $order = $this->makeOrder($customer);
        $rr = $this->makePendingReturn($order, $customer, $vendor, returnId: 7);

        $this->bindAdminEm(admin: $admin, returnRequestById: $rr);

        $response = $this->adminPost($admin, '/v3/admin/returns/7/approve', [
            'admin_notes' => 'Approving — photo evidence is clear',
        ]);
        self::assertSame(200, $response->getStatusCode(), (string) $response->getBody());
        $body = $this->jsonBody($response);
        self::assertSame(OrderReturnRequest::STATUS_APPROVED, $body['data']['status']);
        self::assertSame(OrderReturnRequest::STATUS_APPROVED, $rr->getStatus());
        self::assertSame('Approving — photo evidence is clear', $rr->getAdminNotes());
    }

    #[Test]
    public function approveAcceptsEmptyAdminNotes(): void
    {
        $admin = $this->makeAdmin();
        $customer = $this->makeUser(id: 42);
        $vendor = $this->makeVendor(101);
        $order = $this->makeOrder($customer);
        $rr = $this->makePendingReturn($order, $customer, $vendor, returnId: 7);

        $this->bindAdminEm(admin: $admin, returnRequestById: $rr);

        $response = $this->adminPost($admin, '/v3/admin/returns/7/approve', []);
        self::assertSame(200, $response->getStatusCode());
        self::assertSame(OrderReturnRequest::STATUS_APPROVED, $rr->getStatus());
    }

    #[Test]
    public function approveReturns422FromAlreadyApprovedState(): void
    {
        $admin = $this->makeAdmin();
        $customer = $this->makeUser(id: 42);
        $vendor = $this->makeVendor(101);
        $order = $this->makeOrder($customer);
        $rr = $this->makePendingReturn($order, $customer, $vendor, returnId: 7);
        $rr->approve($admin);  // already approved

        $this->bindAdminEm(admin: $admin, returnRequestById: $rr);

        $response = $this->adminPost($admin, '/v3/admin/returns/7/approve', []);
        self::assertSame(422, $response->getStatusCode());
        $body = $this->jsonBody($response);
        self::assertSame('RETURN_CANNOT_APPROVE', $body['error']['code']);
    }

    // =================================================================
    // POST /v3/admin/returns/{id}/deny
    // =================================================================

    #[Test]
    public function denyTransitionsPendingToDeniedWithRequiredNotes(): void
    {
        $admin = $this->makeAdmin();
        $customer = $this->makeUser(id: 42);
        $vendor = $this->makeVendor(101);
        $order = $this->makeOrder($customer);
        $rr = $this->makePendingReturn($order, $customer, $vendor, returnId: 7);

        $this->bindAdminEm(admin: $admin, returnRequestById: $rr);

        $response = $this->adminPost($admin, '/v3/admin/returns/7/deny', [
            'admin_notes' => 'Items appear unused and in original condition',
        ]);
        self::assertSame(200, $response->getStatusCode(), (string) $response->getBody());
        $body = $this->jsonBody($response);
        self::assertSame(OrderReturnRequest::STATUS_DENIED, $body['data']['status']);
        self::assertSame(OrderReturnRequest::STATUS_DENIED, $rr->getStatus());
    }

    #[Test]
    public function denyRejectsEmptyAdminNotes(): void
    {
        $admin = $this->makeAdmin();
        $customer = $this->makeUser(id: 42);
        $vendor = $this->makeVendor(101);
        $order = $this->makeOrder($customer);
        $rr = $this->makePendingReturn($order, $customer, $vendor, returnId: 7);

        $this->bindAdminEm(admin: $admin, returnRequestById: $rr);

        $response = $this->adminPost($admin, '/v3/admin/returns/7/deny', []);
        self::assertSame(422, $response->getStatusCode());
    }

    // =================================================================
    // POST /v3/admin/returns/{id}/mark-picked-up
    // =================================================================

    #[Test]
    public function markPickedUpTransitionsApprovedToPickedUp(): void
    {
        $admin = $this->makeAdmin();
        $customer = $this->makeUser(id: 42);
        $vendor = $this->makeVendor(101);
        $order = $this->makeOrder($customer);
        $rr = $this->makePendingReturn($order, $customer, $vendor, returnId: 7);
        $rr->approve($admin);

        $this->bindAdminEm(admin: $admin, returnRequestById: $rr);

        $response = $this->adminPost($admin, '/v3/admin/returns/7/mark-picked-up', []);
        self::assertSame(200, $response->getStatusCode());
        self::assertSame(OrderReturnRequest::STATUS_PICKED_UP, $rr->getStatus());
        self::assertNotNull($rr->getPickedUpAt());
    }

    #[Test]
    public function markPickedUpReturns422FromPendingState(): void
    {
        $admin = $this->makeAdmin();
        $customer = $this->makeUser(id: 42);
        $vendor = $this->makeVendor(101);
        $order = $this->makeOrder($customer);
        $rr = $this->makePendingReturn($order, $customer, $vendor, returnId: 7);

        $this->bindAdminEm(admin: $admin, returnRequestById: $rr);

        $response = $this->adminPost($admin, '/v3/admin/returns/7/mark-picked-up', []);
        self::assertSame(422, $response->getStatusCode());
        $body = $this->jsonBody($response);
        self::assertSame('RETURN_CANNOT_MARK_PICKED_UP', $body['error']['code']);
    }

    // =================================================================
    // POST /v3/admin/returns/{id}/record-refund
    // =================================================================

    #[Test]
    public function recordRefundTransitionsDeliveredToRefunded(): void
    {
        $admin = $this->makeAdmin();
        $customer = $this->makeUser(id: 42);
        $vendor = $this->makeVendor(101);
        $order = $this->makeOrder($customer);
        $rr = $this->makePendingReturn($order, $customer, $vendor, returnId: 7);
        $rr->approve($admin);
        $rr->markPickedUp();
        $rr->confirmReceivedByVendor();

        $this->bindAdminEm(admin: $admin, returnRequestById: $rr);

        $response = $this->adminPost($admin, '/v3/admin/returns/7/record-refund', [
            'method' => OrderReturnRefund::METHOD_BANK_TRANSFER,
            'amount' => '90.00',
            'reference' => 'BANK-12345',
            'notes' => 'Wired via Emirates NBD',
        ]);
        self::assertSame(200, $response->getStatusCode(), (string) $response->getBody());
        $body = $this->jsonBody($response);
        self::assertSame(OrderReturnRequest::STATUS_REFUNDED, $body['data']['status']);
        // Admin shape includes the recorded_by_admin_user_id attribution.
        self::assertSame(1, $body['data']['refund']['recorded_by_admin_user_id']);
        self::assertSame('bank_transfer', $body['data']['refund']['method']);
        self::assertSame('90.00', $body['data']['refund']['amount']);
    }

    #[Test]
    public function recordRefundRejectsInvalidMethod(): void
    {
        $admin = $this->makeAdmin();
        $customer = $this->makeUser(id: 42);
        $vendor = $this->makeVendor(101);
        $order = $this->makeOrder($customer);
        $rr = $this->makePendingReturn($order, $customer, $vendor, returnId: 7);
        $rr->approve($admin);
        $rr->markPickedUp();
        $rr->confirmReceivedByVendor();

        $this->bindAdminEm(admin: $admin, returnRequestById: $rr);

        $response = $this->adminPost($admin, '/v3/admin/returns/7/record-refund', [
            'method' => 'crypto',  // not in ALL_METHODS
            'amount' => '90.00',
        ]);
        self::assertSame(422, $response->getStatusCode());
    }

    #[Test]
    public function recordRefundRejectsMalformedAmount(): void
    {
        $admin = $this->makeAdmin();
        $customer = $this->makeUser(id: 42);
        $vendor = $this->makeVendor(101);
        $order = $this->makeOrder($customer);
        $rr = $this->makePendingReturn($order, $customer, $vendor, returnId: 7);
        $rr->approve($admin);
        $rr->markPickedUp();
        $rr->confirmReceivedByVendor();

        $this->bindAdminEm(admin: $admin, returnRequestById: $rr);

        $response = $this->adminPost($admin, '/v3/admin/returns/7/record-refund', [
            'method' => OrderReturnRefund::METHOD_BANK_TRANSFER,
            'amount' => 'not-a-number',
        ]);
        self::assertSame(422, $response->getStatusCode());
    }

    #[Test]
    public function recordRefundReturns422FromWrongState(): void
    {
        // Pending state, can't refund yet.
        $admin = $this->makeAdmin();
        $customer = $this->makeUser(id: 42);
        $vendor = $this->makeVendor(101);
        $order = $this->makeOrder($customer);
        $rr = $this->makePendingReturn($order, $customer, $vendor, returnId: 7);

        $this->bindAdminEm(admin: $admin, returnRequestById: $rr);

        $response = $this->adminPost($admin, '/v3/admin/returns/7/record-refund', [
            'method' => OrderReturnRefund::METHOD_CASH,
            'amount' => '90.00',
        ]);
        self::assertSame(422, $response->getStatusCode());
        $body = $this->jsonBody($response);
        self::assertSame('RETURN_CANNOT_REFUND', $body['error']['code']);
    }

    // =================================================================
    // Auth enforcement
    // =================================================================

    #[Test]
    public function nonAdminUserGets403(): void
    {
        $regularUser = $this->makeUser(id: 99);
        $this->bindAdminEm(admin: $regularUser);

        $response = $this->adminGet($regularUser, '/v3/admin/returns');
        self::assertSame(403, $response->getStatusCode());
    }

    #[Test]
    public function adminRoutesReturn401WithoutAuth(): void
    {
        $response = $this->handle($this->jsonRequest('GET', '/v3/admin/returns'));
        self::assertSame(401, $response->getStatusCode());
    }

    // =================================================================
    // Helpers
    // =================================================================

    /**
     * @param array{items: list<OrderReturnRequest>, total: int}|null $paginatedResult
     */
    private function bindAdminEm(
        User $admin,
        ?array $paginatedResult = null,
        ?OrderReturnRequest $returnRequestById = null,
        ?callable $captureFilters = null,
    ): void {
        $userRepo = $this->createMock(\Bayti\Api\Domain\User\UserRepository::class);
        $userRepo->method('findById')->willReturn($admin);

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
            public function findFilteredPaginatedForAdmin(array $filters = []): array
            {
                if ($this->captureFilters !== null) {
                    ($this->captureFilters)($filters);
                }
                return $this->paginatedResult;
            }
            public function findById(int $id): ?OrderReturnRequest
            {
                return $this->returnRequestById;
            }
            public function save(OrderReturnRequest $rr): void {}
            public function getClassName(): string { return OrderReturnRequest::class; }
        };

        $em = $this->stubEm(function ($em) use ($userRepo, $returnRepo) {
            $em->method('getRepository')->willReturnMap([
                [User::class, $userRepo],
                [OrderReturnRequest::class, $returnRepo],
            ]);
            $em->method('flush');
            $em->method('persist');
        });

        $this->bind(EntityManagerInterface::class, $em);
    }

    private function makeAdmin(): User
    {
        $u = $this->makeUser(id: 1);
        $u->setRoles(admin: true);
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

    private function makeOrder(User $customer, string $subtotal = '100.00', string $discount = '0.00'): Order
    {
        $order = new Order(
            user: $customer,
            orderReference: 'V3-RET-001',
            subtotal: $subtotal,
            discount: $discount,
        );
        $this->setEntityId($order, 100);
        $this->setProp($order, 'paidAt', new DateTimeImmutable('-3 days', new DateTimeZone('UTC')));
        $this->setProp($order, 'status', Order::STATUS_DELIVERED);
        return $order;
    }

    private function makePendingReturn(
        Order $order,
        User $customer,
        Vendor $vendor,
        int $returnId,
    ): OrderReturnRequest {
        $p = (new \ReflectionClass(Product::class))->newInstanceWithoutConstructor();
        $this->setProp($p, 'id', random_int(200, 999));
        $this->setProp($p, 'name', 'Test product');
        $this->setProp($p, 'vendor', $vendor);
        $item = new OrderItem(
            product: $p, vendor: $vendor,
            quantity: 1, unitPrice: '100.00',
            productNameSnapshot: 'Test product', productImageSnapshot: 'x.jpg',
        );
        $this->setEntityId($item, 501);
        $this->setProp($item, 'itemStatus', OrderItem::ITEM_STATUS_DELIVERED);
        $order->addItem($item);

        $rr = new OrderReturnRequest(
            order: $order, customer: $customer,
            reason: OrderReturnRequest::REASON_DEFECTIVE,
            customerNotes: 'pending',
        );
        $rr->addItem(new OrderReturnRequestItem($item, 1));
        $this->setEntityId($rr, $returnId);
        return $rr;
    }

    private function tokenFor(User $user): string
    {
        $jwt = $this->app->getContainer()->get(JwtService::class);
        return $jwt->issueTokenPair($user)->accessToken;
    }

    private function adminGet(User $user, string $uri): ResponseInterface
    {
        return $this->handle($this->jsonRequest('GET', $uri, [], [
            'Authorization' => 'Bearer ' . $this->tokenFor($user),
        ]));
    }

    /**
     * @param array<string, mixed> $body
     */
    private function adminPost(User $user, string $uri, array $body): ResponseInterface
    {
        return $this->handle($this->jsonRequest('POST', $uri, $body, [
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
