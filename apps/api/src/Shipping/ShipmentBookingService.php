<?php

declare(strict_types=1);

namespace Bayti\Api\Shipping;

use Bayti\Api\Domain\Audit\AuditEmitter;
use Bayti\Api\Domain\Catalog\Vendor;
use Bayti\Api\Domain\Order\Order;
use Bayti\Api\Domain\Order\OrderItem;
use Bayti\Api\Domain\Order\OrderShipment;
use Bayti\Api\Domain\Order\OrderShipmentRepository;
use Bayti\Api\Domain\User\User;
use Bayti\Api\Notification\OrderNotificationService;
use Bayti\Api\Notification\Push\PushNotificationService;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * Books courier delivery for ONE vendor's slice of an order, shared by the
 * vendor and admin ship endpoints so the flow lives in one place.
 *
 * On success it: pushes the vendor's shippable items to the provider (OTO),
 * persists/updates the per-vendor OrderShipment with the returned id/tracking,
 * advances those line items to `shipped`, rolls the order status up, audits the
 * booking, and best-effort notifies the customer. Precondition failures throw a
 * ShippingException whose `kind` maps to an HTTP status via httpStatusFor().
 */
final class ShipmentBookingService
{
    private LoggerInterface $logger;

    public function __construct(
        private readonly ShippingProviderInterface $provider,
        private readonly EntityManagerInterface $em,
        private readonly AuditEmitter $audit,
        private readonly OrderNotificationService $notifications,
        private readonly PushNotificationService $push,
        ?LoggerInterface $logger = null,
    ) {
        $this->logger = $logger ?? new NullLogger();
    }

    public function isEnabled(): bool
    {
        return $this->provider->isEnabled();
    }

    /**
     * @throws ShippingException
     */
    public function bookForVendor(
        Order $order,
        Vendor $vendor,
        ?string $deliveryOptionId,
        User $actor,
        ?ServerRequestInterface $request = null,
    ): OrderShipment {
        if (!$this->provider->isEnabled()) {
            throw ShippingException::notConfigured();
        }

        /** @var OrderShipmentRepository $shipmentRepo */
        $shipmentRepo = $this->em->getRepository(OrderShipment::class);
        $existing = $shipmentRepo->findForOrderAndVendor($order, $vendor);
        if ($existing !== null && $existing->getProviderOrderId() !== null) {
            throw new ShippingException(
                ShippingException::KIND_ALREADY_BOOKED,
                'This store already has a booked shipment for this order.',
            );
        }

        $items = $this->shippableItems($order, $vendor);
        if ($items === []) {
            throw new ShippingException(
                ShippingException::KIND_NO_ITEMS,
                'No items are ready to ship for this store on this order.',
            );
        }
        if (!$vendor->pickupAddressIsComplete()) {
            throw ShippingException::incompletePickup();
        }

        // Push to the courier network (may throw ShippingException on a
        // provider/network failure → mapped to 502 upstream).
        $result = $this->provider->createShipment($order, $vendor, $items, $deliveryOptionId);

        $shipment = $existing ?? new OrderShipment($order, $vendor);
        $shipment->markBooked(
            $result->providerOrderId,
            $result->trackingNumber,
            $result->deliveryCompany,
            $deliveryOptionId,
        );
        $this->em->persist($shipment);

        // Dispatch the line items (accepted/preparing → shipped).
        foreach ($items as $item) {
            $this->advanceToShipped($item);
        }
        $order->recomputeStatusFromItems();
        $this->em->flush();

        $this->audit->recordCreate(
            request: $request,
            actor: $actor,
            subject: $shipment,
            afterSnapshot: [
                'order_reference' => $order->getOrderReference(),
                'vendor_id' => $vendor->getId(),
                'provider' => $shipment->getProvider(),
                'provider_order_id' => $shipment->getProviderOrderId(),
                'tracking_number' => $shipment->getTrackingNumber(),
                'delivery_company' => $shipment->getDeliveryCompany(),
            ],
        );

        $this->notifyShipped($order, $items[0]);

        $this->logger->info('shipping.booked', [
            'order_reference' => $order->getOrderReference(),
            'vendor_id' => $vendor->getId(),
            'provider_order_id' => $shipment->getProviderOrderId(),
            'items' => count($items),
            'actor_id' => $actor->getId(),
        ]);

        return $shipment;
    }

    /**
     * Carrier options (id + name + price) for a vendor's shippable items, for
     * the "choose a carrier" flow. Empty when disabled or nothing to quote.
     *
     * @return list<array{id: string, name: string, price: float, currency: string, eta?: string|null, company?: string|null}>
     */
    public function listOptionsForVendor(Order $order, Vendor $vendor): array
    {
        if (!$this->provider->isEnabled()) {
            return [];
        }
        $items = $this->shippableItems($order, $vendor);
        if ($items === []) {
            return [];
        }
        return $this->provider->listDeliveryOptions($order, $vendor, $items);
    }

    /**
     * This vendor's items on the order that are ready to ship (accepted or
     * preparing). Already-shipped / delivered / rejected / cancelled items are
     * excluded.
     *
     * @return list<OrderItem>
     */
    private function shippableItems(Order $order, Vendor $vendor): array
    {
        $vendorId = $vendor->getId();
        $items = [];
        foreach ($order->getItems() as $item) {
            if ($item->getVendor()->getId() !== $vendorId) {
                continue;
            }
            if (in_array($item->getItemStatus(), [
                OrderItem::ITEM_STATUS_ACCEPTED,
                OrderItem::ITEM_STATUS_PREPARING,
            ], true)) {
                $items[] = $item;
            }
        }
        return $items;
    }

    private function advanceToShipped(OrderItem $item): void
    {
        if ($item->getItemStatus() === OrderItem::ITEM_STATUS_ACCEPTED) {
            $item->setItemStatus(OrderItem::ITEM_STATUS_PREPARING);
        }
        if ($item->getItemStatus() === OrderItem::ITEM_STATUS_PREPARING) {
            $item->setItemStatus(OrderItem::ITEM_STATUS_SHIPPED);
        }
    }

    /** Best-effort "shipped" notification; never blocks the booking. */
    private function notifyShipped(Order $order, OrderItem $item): void
    {
        try {
            $this->notifications->itemShipped($order, $item);
            $this->push->itemShipped($order);
        } catch (\Throwable $e) {
            $this->logger->warning('shipping.notify_failed', [
                'order_reference' => $order->getOrderReference(),
                'error' => $e->getMessage(),
            ]);
        }
    }
}
