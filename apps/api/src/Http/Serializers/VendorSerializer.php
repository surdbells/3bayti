<?php

declare(strict_types=1);

namespace Bayti\Api\Http\Serializers;

use Bayti\Api\Domain\Catalog\Product;
use Bayti\Api\Domain\Catalog\Vendor;
use DateTimeInterface;

/**
 * Serialize Vendor entities for API responses.
 *
 * publicShape:    storefront-facing view (no commission, no contact phone)
 * featuredShape:  Designer Spotlight view (publicShape + rating
 *                 aggregate + embedded products) — apps/web home page
 * adminShape:     admin view (includes commission, all fields, is_featured)
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
            'is_featured' => $v->isFeatured(),
            // M3.2.X.6-C: Vendor lifecycle status fields. Admin tool
            // displays the current status, when the most-recent
            // transition happened, and the operator-supplied reason.
            // status_changed_at is null for never-transitioned (i.e.
            // still in initial pending state) vendors.
            'status' => $v->getStatus(),
            'status_changed_at' => $v->getStatusChangedAt()?->format(DateTimeInterface::ATOM),
            'status_reason' => $v->getStatusReason(),
            // M3.2.X.7-D: Vendor email locale preference. null means
            // no preference (falls back to English at send time).
            'preferred_locale' => $v->getPreferredLocale(),
            // Vendor location (UAE-only platform). emirate is one of the
            // seven emirates (or null); country is conceptually always
            // "United Arab Emirates" but emitted explicitly so the admin
            // Stores table + store editor can surface/correct it.
            'emirate' => $v->getEmirate(),
            'country' => $v->getCountry(),
            'legacy_vendor_id' => $v->getLegacyVendorId(),
            'created_at' => $v->getCreatedAt()->format(DateTimeInterface::ATOM),
            'updated_at' => $v->getUpdatedAt()->format(DateTimeInterface::ATOM),
        ]);
    }

    /**
     * Admin "Manage store" detail shape.
     *
     * GET /v3/admin/vendors/{id} — the single-vendor screen
     * (apps/portal manage-store) binds a flat, legacy-named `store`
     * object: the owner user's identity, the store profile, the
     * banking / tax / trade-license fields captured at onboarding, and
     * the KYC document URLs. Field names here MUST match the template
     * bindings (store.first_name, store.store_legal_name, …) — this is
     * the contract the portal reads, distinct from {@see adminShape}
     * (the Stores *table* row) which uses v3-native names.
     *
     * All fields are nullable at source (legacy rows, or v3 vendors
     * that never completed onboarding) and pass through as null → the
     * portal renders "— not provided —" and the setup-progress meter
     * counts them as unfilled.
     *
     * @return array<string, mixed>
     */
    public function manageShape(Vendor $v): array
    {
        $owner = $v->getOwnerUser();

        return [
            'id' => $v->getId(),
            // Owner user identity (the person who runs the store).
            'first_name' => $owner?->getFirstName(),
            'last_name' => $owner?->getLastName(),
            'email' => $owner?->getEmail(),
            'phone' => $owner !== null
                ? $this->e164($owner->getPhone(), $owner->getCountryCode())
                : null,
            'avatar' => $owner?->getAvatarUrl(),
            'last_login' => $owner?->getLastLoginAt()?->format(DateTimeInterface::ATOM),
            // KYC / compliance documents.
            'id_front' => $v->getIdFront(),
            'id_back' => $v->getIdBack(),
            'license_doc' => $v->getLicenseDoc(),
            // Lifecycle flags. `approved` gates the Approved/Pending badge
            // and MUST track the lifecycle `status` (what the stores list,
            // the status filter, and approve/suspend all use) — not the
            // legacy is_store_approved boolean, which migrated vendors carry
            // as true even while status is still 'pending', so the two
            // surfaces disagreed. `store_status`/`is_active` both feed the
            // Active badge (the portal ORs them).
            'approved' => $v->isApproved(),
            'status' => $v->getStatus(),
            'store_status' => $v->isActive(),
            'is_active' => $v->isActive(),
            // Store profile.
            'store_name' => $v->getName(),
            'store_email' => $v->getStoreEmail() ?? $v->getContactEmail(),
            'store_phone' => $v->getStorePhoneRaw() ?? $v->getContactPhone(),
            'store_address' => $v->getStoreAddress(),
            'store_description' => $v->getDescription(),
            'emirate' => $v->getEmirate(),
            'country' => $v->getCountry(),
            // Legal / tax registration.
            'store_legal_name' => $v->getLegalName(),
            'vat_status' => $v->getVatStatus(),
            'trade_license_number' => $v->getTradeLicenseNumber(),
            'licensing_authority' => $v->getLicensingAuthority(),
            'tax_registration_number' => $v->getTaxRegistrationNumber(),
            'vat_registration_effective_date' => $v->getVatRegistrationEffectiveDate()?->format('Y-m-d'),
            'registered_tax_address' => $v->getRegisteredTaxAddress(),
            'tax_contact_email' => $v->getTaxContactEmail(),
            // Banking / settlement.
            'store_bank_name' => $v->getStoreBankName(),
            'store_account_name' => $v->getStoreBankAccountName(),
            'store_account_number' => $v->getStoreBankAccountNumber(),
        ];
    }

    /**
     * Best-effort E.164 formatting for display. New signups already
     * store '+971…'; legacy-migrated owners store a local number
     * (optional leading 0) with an ISO country_code, which naive
     * concatenation renders as "AE0506995999". Mirrors the portal's
     * customer-list DIAL_CODES fallback (GCC set, default +971).
     */
    private function e164(?string $phone, ?string $countryCode): ?string
    {
        $raw = trim((string) ($phone ?? ''));
        if ($raw === '') {
            return null;
        }
        if (str_starts_with($raw, '+')) {
            return $raw;
        }
        $dialCodes = [
            'AE' => '+971', 'SA' => '+966', 'KW' => '+965',
            'QA' => '+974', 'BH' => '+973', 'OM' => '+968',
        ];
        $dial = $dialCodes[strtoupper((string) ($countryCode ?? ''))] ?? '+971';
        $local = ltrim(preg_replace('/\D/', '', $raw) ?? '', '0');
        return $local !== '' ? $dial . $local : $raw;
    }

    /**
     * Vendor self-serve onboarding shape (M3.2.X.6-D).
     *
     * Used by:
     *   - POST /v3/vendor/onboarding/submit (creation response)
     *   - GET /v3/vendor/onboarding/status  (read response)
     *
     * Returns vendor info as the vendor user sees it — their own
     * stores, with status visibility so they understand whether
     * they're still pending or have been approved/suspended. Omits
     * admin-only fields (commission_rate, legacy_vendor_id) that
     * the vendor user has no business reason to see.
     *
     * Does NOT include contact_email/contact_phone of the store
     * because the vendor user already knows those — they submitted
     * them. The shape is informational, not a re-render of submitted
     * data.
     *
     * @return array<string, mixed>
     */
    public function onboardingShape(Vendor $v): array
    {
        return [
            'id' => $v->getId(),
            'slug' => $v->getSlug(),
            'name' => $v->getName(),
            'description' => $v->getDescription(),
            'logo_url' => $v->getLogoUrl(),
            'cover_image_url' => $v->getCoverImageUrl(),
            'legal_name' => $v->getLegalName(),
            // Lifecycle status — the key visibility the vendor user
            // needs from this shape. status_reason surfaces admin
            // notes (e.g. "Need to clarify product authenticity"
            // during pending review, or "Quality complaints from
            // 5 customers" if suspended) so vendors understand the
            // state of their store.
            'status' => $v->getStatus(),
            'status_changed_at' => $v->getStatusChangedAt()?->format(DateTimeInterface::ATOM),
            'status_reason' => $v->getStatusReason(),
            'created_at' => $v->getCreatedAt()->format(DateTimeInterface::ATOM),
            'updated_at' => $v->getUpdatedAt()->format(DateTimeInterface::ATOM),
        ];
    }

    /**
     * Many-onboarding-shape helper for the status endpoint where the
     * user may own multiple stores at varying lifecycle states.
     *
     * @param iterable<Vendor> $vendors
     * @return list<array<string, mixed>>
     */
    public function onboardingShapeMany(iterable $vendors): array
    {
        $out = [];
        foreach ($vendors as $v) {
            $out[] = $this->onboardingShape($v);
        }
        return $out;
    }

    /**
     * Featured-vendor card shape for the apps/web Designer Spotlight.
     *
     * Matches the FeaturedVendor + FeaturedVendorProduct interfaces
     * defined in apps/web/src/app/features/catalog/designer-card.ts.
     * Any field-name change here is a breaking change for that
     * contract — keep them in sync.
     *
     * Why this method takes pre-computed data
     * ========================================
     * Rating + product list need DB queries. If this serializer
     * fetched them itself, it would couple the serializer to the
     * EntityManager and make it untestable. Per separation of
     * concerns, the controller computes the aggregates + product
     * list, the serializer shapes them. Same pattern as PriceSerializer
     * etc. elsewhere in the codebase.
     *
     * @param list<Product> $embeddedProducts up to 4 (controller-clamped)
     * @return array<string, mixed>
     */
    public function featuredShape(
        Vendor $vendor,
        array $embeddedProducts,
        ?float $rating,
        int $ratingCount,
    ): array {
        return [
            'slug' => $vendor->getSlug(),
            // Legacy store id — apps/web navigates featured vendors by slug,
            // but apps/mobile's trending-stores cards open the store + its
            // reviews via the by-legacy-id vendor endpoints. Additive: web
            // ignores it. Null for vendors with no legacy row.
            'store_id' => $vendor->getLegacyVendorId(),
            'name' => $vendor->getName(),
            // Logo + cover for the rich hero-image store card (matches the
            // mobile vendor card). Getters already used by publicShape /
            // directoryShape; additive here — apps/web falls back to a product
            // image when cover is null, so this degrades gracefully.
            'logo_url' => $vendor->getLogoUrl(),
            'cover_image_url' => $vendor->getCoverImageUrl(),
            'description' => $vendor->getDescription(),
            // Rating: round to 1dp for display; null preserved as null
            // (apps/web hides the rating chip when rating_count === 0,
            // so the null vs 0.0 distinction matters for accuracy.)
            'rating' => $rating !== null ? round($rating, 1) : null,
            'rating_count' => $ratingCount,
            'products' => array_map(
                static fn (Product $p): array => [
                    'id' => $p->getId(),
                    // Legacy product id + price for apps/mobile's thumbnail
                    // cards (tap -> single product via by-legacy-id; price
                    // shown under the thumbnail). Additive for apps/web.
                    'legacy_product_id' => $p->getLegacyProductId(),
                    'slug' => $p->getSlug(),
                    // null primary_image_url falls back to empty string;
                    // apps/web's <img> would render a broken icon either way
                    // but empty string is the more honest representation.
                    'image_url' => $p->getPrimaryImageUrl() ?? '',
                    'name' => $p->getName(),
                    'price' => (float) $p->getPrice(),
                ],
                $embeddedProducts,
            ),
        ];
    }

    /**
     * Store DIRECTORY card shape (Stores H2.B) — a superset of
     * {@see publicShape} (so existing /v3/vendors consumers keep every
     * field they read) PLUS the rating aggregate + embedded product
     * thumbnails the rich store card renders. Product mapping matches
     * {@see featuredShape} so the same apps/web StoreCard works for both
     * the Spotlight and the directory grid.
     *
     * Mobile parity (paginated store directory)
     * =========================================
     * apps/mobile's "Popular stores" card binds the SAME legacy-shaped
     * fields it consumes from featuredShape: a legacy `store_id` (used to
     * open the store + its reviews via the by-legacy-id vendor endpoints)
     * and per-product `legacy_product_id` + `price` (tap → single product
     * via by-legacy-id; price under the thumbnail). These are emitted
     * additively here so transformFeaturedVendorsResponse can reshape a
     * /v3/vendors page exactly as it reshapes a Spotlight page. apps/web
     * ignores the extra fields. Null store_id/legacy_product_id for
     * vendors/products with no legacy row.
     *
     * @param list<Product> $embeddedProducts up to 5 (controller-clamped)
     * @return array<string, mixed>
     */
    public function directoryShape(
        Vendor $v,
        array $embeddedProducts,
        ?float $rating,
        int $ratingCount,
    ): array {
        return [
            'id' => $v->getId(),
            // Legacy store id for apps/mobile's by-legacy-id navigation
            // (open store + reviews). Additive: apps/web ignores it.
            'store_id' => $v->getLegacyVendorId(),
            'slug' => $v->getSlug(),
            'name' => $v->getName(),
            'description' => $v->getDescription(),
            'logo_url' => $v->getLogoUrl(),
            'cover_image_url' => $v->getCoverImageUrl(),
            'is_verified' => $v->isVerified(),
            'rating' => $rating !== null ? round($rating, 1) : null,
            'rating_count' => $ratingCount,
            'products' => array_map(
                static fn (Product $p): array => [
                    'id' => $p->getId(),
                    // Legacy product id + price for apps/mobile's thumbnail
                    // cards (tap -> single product via by-legacy-id; price
                    // shown under the thumbnail). Additive for apps/web.
                    'legacy_product_id' => $p->getLegacyProductId(),
                    'slug' => $p->getSlug(),
                    'image_url' => $p->getPrimaryImageUrl() ?? '',
                    'name' => $p->getName(),
                    'price' => (float) $p->getPrice(),
                ],
                $embeddedProducts,
            ),
        ];
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
