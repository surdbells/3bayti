<?php

declare(strict_types=1);

namespace Bayti\Api\Domain\Catalog;

use Doctrine\ORM\EntityRepository;

/** @extends EntityRepository<ProductCollection> */
class ProductCollectionRepository extends EntityRepository
{
    public function save(ProductCollection $col): void
    {
        $em = $this->getEntityManager();
        $em->persist($col);
        $em->flush();
    }

    public function delete(ProductCollection $col): void
    {
        $em = $this->getEntityManager();
        $em->remove($col);
        $em->flush();
    }

    /**
     * @return array{items: list<ProductCollection>, total: int}
     */
    public function findPaginated(int $limit = 20, int $offset = 0, ?bool $activeOnly = null): array
    {
        $qb = $this->createQueryBuilder('c')->orderBy('c.displayOrder', 'ASC')->addOrderBy('c.id', 'DESC');
        if ($activeOnly !== null) {
            $qb->where('c.isActive = :a')->setParameter('a', $activeOnly);
        }
        $countQb = clone $qb;
        $total   = (int) $countQb->select('COUNT(c.id)')->resetDQLPart('orderBy')->getQuery()->getSingleScalarResult();
        $qb->setMaxResults($limit)->setFirstResult($offset);
        /** @var list<ProductCollection> $items */
        $items = $qb->getQuery()->getResult();
        return ['items' => $items, 'total' => $total];
    }

    /**
     * Active collections in display order, for the storefront "shop by collection".
     *
     * @return list<ProductCollection>
     */
    public function findActiveForStorefront(): array
    {
        /** @var list<ProductCollection> $items */
        $items = $this->createQueryBuilder('c')
            ->where('c.isActive = TRUE')
            ->orderBy('c.displayOrder', 'ASC')
            ->addOrderBy('c.id', 'DESC')
            ->getQuery()
            ->getResult();
        return $items;
    }

    public function findBySlug(string $slug, bool $activeOnly = true): ?ProductCollection
    {
        $qb = $this->createQueryBuilder('c')
            ->where('c.slug = :slug')
            ->setParameter('slug', $slug)
            ->setMaxResults(1);
        if ($activeOnly) {
            $qb->andWhere('c.isActive = TRUE');
        }
        /** @var ProductCollection|null $col */
        $col = $qb->getQuery()->getOneOrNullResult();
        return $col;
    }

    public function findByLegacyId(int $legacyId): ?ProductCollection
    {
        /** @var ProductCollection|null $col */
        $col = $this->findOneBy(['legacyCollectionId' => $legacyId]);
        return $col;
    }

    /**
     * The representative image for a collection card: the primary image of the
     * FIRST curated product (by curation order) that is live on the storefront
     * (active + vendor approved + has an image). Null when the collection has
     * no such product (the caller falls back to the collection's cover image).
     */
    public function representativeImageUrl(int $collectionId): ?string
    {
        /** @var list<array{url: string}> $rows */
        $rows = $this->getEntityManager()->createQueryBuilder()
            ->select('p.primaryImageUrl AS url')
            ->from(CollectionProduct::class, 'cp')
            ->join('cp.product', 'p')
            ->join('p.vendor', 'v')
            ->where('cp.collection = :cid')
            ->andWhere('p.isActive = TRUE')
            ->andWhere('p.primaryImageUrl IS NOT NULL')
            ->andWhere('v.isActive = TRUE')
            ->andWhere('v.status = :approved')
            ->setParameter('cid', $collectionId)
            ->setParameter('approved', Vendor::STATUS_APPROVED)
            ->orderBy('cp.sortOrder', 'ASC')
            ->addOrderBy('cp.id', 'ASC')
            ->setMaxResults(1)
            ->getQuery()
            ->getArrayResult();

        return $rows[0]['url'] ?? null;
    }

    /** Count of storefront-visible (active + approved vendor) products in a collection. */
    public function countActiveProducts(int $collectionId): int
    {
        return (int) $this->getEntityManager()->createQueryBuilder()
            ->select('COUNT(p.id)')
            ->from(CollectionProduct::class, 'cp')
            ->join('cp.product', 'p')
            ->join('p.vendor', 'v')
            ->where('cp.collection = :cid')
            ->andWhere('p.isActive = TRUE')
            ->andWhere('v.isActive = TRUE')
            ->andWhere('v.status = :approved')
            ->setParameter('cid', $collectionId)
            ->setParameter('approved', Vendor::STATUS_APPROVED)
            ->getQuery()
            ->getSingleScalarResult();
    }
}
