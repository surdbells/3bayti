<?php

declare(strict_types=1);

namespace Bayti\Api\Tests\Http\Controllers\Shipping;

use Bayti\Api\Domain\Audit\AuditEmitter;
use Bayti\Api\Domain\Audit\AuditLog;
use Bayti\Api\Domain\Catalog\Product;
use Bayti\Api\Domain\Catalog\Vendor;
use Bayti\Api\Domain\Order\Order;
use Bayti\Api\Domain\Order\OrderItem;
use Bayti\Api\Domain\Order\OrderShipment;
use Bayti\Api\Domain\Order\OrderShipmentRepository;
use Bayti\Api\Domain\User\User;
use Bayti\Api\Shipping\Oto\OtoWebhookVerifier;
use Bayti\Api\Tests\Http\HttpTestCase;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\Test;
use Psr\Http\Message\ResponseInterface;

/**
 * POST /v3/shipping/webhook/oto — OTO status callback.
 */
final class OtoWebhookControllerTest extends HttpTestCase
{
    /** @var array<int, AuditLog> */
    private array $recordedAuditLogs = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->recordedAuditLogs = [];
    }

    #[Test]
    public function appliesDeliveredStatusAndMarksTheStoresItemsDelivered(): void
    {
        [$item, $shipment] = $this->makeBookedShipment('14796940', OrderItem::ITEM_STATUS_SHIPPED);
        $this->bindEm($shipment);

        $response = $this->postWebhook([
            'otoId' => '14796940',
            'orderId' => '3B-9-V7',
            'status' => 'Delivered',
            'trackingNumber' => 'TRK123',
            'deliveryCompany' => 'fastExpress',
        ]);

        self::assertSame(200, $response->getStatusCode(), (string) $response->getBody());
        self::assertSame(OrderShipment::STATUS_DELIVERED, $shipment->getStatus());
        self::assertSame('TRK123', $shipment->getTrackingNumber());
        self::assertSame('fastExpress', $shipment->getDeliveryCompany());
        self::assertSame(OrderItem::ITEM_STATUS_DELIVERED, $item->getItemStatus());
        self::assertCount(1, $this->recordedAuditLogs);
    }

    #[Test]
    public function acksAnUnknownShipmentWith200SoOtoDoesNotRetry(): void
    {
        $this->bindEm(null); // findByProviderOrderId → null
        $response = $this->postWebhook(['otoId' => 'does-not-exist', 'status' => 'Delivered']);

        self::assertSame(200, $response->getStatusCode());
        self::assertStringContainsString('unknown_shipment', (string) $response->getBody());
    }

    #[Test]
    public function rejectsAnUnauthorizedWebhookWhenAKeyIsConfigured(): void
    {
        [, $shipment] = $this->makeBookedShipment('14796940', OrderItem::ITEM_STATUS_SHIPPED);
        $this->bindEm($shipment);
        // A configured verifier with no matching header/body → 401, nothing applied.
        $this->bind(OtoWebhookVerifier::class, new OtoWebhookVerifier(secret: null, authKey: 'shhh'));

        $response = $this->postWebhook(['otoId' => '14796940', 'status' => 'Delivered']);

        self::assertSame(401, $response->getStatusCode());
        self::assertSame(OrderShipment::STATUS_BOOKED, $shipment->getStatus());
    }

    // ===== Helpers =====

    /**
     * @param array<string, mixed> $payload
     */
    private function postWebhook(array $payload): ResponseInterface
    {
        return $this->handle($this->jsonRequest('POST', '/v3/shipping/webhook/oto', $payload));
    }

    /** @return array{0: OrderItem, 1: OrderShipment} */
    private function makeBookedShipment(string $otoId, string $itemStatus): array
    {
        $vendor = new Vendor('store-7', 'ABAYA BY MAS', 'v7@example.test');
        $this->setProp($vendor, 'id', 7);

        $user = new User('c@example.com', '+971500000000', password_hash('p', PASSWORD_BCRYPT), 'AE');
        $this->setProp($user, 'id', 42);

        $order = new Order(user: $user, orderReference: '3B-9', subtotal: '265.00');
        $this->setProp($order, 'id', 9);

        $product = (new \ReflectionClass(Product::class))->newInstanceWithoutConstructor();
        $this->setProp($product, 'id', 200);
        $this->setProp($product, 'name', 'Beach Waves');
        $this->setProp($product, 'vendor', $vendor);

        $item = new OrderItem(
            product: $product,
            vendor: $vendor,
            quantity: 1,
            unitPrice: '265.00',
            productNameSnapshot: 'Beach Waves',
        );
        $this->setProp($item, 'id', 701);
        $this->setProp($item, 'itemStatus', $itemStatus);
        $order->addItem($item);

        $shipment = new OrderShipment($order, $vendor);
        $this->setProp($shipment, 'id', 500); // as if already persisted at booking
        $shipment->markBooked($otoId);

        return [$item, $shipment];
    }

    private function bindEm(?OrderShipment $shipment): void
    {
        $shipmentRepo = $this->createMock(OrderShipmentRepository::class);
        $shipmentRepo->method('findByProviderOrderId')->willReturn($shipment);

        $auditSink = &$this->recordedAuditLogs;
        $auditRepo = new class($auditSink) extends \Doctrine\ORM\EntityRepository {
            /** @param array<int, AuditLog> $sink */
            public function __construct(private array &$sink)
            {
            }
            public function save(AuditLog $log): void
            {
                $this->sink[] = $log;
            }
            public function getClassName(): string
            {
                return AuditLog::class;
            }
        };

        $em = $this->stubEm(function ($em) use ($shipmentRepo, $auditRepo) {
            $em->method('getRepository')->willReturnMap([
                [OrderShipment::class, $shipmentRepo],
                [AuditLog::class, $auditRepo],
            ]);
            $em->method('persist');
            $em->method('flush');
        });

        $this->bind(EntityManagerInterface::class, $em);
        $this->bind(AuditEmitter::class, new AuditEmitter($em, new \Psr\Log\NullLogger()));
    }

    private function setProp(object $entity, string $prop, mixed $value): void
    {
        $ref = new \ReflectionProperty($entity::class, $prop);
        $ref->setAccessible(true);
        $ref->setValue($entity, $value);
    }
}
