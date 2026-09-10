<?php

declare(strict_types=1);

namespace Bayti\Api\Http\Controllers\Admin\Order;

use Bayti\Api\Domain\Order\DeliveryReadinessCalculator;
use Bayti\Api\Domain\Order\OrderShipment;
use Bayti\Api\Domain\Order\OrderShipmentRepository;
use Bayti\Api\Domain\User\User;
use Bayti\Api\Http\Errors\ErrorCodes;
use Bayti\Api\Http\Errors\HttpException;
use Bayti\Api\Http\Middleware\AuthMiddleware;
use Bayti\Api\Http\Responder;
use Bayti\Api\Http\Serializers\ShipmentSerializer;
use Bayti\Api\Shipping\ShipmentBookingService;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\ParameterType;
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

    /** Business day boundary the expected-ready dates are evaluated against. */
    private const BUSINESS_TZ = 'Asia/Dubai';

    /** Cap on the number of oldest bookable orders scanned per load. */
    private const ORDER_SCAN_LIMIT = 200;

    /**
     * (order, store) groups with items ready to ship (accepted/preparing) and no
     * shipment yet. Each group also carries the EXPECTED-READY date — the latest
     * expected-ready date among its items (when the whole store slice can be
     * picked up), estimated the same way as the Delivery-readiness board:
     * order.created_at + the vendor-configured per-product lead time
     * (Product.delivery_info) falling back to the vendor's max_delivery_days.
     *
     * Two steps so the DB fetch stays bounded: (1) the OLDEST bookable orders,
     * DB-capped (oldest first, since those are the most likely overdue and the
     * ones ops must action first); (2) their accepted/preparing, unshipped items
     * at item granularity (so per-product lead times are available), rolled up
     * per (order, store) with the date math in PHP. Rows are ordered by
     * expected-ready ascending, so overdue / soonest bookings surface first.
     *
     * @return list<array<string, mixed>>
     */
    private function pendingBookings(): array
    {
        $conn = $this->em->getConnection();

        // Step 1 — bounded set of the oldest orders that still have a bookable
        // (accepted/preparing, no shipment) store slice. Caps the item scan below.
        $idSql = <<<SQL
            SELECT o.id
            FROM orders o
            WHERE o.deleted_at IS NULL
              AND EXISTS (
                  SELECT 1 FROM order_items oi
                  WHERE oi.order_id = o.id
                    AND oi.item_status IN ('accepted', 'preparing')
                    AND NOT EXISTS (
                        SELECT 1 FROM order_shipments s
                        WHERE s.order_id = o.id AND s.vendor_id = oi.vendor_id
                    )
              )
            ORDER BY o.created_at ASC
            LIMIT :limit
            SQL;

        $orderIds = $conn->fetchFirstColumn($idSql, ['limit' => self::ORDER_SCAN_LIMIT], ['limit' => ParameterType::INTEGER]);
        if ($orderIds === []) {
            return [];
        }

        // Step 2 — the bookable items of just those orders, at item granularity.
        $sql = <<<SQL
            SELECT o.id AS order_id, o.order_reference, o.created_at,
                   oi.vendor_id, v.name AS vendor_name, v.max_delivery_days,
                   p.delivery_info
            FROM order_items oi
            JOIN orders o ON o.id = oi.order_id
            JOIN vendors v ON v.id = oi.vendor_id
            JOIN products p ON p.id = oi.product_id
            WHERE oi.order_id IN (:ids)
              AND oi.item_status IN ('accepted', 'preparing')
              AND NOT EXISTS (
                  SELECT 1 FROM order_shipments s
                  WHERE s.order_id = o.id AND s.vendor_id = oi.vendor_id
              )
            SQL;

        $rows = $conn->fetchAllAssociative($sql, ['ids' => $orderIds], ['ids' => ArrayParameterType::INTEGER]);

        $tz = new \DateTimeZone(self::BUSINESS_TZ);
        $today = (new \DateTimeImmutable('now', $tz))->format('Y-m-d');

        /** @var array<string, array<string, mixed>> $groups keyed by "order:vendor" */
        $groups = [];
        foreach ($rows as $r) {
            $key = $r['order_id'] . ':' . $r['vendor_id'];

            $info = $r['delivery_info'] !== null ? json_decode((string) $r['delivery_info'], true) : null;
            $days = DeliveryReadinessCalculator::leadDaysFromDeliveryInfo(is_array($info) ? $info : null)
                ?? max(0, (int) $r['max_delivery_days']);
            $expected = (new \DateTimeImmutable((string) $r['created_at']))
                ->setTimezone($tz)
                ->modify('+' . $days . ' days')
                ->format('Y-m-d');

            if (!isset($groups[$key])) {
                $groups[$key] = [
                    'order_id' => (int) $r['order_id'],
                    'order_reference' => (string) $r['order_reference'],
                    'created_at' => (string) $r['created_at'],
                    'vendor_id' => (int) $r['vendor_id'],
                    'vendor_name' => (string) $r['vendor_name'],
                    'ready_count' => 0,
                    'expected_ready_date' => $expected,
                ];
            }
            $groups[$key]['ready_count']++;
            // The store slice is ready only once its SLOWEST item is ready.
            if ($expected > $groups[$key]['expected_ready_date']) {
                $groups[$key]['expected_ready_date'] = $expected;
            }
        }

        $out = array_values($groups);
        foreach ($out as &$g) {
            $g['is_overdue'] = $g['expected_ready_date'] < $today;
            $g['due_today'] = $g['expected_ready_date'] === $today;
        }
        unset($g);

        // Overdue / soonest-ready first; cap the queue.
        usort($out, static function (array $a, array $b): int {
            return [$a['expected_ready_date'], $a['created_at']] <=> [$b['expected_ready_date'], $b['created_at']];
        });

        return array_slice($out, 0, 200);
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
