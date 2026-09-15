<?php

declare(strict_types=1);

namespace Bayti\Api\Ai\CompleteLook;

use Bayti\Api\Ai\Enrichment\Cosine;
use Bayti\Api\Ai\Enrichment\ProductAiAttributesStore;
use Bayti\Api\Domain\Catalog\Product;
use Bayti\Api\Domain\Catalog\ProductRepository;
use Bayti\Api\Domain\Catalog\RecommendationsService;
use Doctrine\ORM\EntityManagerInterface;

/**
 * "Complete the Look" — live-computed complementary products for a seed product.
 *
 * Deliberately DATA-DRIVEN: no hardcoded category-pairing map (which would be a
 * guess against the live taxonomy). Complements are in-stock products from
 * categories OTHER than the seed's, ranked by shared occasion/colour/style — the
 * enrichment embeddings — when available, and diversified across categories so
 * the result reads as a coherent look rather than six near-duplicates. A
 * co-purchase/popular fallback keeps the strip populated when there are no
 * cross-category complements yet, and best-seller order is the floor when no
 * enrichment has been built.
 *
 * Every candidate is re-validated with Product::isOrderable() && isInStock() —
 * the exact cart/checkout boundary — so a complement can never 404 at add-to-cart
 * or surface out-of-stock. (The RecommendationsService fallback only guarantees
 * orderable, so we re-check in-stock here.)
 */
final class CompleteTheLookService
{
    /** Bounded candidate pool the ranking works over. */
    private const POOL = 120;

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly ProductAiAttributesStore $attributes,
        private readonly RecommendationsService $recommendations,
    ) {
    }

    /**
     * @return list<CompleteLookItem>
     */
    public function forProduct(Product $seed, string $locale = 'en', int $limit = 6): array
    {
        $seedId = $seed->getId();
        if ($seedId === null) {
            return [];
        }
        $seedCategoryId = $seed->getCategory()?->getId();

        // Primary: complements from OTHER categories (real "goes with" items).
        $pool = $this->crossCategoryPool($seedId, $seedCategoryId);
        // Fallback: co-purchase / popular (any category) so the strip is never empty.
        if ($pool === []) {
            $pool = $this->recommendationPool($seedId);
        }
        if ($pool === []) {
            return [];
        }

        $orderedIds = $this->orderBySimilarity($seedId, $pool);

        return $this->diversify($orderedIds, $pool, $limit, $locale);
    }

    /**
     * In-stock, orderable products from categories other than the seed's,
     * best-seller ordered, keyed by id (seed excluded).
     *
     * @return array<int, Product>
     */
    private function crossCategoryPool(int $seedId, ?int $seedCategoryId): array
    {
        /** @var ProductRepository $products */
        $products = $this->em->getRepository(Product::class);
        $rows = $products->findActivePaginated([
            'inStock' => true,
            'sort' => 'best_seller',
            'limit' => self::POOL,
            'offset' => 0,
        ])['items'];

        $pool = [];
        foreach ($rows as $p) {
            $id = $p->getId();
            if ($id === null || $id === $seedId) {
                continue;
            }
            // Complements come from a DIFFERENT category than the seed.
            if ($seedCategoryId !== null && $p->getCategory()?->getId() === $seedCategoryId) {
                continue;
            }
            if ($p->isOrderable() && $p->isInStock()) {
                $pool[$id] = $p;
            }
        }
        return $pool;
    }

    /**
     * Co-purchase / popular recommendations, re-validated for stock (the
     * recommendation read-boundary only guarantees orderable).
     *
     * @return array<int, Product>
     */
    private function recommendationPool(int $seedId): array
    {
        $pool = [];
        foreach ($this->recommendations->getRecommendationsForProduct($seedId, self::POOL) as $rec) {
            $p = $rec['product'];
            $id = $p->getId();
            if ($id === null || $id === $seedId) {
                continue;
            }
            if ($p->isOrderable() && $p->isInStock()) {
                $pool[$id] = $p;
            }
        }
        return $pool;
    }

    /**
     * Order the pool by embedding similarity to the seed when enrichment exists,
     * else keep best-seller order. Rank-then-append so candidates without an
     * embedding (progressive enrichment) are never dropped.
     *
     * @param array<int, Product> $pool
     * @return list<int>
     */
    private function orderBySimilarity(int $seedId, array $pool): array
    {
        $bestSellerOrder = array_keys($pool);

        if (!$this->attributes->hasAnyEmbedding()) {
            return $bestSellerOrder;
        }
        $seedVec = $this->attributes->fetchEmbeddings([$seedId])[$seedId] ?? [];
        if ($seedVec === []) {
            return $bestSellerOrder; // seed not enriched yet — keep best-seller order.
        }
        $embeddings = $this->attributes->fetchEmbeddings($bestSellerOrder);
        if ($embeddings === []) {
            return $bestSellerOrder;
        }

        $ranked = Cosine::rank($seedVec, $embeddings, count($pool));
        if ($ranked === []) {
            return $bestSellerOrder;
        }

        $seen = array_flip($ranked);
        $out = $ranked;
        foreach ($bestSellerOrder as $id) {
            if (!isset($seen[$id])) {
                $out[] = $id;
            }
        }
        return $out;
    }

    /**
     * Take one item per complementary category first (a coherent look), then
     * fill any remaining slots.
     *
     * @param list<int> $orderedIds
     * @param array<int, Product> $pool
     * @return list<CompleteLookItem>
     */
    private function diversify(array $orderedIds, array $pool, int $limit, string $locale): array
    {
        $reason = $this->reason($locale);
        $items = [];
        $used = [];
        $perCategory = [];

        foreach ($orderedIds as $id) {
            if (count($items) >= $limit) {
                break;
            }
            $p = $pool[$id];
            $cat = $p->getCategory()?->getSlug() ?? 'recommended';
            if (($perCategory[$cat] ?? 0) >= 1) {
                continue;
            }
            $perCategory[$cat] = 1;
            $used[$id] = true;
            $items[] = new CompleteLookItem($p, $cat, $reason);
        }

        if (count($items) < $limit) {
            foreach ($orderedIds as $id) {
                if (count($items) >= $limit) {
                    break;
                }
                if (isset($used[$id])) {
                    continue;
                }
                $p = $pool[$id];
                $items[] = new CompleteLookItem($p, $p->getCategory()?->getSlug() ?? 'recommended', $reason);
            }
        }

        return $items;
    }

    private function reason(string $locale): string
    {
        return $locale === 'ar' ? 'يكمل الإطلالة' : 'Completes the look';
    }
}
