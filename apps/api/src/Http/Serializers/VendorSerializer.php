<?php

declare(strict_types=1);

namespace Bayti\Api\Http\Serializers;

use Bayti\Api\Domain\Catalog\Vendor;
use DateTimeInterface;

/**
 * Serialize Vendor entities for API responses.
 *
 * publicShape:  storefront-facing view (no commission, no contact phone)
 * adminShape:   admin view (includes commission, all fields)
 *
 * Contact email/phone are not in publicShape because exposing them
 * publicly invites scraping. Customers contact vendors through the
 * marketplace, not directly.
 */
final class VendorSerializer
{
    /**
     * @return array<string, mixed>
     */
    public function publicShape(Vendor $v): array
    {
        return [
            'id' => $v->getId(),
            'slug' => $v->getSlug(),
            'name' => $v->getName(),
            'description' => $v->getDescription(),
            'logo_url' => $v->getLogoUrl(),
            'cover_image_url' => $v->getCoverImageUrl(),
            'is_verified' => $v->isVerified(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function adminShape(Vendor $v): array
    {
        return array_merge($this->publicShape($v), [
            'contact_email' => $v->getContactEmail(),
            'contact_phone' => $v->getContactPhone(),
            'commission_rate' => $v->getCommissionRate(),
            'is_active' => $v->isActive(),
            'legacy_vendor_id' => $v->getLegacyVendorId(),
            'created_at' => $v->getCreatedAt()->format(DateTimeInterface::ATOM),
            'updated_at' => $v->getUpdatedAt()->format(DateTimeInterface::ATOM),
        ]);
    }

    /**
     * @param iterable<Vendor> $vendors
     * @return list<array<string, mixed>>
     */
    public function publicShapeMany(iterable $vendors): array
    {
        $out = [];
        foreach ($vendors as $v) { $out[] = $this->publicShape($v); }
        return $out;
    }

    /**
     * @param iterable<Vendor> $vendors
     * @return list<array<string, mixed>>
     */
    public function adminShapeMany(iterable $vendors): array
    {
        $out = [];
        foreach ($vendors as $v) { $out[] = $this->adminShape($v); }
        return $out;
    }
}
