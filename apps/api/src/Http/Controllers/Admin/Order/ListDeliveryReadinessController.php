<?php

declare(strict_types=1);

namespace Bayti\Api\Http\Controllers\Admin\Order;

use Bayti\Api\Domain\Order\DeliveryReadinessCalculator;
use Bayti\Api\Domain\Order\Order;
use Bayti\Api\Domain\Order\OrderAddress;
use Bayti\Api\Domain\Order\OrderItem;
use Bayti\Api\Domain\Order\OrderRepository;
use Bayti\Api\Domain\User\User;
use Bayti\Api\Http\Errors\ErrorCodes;
use Bayti\Api\Http\Errors\HttpException;
use Bayti\Api\Http\Middleware\AuthMiddleware;
use Bayti\Api\Http\Responder;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * GET /v3/admin/delivery-readiness
 *
 * The operations / logistics "when is each order ready to go" board. One row
 * per active order, answering — without opening the order — the four questions
 * ops actually asks: what's IN it, WHO ships each item (vendor + pickup), WHEN
 * each item is expected to be ready (vendor-configured per-product lead time),
 * and WHERE it's going (customer + destination).
 *
 * Readiness is computed per item and rolled up per order by
 * {@see DeliveryReadinessCalculator}: an order lands in exactly one bucket —
 * `overdue` (fully ready, past due), `ready` (fully ready today), `waiting`
 * (still waiting on its slowest item) — plus flags (due_today,
 * awaiting_acceptance). The list is intentionally scoped to orders that STILL
 * have something to dispatch; fully-shipped ones fall off.
 *
 * Gated by orders.view. Read-only — booking still happens via the Deliveries
 * queue / order detail ("Book delivery").
 */
final class ListDeliveryReadinessController
{
    use Responder;

    /** Business day boundary the readiness dates are evaluated against. */
    private const BUSINESS_TZ = 'Asia/Dubai';

    /** Cap on the number of orders scanned per load. */
    private const LIMIT = 150;

    public function __construct(
        protected readonly ResponseFactoryInterface $responseFactory,
        private readonly EntityManagerInterface $em,
        private readonly DeliveryReadinessCalculator $calculator,
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

        $today = new \DateTimeImmutable('now', new \DateTimeZone(self::BUSINESS_TZ));

        /** @var OrderRepository $orders */
        $orders = $this->em->getRepository(Order::class);

        $rows = [];
        foreach ($orders->findForDeliveryReadiness(self::LIMIT) as $order) {
            $rows[] = $this->shapeOrder($order, $today);
        }

        return $this->ok([
            'generated_at' => $today->format(\DateTimeInterface::ATOM),
            'orders' => $rows,
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function shapeOrder(Order $order, \DateTimeImmutable $today): array
    {
        $readiness = $this->calculator->forOrder($order, $today);
        $itemMeta = $readiness['items'];

        // Group line items under their vendor (one pickup per store).
        $vendors = [];
        foreach ($order->getItems() as $item) {
            $meta = $itemMeta[$item->getId() ?? 0] ?? null;
            if ($meta === null || $meta['state'] === 'terminal') {
                continue; // rejected/cancelled/etc — not part of the delivery pipeline.
            }
            $vendor = $item->getVendor();
            $vid = $vendor->getId() ?? 0;
            if (!isset($vendors[$vid])) {
                $vendors[$vid] = [
                    'vendor_id' => $vid,
                    'vendor_name' => $vendor->getName(),
                    'vendor_slug' => $vendor->getSlug(),
                    'pickup' => [
                        'location_code' => $vendor->getPickupLocationCode(),
                        'contact_name' => $vendor->getPickupContactName(),
                        'phone' => $vendor->getPickupPhone() ?? $vendor->getContactPhone(),
                        'city' => $vendor->getPickupCity(),
                        'is_complete' => $vendor->pickupAddressIsComplete(),
                    ],
                    'items' => [],
                ];
            }
            $vendors[$vid]['items'][] = $this->shapeItem($item, $meta);
        }

        return [
            'order_id' => $order->getId() ?? 0,
            'order_reference' => $order->getOrderReference(),
            'status' => $order->getStatus(),
            'channel' => $order->getChannel(),
            'created_at' => $order->getCreatedAt()->format(\DateTimeInterface::ATOM),
            'readiness' => $readiness['readiness'],
            'bucket' => $readiness['bucket'],
            'is_overdue' => $readiness['is_overdue'],
            'due_today' => $readiness['due_today'],
            'awaiting_acceptance' => $readiness['awaiting_acceptance'],
            'bottleneck_date' => $readiness['bottleneck_date'],
            'ready_count' => $readiness['ready_count'],
            'unshipped_count' => $readiness['unshipped_count'],
            'active_count' => $readiness['active_count'],
            'customer' => $this->customerBlock($order),
            'delivery' => $this->deliveryBlock($order->getShippingAddress()),
            'vendors' => array_values($vendors),
        ];
    }

    /**
     * @param array{expected_ready_date:string, lead_days:int, lead_source:string, state:string, is_late:bool} $meta
     * @return array<string, mixed>
     */
    private function shapeItem(OrderItem $item, array $meta): array
    {
        $product = $item->getProduct();

        // Mirror OrderSerializer::itemShape's image fallback: legacy snapshots
        // point at the decommissioned host, so prefer the product's current image.
        $image = $item->getProductImageSnapshot();
        if ($image === null || $image === '' || str_contains($image, 'api.3bayti.ae')) {
            $image = $product->getPrimaryImageUrl() ?? $image;
        }

        return [
            'id' => $item->getId() ?? 0,
            'product_id' => $product->getId() ?? 0,
            'product_name' => $item->getProductNameSnapshot(),
            'product_image' => $image,
            'quantity' => $item->getQuantity(),
            'item_status' => $item->getItemStatus(),
            'expected_ready_date' => $meta['expected_ready_date'],
            'lead_days' => $meta['lead_days'],
            'lead_source' => $meta['lead_source'],
            'state' => $meta['state'],
            'is_late' => $meta['is_late'],
        ];
    }

    /**
     * @return array{id:int, name:string|null, phone:string|null, email:string|null}
     */
    private function customerBlock(Order $order): array
    {
        $u = $order->getUser();
        $name = trim(($u->getFirstName() ?? '') . ' ' . ($u->getLastName() ?? ''));
        return [
            'id' => $u->getId() ?? 0,
            'name' => $name !== '' ? $name : null,
            'phone' => $u->getPhone(),
            'email' => $u->getEmail(),
        ];
    }

    /**
     * @return array{name:string|null, phone:string|null, street:string|null, city:string|null, state:string|null, country:string|null, postcode:string|null}|null
     */
    private function deliveryBlock(?OrderAddress $a): ?array
    {
        if ($a === null) {
            return null;
        }
        $name = trim($a->getFirstName() . ' ' . ($a->getLastName() ?? ''));
        return [
            'name' => $name !== '' ? $name : null,
            'phone' => $a->getPhone(),
            'street' => $a->getStreet(),
            'city' => $a->getCity(),
            'state' => $a->getStateProvince(),
            'country' => $a->getCountryCode(),
            'postcode' => $a->getPostalCode(),
        ];
    }
}
