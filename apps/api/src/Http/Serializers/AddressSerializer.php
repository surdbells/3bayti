<?php

declare(strict_types=1);

namespace Bayti\Api\Http\Serializers;

use Bayti\Api\Domain\User\Address;

/**
 * Convert Address entities into public response shapes.
 *
 * The serializer-per-resource pattern mirrors UserSerializer. Keeps
 * the entity decoupled from HTTP concerns and lets us evolve the
 * response shape without changing entity internals.
 *
 * Default address concept
 * -----------------------
 * The entity has separate isDefaultShipping and isDefaultBilling
 * flags, but the M1.7.2 API exposes them combined:
 *
 *   - is_default = true  → both shipping AND billing default
 *   - is_default = false → either or both flags are false
 *
 * This lets us keep the simpler API surface for now while preserving
 * the underlying flexibility (vendor flows in M4 may need to split
 * shipping/billing defaults explicitly).
 *
 * The PATCH /default endpoint accepts a body with separate
 * "shipping" and "billing" fields, so callers wanting the split can
 * use that. The list/get response keeps it simple.
 */
final class AddressSerializer
{
    /**
     * @return array<string, mixed>
     */
    public function publicShape(Address $address): array
    {
        return [
            'id' => $address->getId(),
            'label' => $address->getLabel(),

            'recipient_name' => $address->getRecipientName(),
            'recipient_phone' => $address->getRecipientPhone(),

            'emirate' => $address->getEmirate(),
            'area' => $address->getArea(),
            'street_address' => $address->getStreetAddress(),
            'building_details' => $address->getBuildingDetails(),
            'postal_code' => $address->getPostalCode(),
            'country' => $address->getCountry(),

            // Combined default flag (true iff BOTH role-specific flags
            // are true). Plus the role-specific flags for clients that
            // need to distinguish.
            'is_default' => $address->isDefaultShipping() && $address->isDefaultBilling(),
            'is_default_shipping' => $address->isDefaultShipping(),
            'is_default_billing' => $address->isDefaultBilling(),
        ];
    }

    /**
     * Serialize a list of addresses.
     *
     * @param iterable<Address> $addresses
     * @return list<array<string, mixed>>
     */
    public function publicShapeMany(iterable $addresses): array
    {
        $result = [];
        foreach ($addresses as $address) {
            $result[] = $this->publicShape($address);
        }
        return $result;
    }
}
