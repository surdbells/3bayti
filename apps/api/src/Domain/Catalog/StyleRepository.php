<?php

declare(strict_types=1);

namespace Bayti\Api\Domain\Catalog;

use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\EntityRepository;

/**
 * Repository for Style.
 *
 * @extends EntityRepository<Style>
 */
class StyleRepository extends EntityRepository
{
    /**
     * Find an active style by slug. Returns null when no match;
     * caller surfaces as 404.
     */
    public function findActiveBySlug(string $slug): ?Style
    {
        return $this->findOneBy(['slug' => $slug, 'isActive' => true]);
    }

    /**
     * Look up a style by its legacy id. Same compat pattern as
     * the other by-legacy-id repository methods (see
     * VendorRepository::findByLegacyId for full rationale).
     */
    public function findActiveByLegacyId(int $legacyId): ?Style
    {
        return $this->findOneBy(['legacyStyleId' => $legacyId, 'isActive' => true]);
    }

    /**
     * Paginated active-styles listing filtered by type.
     *
     * Returns the styles themselves; product embedding is done
     * separately by loadProductsForStyles to keep this query simple
     * and avoid the cartesian explosion of joining products in the
     * main query.
     *
     * @return array{items: list<Style>, total: int}
     */
    public function findActivePaginatedByType(int $styleType, int $limit, int $offset): array
    {
        $qb = $this->createQueryBuilder('s')
            ->where('s.styleType = :type')
            ->andWhere('s.isActive = true')
            ->setParameter('type', $styleType)
            // Same NULLS LAST trick as VendorLabelRepository.
            ->addOrderBy('CASE WHEN s.displayOrder IS NULL THEN 1 ELSE 0 END', 'ASC')
            ->addOrderBy('s.displayOrder', 'ASC')
            ->addOrderBy('s.createdAt', 'DESC')
            ->setFirstResult($offset)
            ->setMaxResults($limit);

        $countQb = $this->createQueryBuilder('s')
            ->select('COUNT(s.id)')
            ->where('s.styleType = :type')
            ->andWhere('s.isActive = true')
            ->setParameter('type', $styleType);

        /** @var list<Style> $items */
        $items = $qb->getQuery()->getResult();
        $total = (int) $countQb->getQuery()->getSingleScalarResult();

        return ['items' => $items, 'total' => $total];
    }

    /**
     * Load the ordered product list for a set of styles.
     *
     * Why a separate method (not a Doctrine association)
     * ==================================================
     * The `style_products` join table has a display_order column we
     * need to respect when surfacing products to mobile (which reads
     * style.products[0], style.products[1], etc. — order matters).
     *
     * Doctrine's many-to-many association can't easily preserve a
     * join-table column-ordered result without introducing an
     * association entity class (StyleProduct). Rather than add that
     * mapping, this method uses raw SQL via the EntityManager's
     * connection and returns a structured map keyed by style_id.
     *
     * Performance: one query for all styles in a page — beats N+1
     * cleanly. The cost is the structured-return shape; the caller
     * (StyleSerializer) walks it.
     *
     * @param list<int> $styleIds
     * @return array<int, list<array{
     *     id: int,
     *     slug: string,
     *     name: string,
     *     primary_image_url: ?string,
     *     price: string,
     *     display_order: int
     * }>>
     */
    public function loadProductsForStyles(array $styleIds): array
    {
        if (count($styleIds) === 0) {
            return [];
        }

        $em = $this->getEntityManager();
        $conn = $em->getConnection();

        // We need: style_id, product_id, display_order, plus the
        // product fields the serializer will emit. JOIN to products
        // gets us those without a separate Doctrine load.
        $sql = <<<'SQL'
            SELECT sp.style_id, sp.display_order,
                   p.id AS product_id, p.slug, p.name,
                   p.primary_image_url, p.price
            FROM style_products sp
            JOIN products p ON p.id = sp.product_id
            WHERE sp.style_id IN (?)
              AND p.is_active = TRUE
            ORDER BY sp.style_id, sp.display_order, p.id
        SQL;

        $rows = $conn->fetchAllAssociative(
            $sql,
            [$styleIds],
            [Connection::PARAM_INT_ARRAY],
        );

        $byStyleId = [];
        foreach ($rows as $row) {
            $styleId = (int) $row['style_id'];
            if (!isset($byStyleId[$styleId])) {
                $byStyleId[$styleId] = [];
            }
            $byStyleId[$styleId][] = [
                'id' => (int) $row['product_id'],
                'slug' => (string) $row['slug'],
                'name' => (string) $row['name'],
                'primary_image_url' => $row['primary_image_url'] !== null
                    ? (string) $row['primary_image_url']
                    : null,
                'price' => (string) $row['price'],
                'display_order' => (int) $row['display_order'],
            ];
        }

        return $byStyleId;
    }
}
