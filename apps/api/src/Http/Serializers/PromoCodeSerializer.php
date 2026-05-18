<?php

declare(strict_types=1);

namespace Bayti\Api\Http\Serializers;

use Bayti\Api\Domain\Promo\PromoCode;
use DateTimeInterface;

/**
 * Convert PromoCode entities into admin response shapes.
 *
 * Only admin shapes exist — promo codes are catalog-management
 * primitives, never exposed publicly. The customer-facing surface
 * for promos is `applied_promo` on the cart quote / order serializer
 * (M3.2.X.8-C / -F), built from a PromoResolution, not from the
 * PromoCode entity directly.
 *
 * adminShape exposes every mutable column plus computed metadata
 * (currently_time_valid) so admin UIs can render "active right now"
 * indicators without re-implementing the time-window check.
 *
 * The optional `redemption_count` field is populated by the
 * controllers that want it (Get + List for at-a-glance dashboards);
 * not all callers pay the SQL cost for it — see adminShapeWithCount.
 */
final class PromoCodeSerializer
{
    /**
     * Admin shape WITHOUT redemption counts. Used by Create/Update
     * controllers and by List when the count would be too expensive
     * to compute per-row (one query per row → N+1).
     *
     * @return array<string, mixed>
     */
    public function adminShape(PromoCode $code): array
    {
        return [
            'id' => $code->getId() ?? 0,
            'code' => $code->getCode(),
            'description' => $code->getDescription(),
            'discount_type' => $code->getDiscountType(),
            'discount_value' => $code->getDiscountValue(),
            'currency' => $code->getCurrency(),
            'min_subtotal' => $code->getMinSubtotal(),
            'max_discount_amount' => $code->getMaxDiscountAmount(),
            'usage_limit_global' => $code->getUsageLimitGlobal(),
            'usage_limit_per_user' => $code->getUsageLimitPerUser(),
            'valid_from' => $code->getValidFrom()?->format(DateTimeInterface::ATOM),
            'valid_until' => $code->getValidUntil()?->format(DateTimeInterface::ATOM),
            'is_active' => $code->isActive(),
            'currently_time_valid' => $code->isCurrentlyTimeValid(),
            'created_at' => $code->getCreatedAt()->format(DateTimeInterface::ATOM),
            'updated_at' => $code->getUpdatedAt()->format(DateTimeInterface::ATOM),
        ];
    }

    /**
     * Admin shape WITH a redemption count. The count is passed in
     * rather than queried here — keeps the serializer dependency-free
     * (no repository injection) and lets the caller decide between
     * effective vs gross counting via
     * PromoRedemptionRepository::countByPromoCodeIdEffective /
     * countByPromoCodeIdGross.
     *
     * Used by GetPromoCodeController where the single-row lookup
     * justifies the extra SQL.
     *
     * @return array<string, mixed>
     */
    public function adminShapeWithCount(PromoCode $code, int $redemptionCount): array
    {
        return array_merge($this->adminShape($code), [
            'redemption_count' => $redemptionCount,
        ]);
    }

    /**
     * Map a list of PromoCodes through adminShape.
     *
     * @param iterable<PromoCode> $codes
     * @return list<array<string, mixed>>
     */
    public function adminShapeMany(iterable $codes): array
    {
        $result = [];
        foreach ($codes as $code) {
            $result[] = $this->adminShape($code);
        }
        return $result;
    }
}
