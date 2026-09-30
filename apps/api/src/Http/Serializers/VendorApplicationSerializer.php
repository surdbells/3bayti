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
        // True state of the provisioned store + its login account, so the admin
        // UI reflects reality (a store can be approved-then-suspended, and the
        // owner account can be deactivated or deleted) instead of showing an
        // approved application as simply "active". These also drive whether the
        // "resend credentials" action makes sense — resetting a deleted/inactive
        // account's password just emails a credential login can never accept.
        $vendor = $a->getVendor();
        $owner = $vendor?->getOwnerUser();
        $accountActive = $owner?->isActive();
        $accountDeleted = $owner !== null ? $owner->isDeleted() : null;
        // The API is the authoritative guard (it refuses an unreachable target);
        // this flag lets the portal disable the button + explain why up front.
        $credentialsResendable = $a->isApproved()
            && $owner !== null
            && $accountActive === true
            && $accountDeleted !== true;

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
            'vendor_id' => $vendor?->getId(),
            // Provisioned store + owner-account state (null when not yet approved).
            'store_status' => $vendor?->getStatus(),
            'owner_email' => $owner?->getEmail(),
            'account_active' => $accountActive,
            'account_deleted' => $accountDeleted,
            'credentials_resendable' => $credentialsResendable,
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
