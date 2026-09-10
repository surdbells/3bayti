<?php

declare(strict_types=1);

namespace Bayti\Api\Shipping\Oto;

use Bayti\Api\Domain\Catalog\Vendor;
use Bayti\Api\Domain\Order\Order;
use Bayti\Api\Shipping\ShipmentResult;
use Bayti\Api\Shipping\ShippingException;
use Bayti\Api\Shipping\ShippingProviderInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * OTO (tryoto.com) implementation of the shipping provider: builds the
 * createOrder payload for one vendor's slice of an order and pushes it,
 * auto-booking a courier (createShipment: true) unless a specific option is
 * forced. Selected by the DI container only when OTO is configured
 * (TRYOTO_ENABLED + refresh token); otherwise NullShippingProvider is used.
 */
final class OtoShippingProvider implements ShippingProviderInterface
{
    private LoggerInterface $logger;

    public function __construct(
        private readonly OtoClient $client,
        private readonly OtoOrderPayloadBuilder $builder,
        ?LoggerInterface $logger = null,
    ) {
        $this->logger = $logger ?? new NullLogger();
    }

    public function isEnabled(): bool
    {
        return true;
    }

    public function createShipment(
        Order $order,
        Vendor $vendor,
        array $vendorItems,
        ?string $deliveryOptionId = null,
    ): ShipmentResult {
        if ($vendorItems === []) {
            throw new ShippingException(
                ShippingException::KIND_TRANSPORT,
                'No items to ship for this store on this order.',
            );
        }

        $body = $this->builder->build($order, $vendor, $vendorItems, $deliveryOptionId);
        $response = $this->client->createOrder($body);

        $otoId = $response['otoId'] ?? null;
        if ($otoId === null || $otoId === '') {
            throw new ShippingException(
                ShippingException::KIND_MALFORMED,
                'OTO createOrder response is missing otoId.',
            );
        }

        $this->logger->info('oto.shipment_created', [
            'order_reference' => $order->getOrderReference(),
            'vendor_id' => $vendor->getId(),
            'oto_id' => (string) $otoId,
        ]);

        return new ShipmentResult(
            providerOrderId: (string) $otoId,
            trackingNumber: $this->str($response['trackingNumber'] ?? null),
            deliveryCompany: $this->str($response['deliveryCompany'] ?? null),
            raw: $response,
        );
    }

    public function listDeliveryOptions(Order $order, Vendor $vendor, array $vendorItems): array
    {
        if ($vendorItems === []) {
            return [];
        }

        try {
            $body = $this->builder->build($order, $vendor, $vendorItems, null);
            // Quoting only — never create a shipment while listing options.
            $body['createShipment'] = false;
            $response = $this->client->checkDeliveryFees($body);
        } catch (ShippingException $e) {
            $this->logger->warning('oto.list_options_failed', [
                'order_reference' => $order->getOrderReference(),
                'vendor_id' => $vendor->getId(),
                'error' => $e->getMessage(),
            ]);
            return [];
        }

        return $this->parseDeliveryOptions($response, $order->getCurrency());
    }

    public function listPickupLocations(): array
    {
        try {
            $response = $this->client->listPickupLocations();
        } catch (ShippingException $e) {
            $this->logger->warning('oto.list_pickup_locations_failed', ['error' => $e->getMessage()]);
            return [];
        }

        // Shape varies by account/version — look over the common container keys.
        $rows = $response['pickupLocations'] ?? $response['data'] ?? $response['locations'] ?? $response['warehouses'] ?? [];
        if (!is_array($rows)) {
            return [];
        }

        $out = [];
        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }
            $code = $row['code'] ?? $row['pickupLocationCode'] ?? $row['locationCode'] ?? null;
            if ($code === null || $code === '') {
                continue;
            }
            $city = $row['city'] ?? null;
            $out[] = [
                'code' => (string) $code,
                'name' => (string) ($row['name'] ?? $row['locationName'] ?? $code),
                'city' => $city !== null ? (string) $city : null,
            ];
        }
        return $out;
    }

    public function createPickupLocation(array $input): array
    {
        $city = trim($input['city']);
        // OTO requires an ISO 3166-1 alpha-2 country code. Fall back to AE
        // (UAE-only platform) for any non-ISO2 value, e.g. a free-text name.
        $country = strtoupper(trim($input['country'] ?? 'AE'));
        if (preg_match('/^[A-Z]{2}$/', $country) !== 1) {
            $country = 'AE';
        }
        $payload = [
            'name' => trim($input['name']),
            'code' => trim($input['code']),
            'mobile' => trim($input['phone']),
            'address' => trim($input['address']),
            'city' => $city,
            'country' => $country,
            'contactName' => trim($input['contact_name']),
            'contactEmail' => trim($input['contact_email']),
            'type' => in_array($input['type'] ?? '', ['branch', 'warehouse'], true) ? $input['type'] : 'warehouse',
            'status' => 'active',
        ];
        $postcode = $this->str($input['postcode'] ?? null);
        if ($postcode !== null) {
            $payload['postcode'] = $postcode;
        }
        // Geo pin (from Google Places) improves OTO's courier auto-assignment.
        $lat = $this->num($input['lat'] ?? null);
        $lon = $this->num($input['lon'] ?? null);
        if ($lat !== null && $lon !== null) {
            $payload['lat'] = $lat;
            $payload['lon'] = $lon;
        }

        // Propagates ShippingException (auth/transport/soft-failure) to the caller,
        // which maps it to an HTTP status — the admin must know if the create failed.
        $response = $this->client->createPickupLocation($payload);

        // OTO echoes back the code we sent (pickupLocationCode); fall back to ours.
        $code = $this->str($response['pickupLocationCode'] ?? $response['code'] ?? null) ?? $payload['code'];

        return [
            'code' => $code,
            'name' => $payload['name'],
            'city' => $city !== '' ? $city : null,
        ];
    }

    /**
     * OTO's fee-check response shape varies by account/version, so parse
     * defensively over the common key names.
     *
     * @param array<string, mixed> $response
     * @return list<array{id: string, name: string, price: float, currency: string}>
     */
    private function parseDeliveryOptions(array $response, string $currency): array
    {
        $rows = $response['deliveryCompanies'] ?? $response['data'] ?? $response['options'] ?? [];
        if (!is_array($rows)) {
            return [];
        }

        $out = [];
        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }
            $id = $row['deliveryOptionId'] ?? $row['id'] ?? null;
            $name = $row['deliveryCompanyName'] ?? $row['name'] ?? $row['deliveryCompany'] ?? null;
            if ($id === null || $name === null) {
                continue;
            }
            $out[] = [
                'id' => (string) $id,
                'name' => (string) $name,
                'price' => (float) ($row['price'] ?? $row['deliveryFee'] ?? $row['shippingAmount'] ?? 0),
                'currency' => (string) ($row['currency'] ?? $currency),
            ];
        }
        return $out;
    }

    private function str(mixed $v): ?string
    {
        if ($v === null || $v === '') {
            return null;
        }
        return (string) $v;
    }

    /** Numeric coercion for geo coordinates; null for empty/non-numeric input. */
    private function num(mixed $v): ?float
    {
        if (is_int($v) || is_float($v)) {
            return (float) $v;
        }
        if (is_string($v) && is_numeric(trim($v))) {
            return (float) trim($v);
        }
        return null;
    }
}
