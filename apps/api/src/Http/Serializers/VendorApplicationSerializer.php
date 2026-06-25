<?php

declare(strict_types=1);

namespace Bayti\Api\Http\Serializers;

use Bayti\Api\Domain\Catalog\VendorApplication;
use DateTimeInterface;

/**
 * Serialize VendorApplication entities for API responses.
 *
 * adminShape: full admin review view (all submitted fields + lifecycle
 *             + the provisioned vendor id / reviewing admin once a
 *             decision has been made).
 */
final class VendorApplicationSerializer
{
    /**
     * @return array<string, mixed>
     */
    public function adminShape(VendorApplication $a): array
    {
        return [
            'id' => $a->getId(),
            'first_name' => $a->getFirstName(),
            'last_name' => $a->getLastName(),
            'email' => $a->getEmail(),
            'phone' => $a->getPhone(),
            'country_code' => $a->getCountryCode(),
            'business_name' => $a->getBusinessName(),
            'license_number' => $a->getLicenseNumber(),
            'category' => $a->getCategory(),
            'message' => $a->getMessage(),
            'status' => $a->getStatus(),
            'reject_reason' => $a->getRejectReason(),
            'vendor_id' => $a->getVendor()?->getId(),
            'reviewed_by_user_id' => $a->getReviewedBy()?->getId(),
            'reviewed_at' => $a->getReviewedAt()?->format(DateTimeInterface::ATOM),
            'created_at' => $a->getCreatedAt()->format(DateTimeInterface::ATOM),
            'updated_at' => $a->getUpdatedAt()->format(DateTimeInterface::ATOM),
        ];
    }

    /**
     * @param iterable<VendorApplication> $applications
     * @return list<array<string, mixed>>
     */
    public function adminShapeMany(iterable $applications): array
    {
        $out = [];
        foreach ($applications as $a) {
            $out[] = $this->adminShape($a);
        }
        return $out;
    }
}
