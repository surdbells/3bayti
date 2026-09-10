<?php

declare(strict_types=1);

namespace Bayti\Api\Shipping;

use Bayti\Api\Domain\Catalog\Vendor;
use Bayti\Api\Domain\Order\Order;
use Bayti\Api\Domain\Order\OrderItem;

/**
 * Books courier delivery for an order's items.
 *
 * The application (ship endpoints, webhook) depends on this interface, never on
 * a concrete provider (OTO today). A NullShippingProvider stands in when no
 * provider is configured, so the app boots and every caller can guard on
 * isEnabled() — mirroring the SmsSenderInterface / NullSmsSender pattern.
 *
 * 3bayti is a multi-vendor marketplace, so a single order splits into one
 * shipment PER VENDOR (pickup from that store → the customer). Every method
 * here therefore operates on ONE vendor's slice of an order.
 */
interface ShippingProviderInterface
{
    /** True only for a real, configured provider. */
    public function isEnabled(): bool;

    /**
     * Push one vendor's items to the courier network and (unless a specific
     * option is requested) let it auto-assign a carrier. Returns the created
     * shipment, or throws ShippingException.
     *
     * @param OrderItem[] $vendorItems the subset of the order's items for $vendor
     * @param string|null $deliveryOptionId a specific carrier option to force;
     *        null lets the provider auto-assign
     * @throws ShippingException
     */
    public function createShipment(
        Order $order,
        Vendor $vendor,
        array $vendorItems,
        ?string $deliveryOptionId = null,
    ): ShipmentResult;

    /**
     * Available delivery options (carrier + price) for a vendor's items, for
     * the "choose a carrier" flow. Returns an empty list when the provider is
     * disabled or can't quote.
     *
     * @param OrderItem[] $vendorItems
     * @return list<array{id: string, name: string, price: float, currency: string, eta?: string|null, company?: string|null}>
     */
    public function listDeliveryOptions(Order $order, Vendor $vendor, array $vendorItems): array;

    /**
     * The provider's registered pickup/sender locations (for mapping a vendor to
     * its sender in the OTO portal via a searchable dropdown). Empty when the
     * provider is disabled or can't list them.
     *
     * @return list<array{code: string, name: string, city: string|null}>
     */
    public function listPickupLocations(): array;

    /**
     * Register a NEW pickup/sender location with the provider (so an admin can
     * add a store's location inline when it isn't already in the OTO portal) and
     * return the created location for immediate selection. Throws when the
     * provider is disabled or the create is rejected.
     *
     * @param array{
     *     code: string, name: string, contact_name: string, contact_email: string,
     *     phone: string, address: string, city: string,
     *     country?: string, type?: string, postcode?: string|null,
     *     lat?: float|string|null, lon?: float|string|null
     * } $input
     * @return array{code: string, name: string, city: string|null}
     * @throws ShippingException
     */
    public function createPickupLocation(array $input): array;
}
