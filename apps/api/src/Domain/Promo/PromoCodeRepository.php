<?php

declare(strict_types=1);

namespace Bayti\Api\Domain\Promo;

use DateTimeImmutable;
use Doctrine\ORM\EntityRepository;

/**
 * Repository for PromoCode entities.
 *
 * Two surfaces:
 *   - Resolver path: findByNormalizedCode() resolves a user-typed code
 *     (any casing, any whitespace) to a PromoCode entity for the
 *     PromoCodeResolverService validation chain.
 *   - Admin path: findFilteredPaginated() backs the list endpoint with
 *     the standard filter set (is_active, discount_type, code LIKE,
 *     time-window filters, pagination).
 *
 * @extends EntityRepository<PromoCode>
 */
class PromoCodeRepository extends EntityRepository
{
    /**
     * Persist a promo code row + flush. Used by admin create/update
     * controllers. Same fire-and-forget shape as NotificationLogRepository::save
     * and AuditLogRepository::save — the admin endpoint expects the row
     * to land before the response goes out (audit emission needs the id).
     */
    public function save(PromoCode $code): void
    {
        $em = $this->getEntityManager();
        $em->persist($code);
        $em->flush();
    }

    /**
     * Remove a promo code row (hard delete). Used by the admin delete
     * controller ONLY when the row has zero redemptions; otherwise the
     * controller soft-deletes via setActive(false) to preserve FK
     * integrity from historical promo_redemptions rows.
     */
    public function remove(PromoCode $code): void
    {
        $em = $this->getEntityManager();
        $em->remove($code);
        $em->flush();
    }

    /**
     * Resolve a user-typed code to the entity. Normalization mirrors
     * PromoCode::normalizeCode (trim + upper-case) so case-insensitive
     * input always matches the stored UPPER form. Returns null if no
     * row matches (the resolver maps that to PROMO_NOT_FOUND).
     *
     * Does NOT filter on is_active or time bounds — the resolver
     * enforces those after lookup so it can return distinct error
     * codes (PROMO_INACTIVE, PROMO_EXPIRED) rather than a generic
     * "not found".
     */
    public function findByNormalizedCode(string $rawCode): ?PromoCode
    {
        $normalized = PromoCode::normalizeCode($rawCode);
        if ($normalized === '') {
            return null;
        }
        return $this->findOneBy(['code' => $normalized]);
    }

    /**
     * Filter + paginate for the admin list endpoint.
     *
     * @param array{
     *   isActive?: bool|null,
     *   discountType?: string|null,
     *   codeContains?: string|null,
     *   validAt?: DateTimeImmutable|null,
     *   limit?: int,
     *   offset?: int,
     * } $filters
     *
     * @return array{items: list<PromoCode>, total: int}
     */
    public function findFilteredPaginated(array $filters = []): array
    {
        $qb = $this->createQueryBuilder('pc');

        if (isset($filters['isActive'])) {
            $qb->andWhere('pc.isActive = :isActive')
               ->setParameter('isActive', $filters['isActive']);
        }
        if (!empty($filters['discountType'])) {
            $qb->andWhere('pc.discountType = :discountType')
               ->setParameter('discountType', $filters['discountType']);
        }
        if (!empty($filters['codeContains'])) {
            // Normalize the search term the same way stored codes are
            // normalized; LIKE pattern against the UPPER form.
            $needle = PromoCode::normalizeCode($filters['codeContains']);
            $qb->andWhere('pc.code LIKE :needle')
               ->setParameter('needle', '%' . $needle . '%');
        }
        if (!empty($filters['validAt'])) {
            // "Currently valid at this timestamp" — used by the admin
            // UI to surface "active right now" codes. Honors both
            // bounds; rows with null bounds always pass that side.
            $qb->andWhere('(pc.validFrom IS NULL OR pc.validFrom <= :validAt)')
               ->andWhere('(pc.validUntil IS NULL OR pc.validUntil >= :validAt)')
               ->setParameter('validAt', $filters['validAt']);
        }

        // Count BEFORE applying limit/offset.
        $countQb = clone $qb;
        $total = (int) $countQb->select('COUNT(pc.id)')
            ->getQuery()
            ->getSingleScalarResult();

        // Most-recent first matches the admin's typical "what did I
        // just create" expectation.
        $qb->orderBy('pc.createdAt', 'DESC')
           ->addOrderBy('pc.id', 'DESC');

        $qb->setMaxResults($filters['limit'] ?? 20)
           ->setFirstResult($filters['offset'] ?? 0);

        /** @var list<PromoCode> $items */
        $items = $qb->getQuery()->getResult();

        return ['items' => $items, 'total' => $total];
    }
}
