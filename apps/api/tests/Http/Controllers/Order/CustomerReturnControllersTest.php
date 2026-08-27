<?php

declare(strict_types=1);

namespace Bayti\Api\Tests\Http\Controllers\Order;

use Bayti\Api\Domain\Catalog\Product;
use Bayti\Api\Domain\Catalog\Vendor;
use Bayti\Api\Domain\Catalog\VendorRepository;
use Bayti\Api\Domain\Order\Order;
use Bayti\Api\Domain\Order\OrderItem;
use Bayti\Api\Domain\Order\OrderRepository;
use Bayti\Api\Domain\Order\OrderReturnRequest;
use Bayti\Api\Domain\Order\OrderReturnRequestPhoto;
use Bayti\Api\Domain\Order\OrderReturnRequestPhotoRepository;
use Bayti\Api\Domain\Order\OrderReturnRequestRepository;
use Bayti\Api\Domain\Order\ReturnPhotoStorageService;
use Bayti\Api\Domain\User\User;
use Bayti\Api\Http\Controllers\Order\CancelReturnController;
use Bayti\Api\Http\Controllers\Order\GetReturnController;
use Bayti\Api\Http\Controllers\Order\ListCustomerReturnsController;
use Bayti\Api\Http\Controllers\Order\ServeReturnPhotoController;
use Bayti\Api\Http\Controllers\Order\SubmitReturnController;
use Bayti\Api\Http\Serializers\ReturnRequestSerializer;
use Bayti\Api\Infrastructure\Auth\JwtService;
use Bayti\Api\Tests\Http\HttpTestCase;
use DateTimeImmutable;
use DateTimeZone;
use Doctrine\ORM\EntityManagerInterface;
use League\Flysystem\Filesystem;
use League\Flysystem\Local\LocalFilesystemAdapter;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Slim\Psr7\Stream;
use Slim\Psr7\UploadedFile;

/**
 * Coverage for the 5 M3.2.X.18-D customer return endpoints.
 *
 * Routes covered:
 *   POST /v3/orders/{id}/returns                    (submit)
 *   GET  /v3/orders/{id}/returns                    (list)
 *   GET  /v3/returns/{id}                           (detail)
 *   POST /v3/returns/{id}/cancel                    (withdraw)
 *   GET  /v3/returns/{id}/photos/{photoId}          (auth-gated serve)
 *
 * Test infrastructure:
 *   - Full Slim app via HttpTestCase
 *   - Per-test Flysystem temp dir for the photo storage service
 *     (real LocalFilesystemAdapter, not a mock)
 *   - EM mocked via stubEm + getRepository returnMap
 *   - Anonymous repositories used where save() needs to capture
 *     persisted entities, with sink arrays to assert
 */
#[CoversClass(SubmitReturnController::class)]
#[CoversClass(ListCustomerReturnsController::class)]
#[CoversClass(GetReturnController::class)]
#[CoversClass(CancelReturnController::class)]
#[CoversClass(ServeReturnPhotoController::class)]
#[CoversClass(ReturnRequestSerializer::class)]
#[CoversClass(\Bayti\Api\Http\Controllers\Order\Dto\SubmitReturnInput::class)]
final class CustomerReturnControllersTest extends HttpTestCase
{
    private string $tmpDir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tmpDir = sys_get_temp_dir() . '/customer-return-test-' . uniqid('', true);
        mkdir($this->tmpDir, 0755, true);
        // Real Flysystem instance so the controller's photo upload
        // pipeline runs end-to-end against the disk.
        $fs = new Filesystem(new LocalFilesystemAdapter($this->tmpDir));
        $this->bind(\League\Flysystem\FilesystemOperator::class, $fs);
        $this->bind(
            ReturnPhotoStorageService::class,
            new ReturnPhotoStorageService($fs),
        );
    }

    protected function tearDown(): void
    {
        $this->rrmdir($this->tmpDir);
        parent::tearDown();
    }

    // =================================================================
    // POST /v3/orders/{id}/returns, submit
    // =================================================================

    #[Test]
    public function submitCreatesReturnRequestWithPhotos(): void
    {
        $user = $this->makeUser(id: 42);
        $order = $this->makeDeliveredOrder(user: $user, orderId: 100);
        $vendor = $this->makeVendor(101);
        $item = $this->addDeliveredItem($order, $vendor, id: 501, qty: 1, unitPrice: '99.00');

        $captured = [];
        $this->bindStandardEm(
            user: $user,
            order: $order,
            captureCallback: function (OrderReturnRequest $rr) use (&$captured): void {
                $captured[] = $rr;
                $this->setEntityId($rr, 1);
                foreach ($rr->getItems() as $rrItem) {
                    $this->setEntityId($rrItem, 10);
                }
                foreach ($rr->getPhotos() as $photo) {
                    $this->setEntityId($photo, 20);
                }
            },
        );

        $request = $this->multipartRequest(
            method: 'POST',
            uri: '/v3/orders/100/returns',
            user: $user,
            formFields: [
                'reason' => OrderReturnRequest::REASON_DEFECTIVE,
                'customer_notes' => 'stitching came loose',
                'order_item_ids' => [501],
            ],
            files: [
                'photos' => [
                    $this->makeUpload(random_bytes(1024), 'image/jpeg', 'evidence1.jpg'),
                ],
            ],
        );

        $response = $this->handle($request);

        self::assertSame(201, $response->getStatusCode(), (string) $response->getBody());
        $body = $this->jsonBody($response);

        self::assertSame(1, $body['data']['id']);
        self::assertSame(OrderReturnRequest::STATUS_PENDING, $body['data']['status']);
        self::assertSame('defective', $body['data']['reason']);
        self::assertSame('stitching came loose', $body['data']['customer_notes']);
        self::assertCount(1, $body['data']['items']);
        self::assertSame(501, $body['data']['items'][0]['order_item_id']);
        self::assertCount(1, $body['data']['photos']);
        self::assertSame('image/jpeg', $body['data']['photos'][0]['mime_type']);
        self::assertSame('evidence1.jpg', $body['data']['photos'][0]['original_filename']);

        self::assertCount(1, $captured, 'expected exactly one return persisted');
    }

    #[Test]
    public function submitWithoutPhotosSucceeds(): void
    {
        // Photos are optional per Q-Photos = 0–5 allowed.
        $user = $this->makeUser(id: 42);
        $order = $this->makeDeliveredOrder(user: $user, orderId: 100);
        $vendor = $this->makeVendor(101);
        $this->addDeliveredItem($order, $vendor, id: 501, qty: 1, unitPrice: '99.00');

        $this->bindStandardEm(user: $user, order: $order);

        $request = $this->multipartRequest(
            method: 'POST',
            uri: '/v3/orders/100/returns',
            user: $user,
            formFields: [
                'reason' => OrderReturnRequest::REASON_SIZE_ISSUE,
                'order_item_ids' => [501],
            ],
            files: [],
        );

        $response = $this->handle($request);
        self::assertSame(201, $response->getStatusCode(), (string) $response->getBody());
        $body = $this->jsonBody($response);
        self::assertSame([], $body['data']['photos']);
    }

    #[Test]
    public function submitRejectsMoreThanMaxPhotos(): void
    {
        $user = $this->makeUser(id: 42);
        $order = $this->makeDeliveredOrder(user: $user, orderId: 100);
        $vendor = $this->makeVendor(101);
        $this->addDeliveredItem($order, $vendor, id: 501, qty: 1, unitPrice: '99.00');

        $this->bindStandardEm(user: $user, order: $order);

        // 6 photos, exceeds MAX_PHOTOS_PER_REQUEST=5
        $photos = [];
        for ($i = 0; $i < 6; $i++) {
            $photos[] = $this->makeUpload(random_bytes(100), 'image/jpeg', "evidence{$i}.jpg");
        }

        $request = $this->multipartRequest(
            method: 'POST',
            uri: '/v3/orders/100/returns',
            user: $user,
            formFields: [
                'reason' => OrderReturnRequest::REASON_DEFECTIVE,
                'order_item_ids' => [501],
            ],
            files: ['photos' => $photos],
        );

        $response = $this->handle($request);
        self::assertSame(422, $response->getStatusCode());
        $body = $this->jsonBody($response);
        self::assertArrayHasKey('photos', $body['error']['details']['fields']);
    }

    #[Test]
    public function submitRejectsInvalidPhotoMime(): void
    {
        $user = $this->makeUser(id: 42);
        $order = $this->makeDeliveredOrder(user: $user, orderId: 100);
        $vendor = $this->makeVendor(101);
        $this->addDeliveredItem($order, $vendor, id: 501, qty: 1, unitPrice: '99.00');

        $this->bindStandardEm(user: $user, order: $order);

        $request = $this->multipartRequest(
            method: 'POST',
            uri: '/v3/orders/100/returns',
            user: $user,
            formFields: [
                'reason' => OrderReturnRequest::REASON_DEFECTIVE,
                'order_item_ids' => [501],
            ],
            files: ['photos' => [$this->makeUpload(random_bytes(100), 'image/gif', 'x.gif')]],
        );

        $response = $this->handle($request);
        self::assertSame(422, $response->getStatusCode());
    }

    #[Test]
    public function submitRejectsReasonOtherWithoutNotes(): void
    {
        $user = $this->makeUser(id: 42);
        $order = $this->makeDeliveredOrder(user: $user, orderId: 100);
        $vendor = $this->makeVendor(101);
        $this->addDeliveredItem($order, $vendor, id: 501, qty: 1, unitPrice: '99.00');

        $this->bindStandardEm(user: $user, order: $order);

        $request = $this->multipartRequest(
            method: 'POST',
            uri: '/v3/orders/100/returns',
            user: $user,
            formFields: [
                'reason' => OrderReturnRequest::REASON_OTHER,
                'order_item_ids' => [501],
                // customer_notes intentionally omitted
            ],
            files: [],
        );

        $response = $this->handle($request);
        self::assertSame(422, $response->getStatusCode());
        $body = $this->jsonBody($response);
        self::assertArrayHasKey('customer_notes', $body['error']['details']['fields']);
    }

    #[Test]
    public function submitRejectsInvalidReason(): void
    {
        $user = $this->makeUser(id: 42);
        $order = $this->makeDeliveredOrder(user: $user, orderId: 100);
        $vendor = $this->makeVendor(101);
        $this->addDeliveredItem($order, $vendor, id: 501, qty: 1, unitPrice: '99.00');

        $this->bindStandardEm(user: $user, order: $order);

        $request = $this->multipartRequest(
            method: 'POST',
            uri: '/v3/orders/100/returns',
            user: $user,
            formFields: [
                'reason' => 'made_up_reason',
                'order_item_ids' => [501],
            ],
            files: [],
        );

        $response = $this->handle($request);
        self::assertSame(422, $response->getStatusCode());
    }

    #[Test]
    public function submitReturns422WhenItemNotDelivered(): void
    {
        $user = $this->makeUser(id: 42);
        $order = $this->makeDeliveredOrder(user: $user, orderId: 100);
        $vendor = $this->makeVendor(101);
        // Shipped (not delivered), Rule 2 fails.
        $product = $this->makeProduct($vendor);
        $item = new OrderItem(
            product: $product, vendor: $vendor,
            quantity: 1, unitPrice: '50.00',
            productNameSnapshot: 'X', productImageSnapshot: 'x.jpg',
        );
        $this->setEntityId($item, 501);
        $this->setProp($item, 'itemStatus', OrderItem::ITEM_STATUS_SHIPPED);
        $order->addItem($item);

        $this->bindStandardEm(user: $user, order: $order);

        $request = $this->multipartRequest(
            method: 'POST',
            uri: '/v3/orders/100/returns',
            user: $user,
            formFields: [
                'reason' => OrderReturnRequest::REASON_DEFECTIVE,
                'order_item_ids' => [501],
            ],
            files: [],
        );

        $response = $this->handle($request);
        self::assertSame(422, $response->getStatusCode());
        $body = $this->jsonBody($response);
        self::assertSame('RETURN_ITEM_NOT_DELIVERED', $body['error']['code']);
    }

    #[Test]
    public function submitReturns404ForCrossUser(): void
    {
        $callingUser = $this->makeUser(id: 42);
        $otherUser = $this->makeUser(id: 99);
        // Order belongs to otherUser, repo.findForUser($id, $callingUser) returns null.
        $order = $this->makeDeliveredOrder(user: $otherUser, orderId: 100);

        $this->bindStandardEm(
            user: $callingUser,
            order: null,  // findForUser($callingUser) returns null
            additionalOrders: [],
        );

        $request = $this->multipartRequest(
            method: 'POST',
            uri: '/v3/orders/100/returns',
            user: $callingUser,
            formFields: [
                'reason' => OrderReturnRequest::REASON_DEFECTIVE,
                'order_item_ids' => [501],
            ],
            files: [],
        );

        $response = $this->handle($request);
        self::assertSame(404, $response->getStatusCode(), (string) $response->getBody());
    }

    #[Test]
    public function submitReturns401WithoutAuth(): void
    {
        $request = $this->jsonRequest('POST', '/v3/orders/100/returns', []);
        $response = $this->handle($request);
        self::assertSame(401, $response->getStatusCode());
    }

    // =================================================================
    // GET /v3/orders/{id}/returns, list per order
    // =================================================================

    #[Test]
    public function listReturnsCustomersReturnsForOrder(): void
    {
        $user = $this->makeUser(id: 42);
        $order = $this->makeDeliveredOrder(user: $user, orderId: 100);
        $vendor = $this->makeVendor(101);
        $orderItem = $this->addDeliveredItem($order, $vendor, id: 501, qty: 1, unitPrice: '50.00');

        $rr1 = new OrderReturnRequest(
            order: $order, customer: $user,
            reason: OrderReturnRequest::REASON_DEFECTIVE,
            customerNotes: 'one',
        );
        $this->setEntityId($rr1, 1);
        $rr2 = new OrderReturnRequest(
            order: $order, customer: $user,
            reason: OrderReturnRequest::REASON_SIZE_ISSUE,
        );
        $this->setEntityId($rr2, 2);

        $this->bindStandardEm(
            user: $user,
            order: $order,
            returnsForCustomerByOrder: [$rr1, $rr2],
        );

        $response = $this->makeGet($user, '/v3/orders/100/returns');
        self::assertSame(200, $response->getStatusCode(), (string) $response->getBody());
        $body = $this->jsonBody($response);
        self::assertSame(2, $body['meta']['total']);
        self::assertCount(2, $body['data']);
    }

    #[Test]
    public function listReturns404ForCrossUserOrder(): void
    {
        $user = $this->makeUser(id: 42);
        $this->bindStandardEm(user: $user, order: null);

        $response = $this->makeGet($user, '/v3/orders/100/returns');
        self::assertSame(404, $response->getStatusCode());
    }

    // =================================================================
    // GET /v3/returns/{id}, detail
    // =================================================================

    #[Test]
    public function getReturnsReturnsRequestDetail(): void
    {
        $user = $this->makeUser(id: 42);
        $order = $this->makeDeliveredOrder(user: $user, orderId: 100);
        $rr = new OrderReturnRequest(
            order: $order, customer: $user,
            reason: OrderReturnRequest::REASON_DEFECTIVE,
            customerNotes: 'broken',
        );
        $this->setEntityId($rr, 7);

        $this->bindStandardEm(user: $user, returnRequestById: $rr);

        $response = $this->makeGet($user, '/v3/returns/7');
        self::assertSame(200, $response->getStatusCode(), (string) $response->getBody());
        $body = $this->jsonBody($response);
        self::assertSame(7, $body['data']['id']);
        self::assertSame('defective', $body['data']['reason']);
    }

    #[Test]
    public function getReturns404ForCrossUserReturn(): void
    {
        $callingUser = $this->makeUser(id: 42);
        $otherUser = $this->makeUser(id: 99);
        $order = $this->makeDeliveredOrder(user: $otherUser, orderId: 100);
        $rr = new OrderReturnRequest(
            order: $order, customer: $otherUser,
            reason: OrderReturnRequest::REASON_DEFECTIVE,
            customerNotes: 'b',
        );
        $this->setEntityId($rr, 7);

        $this->bindStandardEm(user: $callingUser, returnRequestById: $rr);

        $response = $this->makeGet($callingUser, '/v3/returns/7');
        self::assertSame(404, $response->getStatusCode());
    }

    // =================================================================
    // POST /v3/returns/{id}/cancel
    // =================================================================

    #[Test]
    public function cancelTransitionsPendingToCancelled(): void
    {
        $user = $this->makeUser(id: 42);
        $order = $this->makeDeliveredOrder(user: $user, orderId: 100);
        $rr = new OrderReturnRequest(
            order: $order, customer: $user,
            reason: OrderReturnRequest::REASON_DEFECTIVE,
            customerNotes: 'x',
        );
        $this->setEntityId($rr, 7);

        $this->bindStandardEm(user: $user, returnRequestById: $rr);

        $response = $this->handle($this->jsonRequest(
            'POST',
            '/v3/returns/7/cancel',
            [],
            ['Authorization' => 'Bearer ' . $this->tokenFor($user)],
        ));

        self::assertSame(200, $response->getStatusCode(), (string) $response->getBody());
        $body = $this->jsonBody($response);
        self::assertSame(OrderReturnRequest::STATUS_CANCELLED, $body['data']['status']);
        self::assertSame(OrderReturnRequest::STATUS_CANCELLED, $rr->getStatus());
    }

    #[Test]
    public function cancelReturns422FromApprovedState(): void
    {
        $user = $this->makeUser(id: 42);
        $admin = $this->makeUser(id: 1);
        $admin->setRoles(admin: true);
        $order = $this->makeDeliveredOrder(user: $user, orderId: 100);
        $rr = new OrderReturnRequest(
            order: $order, customer: $user,
            reason: OrderReturnRequest::REASON_DEFECTIVE,
            customerNotes: 'x',
        );
        $rr->approve($admin);
        $this->setEntityId($rr, 7);

        $this->bindStandardEm(user: $user, returnRequestById: $rr);

        $response = $this->handle($this->jsonRequest(
            'POST',
            '/v3/returns/7/cancel',
            [],
            ['Authorization' => 'Bearer ' . $this->tokenFor($user)],
        ));

        self::assertSame(422, $response->getStatusCode());
        $body = $this->jsonBody($response);
        self::assertSame('RETURN_CANNOT_CANCEL', $body['error']['code']);
    }

    // =================================================================
    // GET /v3/returns/{id}/photos/{photoId}, serve
    // =================================================================

    #[Test]
    public function customerCanFetchTheirOwnPhoto(): void
    {
        $user = $this->makeUser(id: 42);
        $order = $this->makeDeliveredOrder(user: $user, orderId: 100);
        $rr = new OrderReturnRequest(
            order: $order, customer: $user,
            reason: OrderReturnRequest::REASON_DEFECTIVE,
            customerNotes: 'x',
        );
        $this->setEntityId($rr, 7);

        // Upload a photo through the real storage service so the
        // serve endpoint can stream real bytes.
        $svc = new ReturnPhotoStorageService(
            new Filesystem(new LocalFilesystemAdapter($this->tmpDir)),
        );
        $expectedBytes = random_bytes(2048);
        $upload = $this->makeUpload($expectedBytes, 'image/jpeg', 'evidence.jpg');
        $stored = $svc->store($upload, returnRequestId: 7);
        $this->bind(ReturnPhotoStorageService::class, $svc);

        $photo = new OrderReturnRequestPhoto(
            storagePath: $stored->storagePath,
            mimeType: $stored->mimeType,
            sizeBytes: $stored->sizeBytes,
            originalFilename: $stored->originalFilename,
        );
        $this->setEntityId($photo, 20);
        $rr->addPhoto($photo);

        $this->bindStandardEm(
            user: $user,
            returnRequestById: $rr,
            photoByIdAndRequest: $photo,
        );

        $response = $this->makeGet($user, '/v3/returns/7/photos/20');
        self::assertSame(200, $response->getStatusCode());
        self::assertSame('image/jpeg', $response->getHeaderLine('Content-Type'));
        self::assertSame((string) $stored->sizeBytes, $response->getHeaderLine('Content-Length'));
        self::assertSame($expectedBytes, (string) $response->getBody());
    }

    #[Test]
    public function strangerCannotFetchPhoto(): void
    {
        $owner = $this->makeUser(id: 42);
        $stranger = $this->makeUser(id: 999);
        $order = $this->makeDeliveredOrder(user: $owner, orderId: 100);
        $rr = new OrderReturnRequest(
            order: $order, customer: $owner,
            reason: OrderReturnRequest::REASON_DEFECTIVE,
            customerNotes: 'x',
        );
        $this->setEntityId($rr, 7);

        // No need to actually write a photo blob, the auth gate
        // fires before the file read.
        $this->bindStandardEm(
            user: $stranger,
            returnRequestById: $rr,
            // findIdsByOwnerUser returns [] for the stranger
            vendorIdsForCallingUser: [],
        );

        $response = $this->makeGet($stranger, '/v3/returns/7/photos/20');
        self::assertSame(404, $response->getStatusCode());
    }

    #[Test]
    public function photoServeReturns401WithoutAuth(): void
    {
        $response = $this->handle($this->jsonRequest('GET', '/v3/returns/7/photos/20'));
        self::assertSame(401, $response->getStatusCode());
    }

    // =================================================================
    // Helpers
    // =================================================================

    /**
     * @param array{
     *   user: User,
     *   order?: ?Order,
     *   additionalOrders?: array<int, ?Order>,
     *   captureCallback?: ?callable,
     *   returnsForCustomerByOrder?: list<OrderReturnRequest>,
     *   returnRequestById?: ?OrderReturnRequest,
     *   photoByIdAndRequest?: ?OrderReturnRequestPhoto,
     *   vendorIdsForCallingUser?: list<int>,
     * } $opts
     */
    private function bindStandardEm(
        User $user,
        ?Order $order = null,
        ?callable $captureCallback = null,
        array $returnsForCustomerByOrder = [],
        ?OrderReturnRequest $returnRequestById = null,
        ?OrderReturnRequestPhoto $photoByIdAndRequest = null,
        array $vendorIdsForCallingUser = [],
        array $additionalOrders = [],
    ): void {
        $userRepo = $this->createMock(\Bayti\Api\Domain\User\UserRepository::class);
        $userRepo->method('findById')->willReturn($user);

        $orderRepo = $this->createMock(OrderRepository::class);
        $orderRepo->method('findForUser')->willReturn($order);

        $returnRepo = new class(
            $returnsForCustomerByOrder,
            $returnRequestById,
            $captureCallback,
        ) extends OrderReturnRequestRepository {
            /**
             * @param list<OrderReturnRequest> $returnsForCustomerByOrder
             */
            public function __construct(
                public readonly array $returnsForCustomerByOrder,
                public readonly ?OrderReturnRequest $returnRequestById,
                public readonly mixed $captureCallback,
            ) {
                // Intentionally skip parent EntityRepository ctor -
                // we override every method we need; $em + $class
                // properties stay uninitialized but unaccessed.
            }
            public function findForCustomerByOrder(User $customer, int $orderId): array
            {
                return $this->returnsForCustomerByOrder;
            }
            public function findById(int $id): ?OrderReturnRequest
            {
                return $this->returnRequestById;
            }
            public function hasOverlappingPendingForOrderItems(array $orderItemIds): bool
            {
                return false;
            }
            public function save(OrderReturnRequest $rr): void
            {
                if ($this->captureCallback !== null) {
                    ($this->captureCallback)($rr);
                }
            }
            public function getClassName(): string { return OrderReturnRequest::class; }
        };

        $photoRepo = new class($photoByIdAndRequest) extends OrderReturnRequestPhotoRepository {
            public function __construct(public readonly ?OrderReturnRequestPhoto $photo) {}
            public function findByIdAndRequest(int $photoId, int $returnRequestId): ?OrderReturnRequestPhoto
            {
                return $this->photo;
            }
            public function getClassName(): string { return OrderReturnRequestPhoto::class; }
        };

        $vendorRepo = $this->createMock(VendorRepository::class);
        $vendorRepo->method('findIdsByOwnerUser')->willReturn($vendorIdsForCallingUser);

        $em = $this->stubEm(function ($em) use ($userRepo, $orderRepo, $returnRepo, $photoRepo, $vendorRepo) {
            $em->method('getRepository')->willReturnMap([
                [User::class, $userRepo],
                [Order::class, $orderRepo],
                [OrderReturnRequest::class, $returnRepo],
                [OrderReturnRequestPhoto::class, $photoRepo],
                [Vendor::class, $vendorRepo],
            ]);
            $em->method('flush');
            $em->method('persist');
        });

        $this->bind(EntityManagerInterface::class, $em);

        // The DI factory for ReturnRequestEligibilityService pulls
        // the OrderReturnRequestRepository through $em->getRepository,
        // so we have to rebind the service against our mocked EM
        // (the production factory captured the boot-time EM).
        $this->bind(
            \Bayti\Api\Domain\Order\ReturnRequestEligibilityService::class,
            new \Bayti\Api\Domain\Order\ReturnRequestEligibilityService(
                returnRepo: $returnRepo,
            ),
        );
    }

    private function makeDeliveredOrder(User $user, int $orderId): Order
    {
        $order = new Order(
            user: $user,
            orderReference: "V3-RET-{$orderId}",
            subtotal: '100.00',
        );
        $this->setEntityId($order, $orderId);
        $this->setProp($order, 'paidAt', new DateTimeImmutable('-3 days', new DateTimeZone('UTC')));
        $this->setProp($order, 'status', Order::STATUS_DELIVERED);
        return $order;
    }

    private function makeVendor(int $id): Vendor
    {
        $v = (new \ReflectionClass(Vendor::class))->newInstanceWithoutConstructor();
        $this->setProp($v, 'id', $id);
        $this->setProp($v, 'name', "Vendor {$id}");
        $this->setProp($v, 'contactEmail', "vendor{$id}@example.com");
        return $v;
    }

    private function makeProduct(Vendor $vendor): Product
    {
        $p = (new \ReflectionClass(Product::class))->newInstanceWithoutConstructor();
        $this->setProp($p, 'id', random_int(200, 999));
        $this->setProp($p, 'name', 'Test product');
        $this->setProp($p, 'vendor', $vendor);
        return $p;
    }

    private function addDeliveredItem(Order $order, Vendor $vendor, int $id, int $qty, string $unitPrice): OrderItem
    {
        $product = $this->makeProduct($vendor);
        $item = new OrderItem(
            product: $product, vendor: $vendor,
            quantity: $qty, unitPrice: $unitPrice,
            productNameSnapshot: 'Test product', productImageSnapshot: 'x.jpg',
        );
        $this->setEntityId($item, $id);
        $this->setProp($item, 'itemStatus', OrderItem::ITEM_STATUS_DELIVERED);
        $order->addItem($item);
        return $item;
    }

    /**
     * Build a multipart-shaped request for the submit endpoint.
     * Slim's body parser would populate parsedBody from form fields
     * and uploadedFiles from the file parts, we set both directly
     * for test ergonomics.
     *
     * @param array<string, mixed> $formFields
     * @param array<string, mixed> $files
     */
    private function multipartRequest(
        string $method,
        string $uri,
        User $user,
        array $formFields,
        array $files,
    ): ServerRequestInterface {
        $request = $this->jsonRequest($method, $uri, $formFields, [
            'Authorization' => 'Bearer ' . $this->tokenFor($user),
            'Content-Type' => 'multipart/form-data; boundary=test',
        ]);
        $request = $request->withUploadedFiles($files);
        return $request;
    }

    private function tokenFor(User $user): string
    {
        $jwt = $this->app->getContainer()->get(JwtService::class);
        return $jwt->issueTokenPair($user)->accessToken;
    }

    private function makeGet(User $user, string $uri): ResponseInterface
    {
        return $this->handle($this->jsonRequest('GET', $uri, [], [
            'Authorization' => 'Bearer ' . $this->tokenFor($user),
        ]));
    }

    private function makeUpload(string $bytes, string $mime, string $filename): UploadedFile
    {
        $tmpFile = tempnam(sys_get_temp_dir(), 'rps-test');
        if ($tmpFile === false) {
            throw new \RuntimeException('Failed to create temp upload file.');
        }
        file_put_contents($tmpFile, $bytes);
        return new UploadedFile(
            $tmpFile,
            $filename,
            $mime,
            strlen($bytes),
            UPLOAD_ERR_OK,
        );
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

    private function rrmdir(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        $entries = scandir($dir);
        if ($entries === false) {
            return;
        }
        foreach ($entries as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $path = $dir . '/' . $entry;
            if (is_dir($path)) {
                $this->rrmdir($path);
            } else {
                @unlink($path);
            }
        }
        @rmdir($dir);
    }
}
