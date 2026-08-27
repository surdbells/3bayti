<?php

declare(strict_types=1);

namespace Bayti\Api\Domain\Promo;

use Bayti\Api\Domain\Order\Order;
use Doctrine\ORM\EntityRepository;

/**
 * Repository for PromoRedemption entities.
 *
 * Hot paths:
 *   - countByPromoCodeIdEffective: usage_limit_global enforcement
 *   - countByUserAndPromoCodeEffective: usage_limit_per_user enforcement
 *   - findByOrderId: serializer reverse-lookup for applied_promo block
 *
 * The "effective" suffix on the count methods denotes that orders in
 * terminal-failed states (cancelled / failed) are excluded from
 * the count, so a customer whose checkout failed at the gateway
 * doesn't burn their one allowed redemption.
 *
 * Status taxonomy reference: Order::STATUS_* in
 * apps/api/src/Domain/Order/Order.php. The terminal-failed states are
 * intentionally enumerated inline below rather than added as a new
 * constant on Order; the set of "consumed a redemption" statuses is a
 * promo-engine concern, not an order-domain concern, and centralizing
 * it here keeps the coupling one-way.
 *
 * @extends EntityRepository<PromoRedemption>
 */
class PromoRedemptionRepository extends EntityRepository
{
    /**
     * Order statuses that should NOT count toward usage limits. A
     * canceled or payment-failed order's redemption is historical
     * only, the customer never actually got the discount.
     *
     * Note: we deliberately do NOT exclude pending_payment here.
     * A pending-payment order has reserved the redemption slot;
     * counting it prevents a customer from "trying the code" in
     * one checkout attempt while another is mid-Noon-roundtrip
     * and effectively double-redeeming.
     */
    private const STATUSES_EXCLUDED_FROM_COUNT = [
        Order::STATUS_CANCELLED,
        Order::STATUS_FAILED,
    ];

    /**
     * Persist a redemption row. Called from inside the checkout EM
     * transaction in M3.2.X.8-D; the caller flushes once at the end
     * of the transaction along with the Order and PaymentTransaction,
     * so this method does NOT flush.
     */
    public function persist(PromoRedemption $redemption): void
    {
        $this->getEntityManager()->persist($redemption);
    }

    /**
     * Remove a redemption row (admin affordance + future cancel-flow
     * hook). Flushes immediately.
     */
    public function remove(PromoRedemption $redemption): void
    {
        $em = $this->getEntityManager();
        $em->remove($redemption);
        $em->flush();
    }

    /**
     * Count effective redemptions for a promo code across all users.
     * Used by the resolver for usage_limit_global enforcement.
     *
     * Excludes redemptions attached to orders in terminal-failed states
     * (see STATUSES_EXCLUDED_FROM_COUNT).
     */
    public function countByPromoCodeIdEffective(int $promoCodeId): int
    {
        $qb = $this->createQueryBuilder('r')
            ->select('COUNT(r.id)')
            ->innerJoin('r.order', 'o')
            ->where('r.promoCode = :promoCodeId')
            ->andWhere('o.status NOT IN (:excludedStatuses)')
            ->setParameter('promoCodeId', $promoCodeId)
            ->setParameter('excludedStatuses', self::STATUSES_EXCLUDED_FROM_COUNT);

        return (int) $qb->getQuery()->getSingleScalarResult();
    }

    /**
     * Count effective redemptions for a (user, promo code) pair. Used
     * by the resolver for usage_limit_per_user enforcement.
     *
     * Excludes redemptions attached to orders in terminal-failed states.
     */
    public function countByUserAndPromoCodeEffective(int $userId, int $promoCodeId): int
    {
        $qb = $this->createQueryBuilder('r')
            ->select('COUNT(r.id)')
            ->innerJoin('r.order', 'o')
            ->where('r.user = :userId')
            ->andWhere('r.promoCode = :promoCodeId')
            ->andWhere('o.status NOT IN (:excludedStatuses)')
            ->setParameter('userId', $userId)
            ->setParameter('promoCodeId', $promoCodeId)
            ->setParameter('excludedStatuses', self::STATUSES_EXCLUDED_FROM_COUNT);

        return (int) $qb->getQuery()->getSingleScalarResult();
    }

    /**
     * Count ALL redemptions for a promo code regardless of order state.
     * Used by the admin endpoints to surface "this code has been
     * redeemed N times total" (the gross figure, including canceled
     * orders), distinct from the effective count used for limit
     * enforcement.
     */
    public function countByPromoCodeIdGross(int $promoCodeId): int
    {
        $qb = $this->createQueryBuilder('r')
            ->select('COUNT(r.id)')
            ->where('r.promoCode = :promoCodeId')
            ->setParameter('promoCodeId', $promoCodeId);

        return (int) $qb->getQuery()->getSingleScalarResult();
    }

    /**
     * Reverse lookup from an order to its redemption. Used by
     * OrderSerializer to surface the applied_promo block on
     * GET /v3/orders/{id} when a redemption exists. Returns null
     * for orders without a promo applied.
     */
    public function findByOrderId(int $orderId): ?PromoRedemption
    {
        return $this->findOneBy(['order' => $orderId]);
    }
}
