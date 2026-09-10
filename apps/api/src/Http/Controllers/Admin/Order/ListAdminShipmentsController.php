<?php

declare(strict_types=1);

namespace Bayti\Api\Http\Controllers\Admin\Order;

use Bayti\Api\Domain\Order\OrderShipment;
use Bayti\Api\Domain\Order\OrderShipmentRepository;
use Bayti\Api\Domain\User\User;
use Bayti\Api\Http\Errors\ErrorCodes;
use Bayti\Api\Http\Errors\HttpException;
use Bayti\Api\Http\Middleware\AuthMiddleware;
use Bayti\Api\Http\Responder;
use Bayti\Api\Http\Serializers\ShipmentSerializer;
use Bayti\Api\Shipping\ShipmentBookingService;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * GET /v3/admin/shipments
 *
 * The admin "Deliveries" queue: one place to push orders to the courier (OTO).
 *   - `pending`: per (order, store) groups whose items are ready to ship
 *     (accepted/preparing) but have no shipment yet — each bookable from here.
 *   - `booked`: the recent shipments with their carrier + tracking + status.
 *
 * Booking itself reuses POST /admin/orders/{id}/vendors/{vid}/ship. Gated by
 * orders.view.
 */
final class ListAdminShipmentsController
{
    use Responder;

    public function __construct(
        protected readonly ResponseFactoryInterface $responseFactory,
        private readonly EntityManagerInterface $em,
        private readonly ShipmentSerializer $shipmentSerializer,
        private readonly ShipmentBookingService $booking,
    ) {
    }

    protected function getResponseFactory(): ResponseFactoryInterface
    {
        return $this->responseFactory;
    }

    public function __invoke(ServerRequestInterface $request): ResponseInterface
    {
        $user = $request->getAttribute(AuthMiddleware::ATTR_USER);
        if (!$user instanceof User) {
            throw HttpException::unauthorized(ErrorCodes::AUTH_INVALID_TOKEN, 'Authentication required.');
        }

        return $this->ok([
            'enabled' => $this->booking->isEnabled(),
            'pending' => $this->pendingBookings(),
            'booked' => $this->bookedShipments(),
        ]);
    }

    /**
     * (order, store) groups with items ready to ship (accepted/preparing) and no
     * shipment yet. One raw aggregate query, newest orders first.
     *
     * @return list<array<string, mixed>>
     */
    private function pendingBookings(): array
    {
        $sql = <<<SQL
            SELECT o.id AS order_id, o.order_reference, o.created_at,
                   oi.vendor_id, v.name AS vendor_name,
                   COUNT(*) AS ready_count
            FROM order_items oi
            JOIN orders o ON o.id = oi.order_id
            JOIN vendors v ON v.id = oi.vendor_id
            WHERE oi.item_status IN ('accepted', 'preparing')
              AND o.deleted_at IS NULL
              AND NOT EXISTS (
                  SELECT 1 FROM order_shipments s
                  WHERE s.order_id = o.id AND s.vendor_id = oi.vendor_id
              )
            GROUP BY o.id, o.order_reference, o.created_at, oi.vendor_id, v.name
            ORDER BY o.created_at DESC
            LIMIT 200
            SQL;

        $rows = $this->em->getConnection()->fetchAllAssociative($sql);

        return array_map(static fn (array $r): array => [
            'order_id' => (int) $r['order_id'],
            'order_reference' => (string) $r['order_reference'],
            'created_at' => (string) $r['created_at'],
            'vendor_id' => (int) $r['vendor_id'],
            'vendor_name' => (string) $r['vendor_name'],
            'ready_count' => (int) $r['ready_count'],
        ], $rows);
    }

    /**
     * Recent booked shipments, each with its order + tracking context.
     *
     * @return list<array<string, mixed>>
     */
    private function bookedShipments(): array
    {
        /** @var OrderShipmentRepository $repo */
        $repo = $this->em->getRepository(OrderShipment::class);

        $out = [];
        foreach ($repo->findRecent(100) as $s) {
            $order = $s->getOrder();
            $out[] = $this->shipmentSerializer->shape($s) + [
                'order_id' => $order->getId(),
                'order_reference' => $order->getOrderReference(),
                'created_at' => $order->getCreatedAt()->format(\DateTimeInterface::ATOM),
            ];
        }
        return $out;
    }
}
