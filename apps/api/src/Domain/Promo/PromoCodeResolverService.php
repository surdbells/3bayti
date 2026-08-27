<?php

declare(strict_types=1);

namespace Bayti\Api\Domain\Promo;

use Bayti\Api\Domain\Cart\Cart;
use Bayti\Api\Domain\Order\Order;
use Bayti\Api\Domain\Promo\Exception\PromoNotApplicableException;
use Bayti\Api\Domain\User\User;
use DateTimeImmutable;
use DateTimeZone;
use Doctrine\ORM\EntityManagerInterface;

/**
 * The promo code resolver — the heart of the M3.2.X.8 engine.
 *
 * Two surfaces:
 *
 *   resolveForCart(Cart, User, string $rawCode): PromoResolution
 *     The validation chain. Looks up the code by normalized text,
 *     walks 10 ordered rules, computes the discount amount on success.
 *     Throws PromoNotApplicableException with a structured error code
 *     on any rule failure — caller maps to HTTP 422 via the
 *     exception's toHttpException().
 *
 *   recordRedemption(PromoCode, User, Order, string): PromoRedemption
 *     Persists the attribution row. Called by InitiateCheckoutController
 *     inside the checkout EM transaction AFTER resolveForCart succeeded
 *     AND the order has been flushed (so the redemption's FK to
 *     orders.id is real). Does NOT flush — the controller's outer
 *     transaction owns commit semantics.
 *
 * The 10-rule chain (in order; first failure stops)
 * ==================================================
 *
 *   1. Code text is not empty after normalization
 *   2. Catalog row exists for the normalized code
 *   3. Catalog row's is_active = true
 *   4. now() >= valid_from (or valid_from IS NULL)
 *   5. now() <= valid_until (or valid_until IS NULL)
 *   6. promo.currency = cart.currency
 *   7. applicable_subtotal >= min_subtotal (or min_subtotal IS NULL)
 *   8. effective global redemption count < usage_limit_global
 *      (or usage_limit_global IS NULL)
 *   9. effective per-user redemption count < usage_limit_per_user
 *      (or usage_limit_per_user IS NULL)
 *   10. Compute discount_amount with type-aware clamping
 *
 * Vendor scope (between rules 6 and 7)
 * ------------------------------------
 * The "applicable subtotal" the min-subtotal gate (7) and discount (10) work
 * off depends on the code's owner: a platform-wide code (promo_codes.vendor_id
 * IS NULL) discounts the WHOLE cart; a vendor-owned coupon (vendor_id set)
 * discounts ONLY that vendor's items. A vendor coupon applied to a cart with no
 * items from that vendor is rejected with PROMO_NOT_APPLICABLE_TO_CART rather
 * than silently discounting nothing.
 *
 * The "effective" counts on rules 8 and 9 exclude redemptions on
 * orders in cancelled/failed states — so a customer whose first
 * attempt failed at the gateway doesn't burn their one allowed
 * redemption. See PromoRedemptionRepository::STATUSES_EXCLUDED_FROM_COUNT.
 *
 * Lazy EM resolution (locked pattern #1)
 * ---------------------------------------
 * The service holds an optional EntityManagerInterface; repositories
 * are resolved on each call via getRepository() with an instanceof
 * check. This mirrors OrderNotificationService::safePersist and lets
 * the service be constructed in tests with no EM (callers that pass
 * null get a service that operates against a manually-injected
 * PromoCodeRepository + PromoRedemptionRepository).
 *
 * Race-condition stance (documented v1 limitation)
 * -------------------------------------------------
 * Rules 8 and 9 are best-effort point-in-time counts. Two concurrent
 * checkouts could both read count = N-1, both write redemptions, and
 * end up with N+1 redemptions against a usage_limit_global = N. The
 * UNIQUE constraint on promo_redemptions.order_id only protects
 * against the same-order double-write; cross-order races are not
 * locked out. Per plan §5 risk register, this is acceptable for
 * marketplace volume; a SELECT ... FOR UPDATE upgrade is future
 * hardening (would need to lock the promo_codes row + serialize
 * redemption inserts).
 *
 * Currency comparison is case-insensitive
 * ----------------------------------------
 * PromoCode::setCurrency upper-cases. Cart::$currency defaults to 'AED'.
 * The compare is via strtolower-equivalent; this is defense in depth
 * against any legacy carts that might carry mixed-case currency.
 */
final class PromoCodeResolverService
{
    public function __construct(
        private readonly ?EntityManagerInterface $em = null,
        // Optional direct-injection paths for tests. When null, the
        // service resolves repositories lazily from $em. When set,
        // these win — convenient for unit tests that supply
        // hand-rolled fakes.
        private readonly ?PromoCodeRepository $promoCodeRepository = null,
        private readonly ?PromoRedemptionRepository $promoRedemptionRepository = null,
    ) {
    }

    /**
     * Walk the 10-rule validation chain. Returns a PromoResolution on
     * success; throws PromoNotApplicableException with a structured
     * error code on the first failure.
     *
     * @throws PromoNotApplicableException
     */
    public function resolveForCart(Cart $cart, User $user, string $rawCode): PromoResolution
    {
        // Rule 1 — Normalize + non-empty check
        $normalized = PromoCode::normalizeCode($rawCode);
        if ($normalized === '') {
            throw PromoNotApplicableException::notFound($rawCode);
        }

        // Rule 2 — Catalog lookup
        $promo = $this->resolvePromoCodeRepository()?->findByNormalizedCode($normalized);
        if ($promo === null) {
            throw PromoNotApplicableException::notFound($rawCode);
        }

        // Rule 3 — Active flag
        if (!$promo->isActive()) {
            throw PromoNotApplicableException::inactive($promo->getCode());
        }

        // Rules 4 + 5 — Time-window bracket
        $now = new DateTimeImmutable('now', new DateTimeZone('UTC'));
        $validFrom = $promo->getValidFrom();
        if ($validFrom !== null && $now < $validFrom) {
            throw PromoNotApplicableException::notYetValid($promo->getCode(), $validFrom);
        }
        $validUntil = $promo->getValidUntil();
        if ($validUntil !== null && $now > $validUntil) {
            throw PromoNotApplicableException::expired($promo->getCode(), $validUntil);
        }

        // Rule 6 — Currency match (case-insensitive defense in depth)
        if (strtoupper($promo->getCurrency()) !== strtoupper($cart->getCurrency())) {
            throw PromoNotApplicableException::currencyMismatch(
                $promo->getCode(),
                $promo->getCurrency(),
                $cart->getCurrency(),
            );
        }

        // Vendor scope — a coupon owned by a vendor (vendor_id set) discounts
        // ONLY that vendor's items; a platform-wide code (vendor_id null)
        // discounts the whole cart. Everything downstream (the min-subtotal
        // gate below and the discount amount in rule 10) works off this
        // applicable subtotal.
        $vendorId = $promo->getVendorId();
        if ($vendorId === null) {
            $applicableSubtotal = $cart->computeSubtotal();
        } else {
            $applicableSubtotal = $cart->computeSubtotalForVendor($vendorId);
            // No items from that vendor → the code would discount nothing;
            // reject rather than silently applying a 0.00 discount.
            if (bccomp($applicableSubtotal, '0.00', 2) <= 0) {
                throw PromoNotApplicableException::notApplicableToCart($promo->getCode());
            }
        }

        // Rule 7 — Min subtotal check (against the applicable subtotal)
        $minSubtotal = $promo->getMinSubtotal();
        if ($minSubtotal !== null && bccomp($applicableSubtotal, $minSubtotal, 2) < 0) {
            throw PromoNotApplicableException::minSubtotalNotMet(
                $promo->getCode(),
                $minSubtotal,
                $cart->getCurrency(),
            );
        }

        // Rules 8 + 9 — Usage limit enforcement
        $redemptions = $this->resolvePromoRedemptionRepository();
        $globalLimit = $promo->getUsageLimitGlobal();
        if ($globalLimit !== null && $redemptions !== null) {
            $promoId = $promo->getId();
            if ($promoId !== null) {
                $globalCount = $redemptions->countByPromoCodeIdEffective($promoId);
                if ($globalCount >= $globalLimit) {
                    throw PromoNotApplicableException::globalLimitReached($promo->getCode());
                }
            }
        }
        $perUserLimit = $promo->getUsageLimitPerUser();
        if ($perUserLimit !== null && $redemptions !== null) {
            $promoId = $promo->getId();
            $userId = $user->getId();
            if ($promoId !== null && $userId !== null) {
                $userCount = $redemptions->countByUserAndPromoCodeEffective($userId, $promoId);
                if ($userCount >= $perUserLimit) {
                    throw PromoNotApplicableException::userLimitReached($promo->getCode());
                }
            }
        }

        // Rule 10 — Compute discount amount with type-aware clamping
        $discountAmount = $this->computeDiscountAmount($promo, $applicableSubtotal);

        return new PromoResolution(
            promoCode: $promo,
            discountAmount: $discountAmount,
            currency: $promo->getCurrency(),
        );
    }

    /**
     * Persist a redemption row attaching the resolved promo to a
     * just-committed order. Called by InitiateCheckoutController
     * inside the checkout EM transaction AFTER the order has been
     * flushed (so order.id is real for the FK).
     *
     * Does NOT flush. The caller's outer transaction owns commit.
     *
     * Returns the constructed redemption so the caller can call
     * Order::setPromoRedemption with it.
     */
    public function recordRedemption(
        PromoCode $promoCode,
        User $user,
        Order $order,
        string $discountAmount,
    ): PromoRedemption {
        $redemption = new PromoRedemption($promoCode, $user, $order, $discountAmount);
        $repo = $this->resolvePromoRedemptionRepository();
        if ($repo !== null) {
            $repo->persist($redemption);
        }
        return $redemption;
    }

    /**
     * Compute the discount amount for a given promo + cart subtotal.
     *
     * For DISCOUNT_TYPE_PERCENTAGE:
     *   raw = subtotal * discount_value / 100
     *   if max_discount_amount is set, clamp at min(raw, max_discount_amount)
     *
     * For DISCOUNT_TYPE_FIXED_AMOUNT:
     *   raw = discount_value
     *   clamp at min(raw, subtotal) — can't discount more than the cart
     *
     * Both branches return a DECIMAL(10,2) string. Order's
     * computeTotal already floors the total at zero, so a discount
     * larger than (subtotal + delivery_fee) is mathematically safe;
     * we clamp anyway to keep the recorded discount_amount meaningful
     * (recording a 1000 AED discount on a 50 AED cart is misleading).
     */
    private function computeDiscountAmount(PromoCode $promo, string $cartSubtotal): string
    {
        if ($promo->getDiscountType() === PromoCode::DISCOUNT_TYPE_PERCENTAGE) {
            // bcmul with scale=4 then bcdiv with scale=2 to avoid
            // losing precision mid-calculation on edge percentages
            // like 33.33% × 99.99.
            $raw = bcdiv(
                bcmul($cartSubtotal, $promo->getDiscountValue(), 4),
                '100',
                2,
            );
            $maxCap = $promo->getMaxDiscountAmount();
            if ($maxCap !== null && bccomp($raw, $maxCap, 2) > 0) {
                return $maxCap;
            }
            return $raw;
        }

        // DISCOUNT_TYPE_FIXED_AMOUNT — clamp at cart subtotal so the
        // recorded discount_amount never exceeds the gross.
        $raw = $promo->getDiscountValue();
        if (bccomp($raw, $cartSubtotal, 2) > 0) {
            return $cartSubtotal;
        }
        return $raw;
    }

    /**
     * Lazy resolution per locked pattern #1. Direct injection wins
     * (test path); otherwise EM lookup with the instanceof safety
     * check that mirrors OrderNotificationService::safePersist.
     */
    private function resolvePromoCodeRepository(): ?PromoCodeRepository
    {
        if ($this->promoCodeRepository !== null) {
            return $this->promoCodeRepository;
        }
        if ($this->em === null) {
            return null;
        }
        $repo = $this->em->getRepository(PromoCode::class);
        if (!$repo instanceof PromoCodeRepository) {
            return null;
        }
        return $repo;
    }

    private function resolvePromoRedemptionRepository(): ?PromoRedemptionRepository
    {
        if ($this->promoRedemptionRepository !== null) {
            return $this->promoRedemptionRepository;
        }
        if ($this->em === null) {
            return null;
        }
        $repo = $this->em->getRepository(PromoRedemption::class);
        if (!$repo instanceof PromoRedemptionRepository) {
            return null;
        }
        return $repo;
    }
}
