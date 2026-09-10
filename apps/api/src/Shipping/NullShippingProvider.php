<?php

declare(strict_types=1);

namespace Bayti\Api\Shipping;

use Bayti\Api\Domain\Catalog\Vendor;
use Bayti\Api\Domain\Order\Order;

/**
 * No-op shipping provider used when no courier provider is configured
 * (TRYOTO_ENABLED unset / missing refresh token). Lets the container resolve
 * and the app boot; callers must guard on isEnabled() before booking, and any
 * accidental createShipment() call fails loudly rather than silently no-oping.
 */
final class NullShippingProvider implements ShippingProviderInterface
{
    public function isEnabled(): bool
    {
        return false;
    }

    public function createShipment(
        Order $order,
        Vendor $vendor,
        array $vendorItems,
        ?string $deliveryOptionId = null,
    ): ShipmentResult {
        throw ShippingException::notConfigured();
    }

    public function listDeliveryOptions(Order $order, Vendor $vendor, array $vendorItems): array
    {
        return [];
    }

    public function listPickupLocations(): array
    {
        return [];
    }

    public function createPickupLocation(array $input): array
    {
        throw ShippingException::notConfigured();
    }
}
