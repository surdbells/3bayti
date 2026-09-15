<?php

declare(strict_types=1);

namespace Bayti\Api\Ai\Concierge;

use Bayti\Api\Ai\AiException;
use Bayti\Api\Ai\AiProviderInterface;
use Bayti\Api\Ai\Enrichment\Cosine;
use Bayti\Api\Ai\Enrichment\ProductAiAttributesStore;
use Bayti\Api\Domain\Catalog\Category;
use Bayti\Api\Domain\Catalog\CategoryRepository;
use Bayti\Api\Domain\Catalog\Product;
use Bayti\Api\Domain\Catalog\ProductRepository;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Retrieves a bounded shortlist of REAL, orderable products for a parsed intent,
 * so the ranker only ever re-orders things that genuinely exist and are in stock.
 *
 * Retrieval is deliberately built on ProductRepository::findActivePaginated,
 * which already enforces the active + approved-vendor gate. Every candidate is
 * additionally re-checked with Product::isOrderable()/isInStock() — the exact
 * boundary cart/checkout use — so Ain can never surface something that 404s at
 * add-to-cart. A semantic (embedding) pass is added in a later phase; the
 * structured-filter + keyword passes here stand alone.
 */
final class ProductRetrievalService
{
    /** Upper bound on the shortlist handed to the ranker. */
    public const SHORTLIST = 40;

    /** Below this, broaden the query so a narrow request still yields options. */
    private const THIN = 8;

    /** Cap on the enriched candidate pool the semantic pass ranks in PHP. */
    private const SEMANTIC_POOL = 200;

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly AiProviderInterface $ai,
        private readonly ProductAiAttributesStore $attributes,
    ) {
    }

    /**
     * @return list<Product> deduped, validated candidates (most-relevant first)
     */
    public function retrieve(ConciergeIntent $intent, int $limit = self::SHORTLIST): array
    {
        /** @var ProductRepository $products */
        $products = $this->em->getRepository(Product::class);

        $search = $intent->searchText();
        $categoryId = $this->resolveCategoryId($intent->categorySlug);

        /** @var array<int, Product> $collected id => product */
        $collected = [];

        // Pass 1 — the full intent: category + colours + budget + keyword search.
        $f1 = $this->baseFilters($intent, $limit);
        if ($categoryId !== null) {
            $f1['categoryId'] = $categoryId;
        }
        if ($intent->colours !== []) {
            $f1['colors'] = $intent->colours;
        }
        if ($search !== '') {
            $f1['searchQuery'] = $search;
            $f1['sort'] = 'relevance';
        } else {
            $f1['sort'] = 'best_seller';
        }
        $this->collect($products->findActivePaginated($f1)['items'], $collected);

        // Pass 2 — broaden when thin: drop colours + category, keep search + budget.
        if (count($collected) < self::THIN && $search !== '') {
            $f2 = $this->baseFilters($intent, $limit);
            $f2['searchQuery'] = $search;
            $f2['sort'] = 'relevance';
            $this->collect($products->findActivePaginated($f2)['items'], $collected);
        }

        // Pass 3 — last resort so a valid catalogue never returns nothing:
        // in-budget best-sellers.
        if ($collected === []) {
            $f3 = $this->baseFilters($intent, $limit);
            $f3['sort'] = 'best_seller';
            $this->collect($products->findActivePaginated($f3)['items'], $collected);
        }

        // Semantic pass: when products are enriched with embeddings and AI is on,
        // re-order + expand the shortlist by meaning (occasion/colour/style the
        // exact keywords miss). No-op otherwise — keyword order stands.
        $semantic = $this->semanticPass($intent, $search, $this->resolveCategoryId($intent->categorySlug), $collected, $limit);
        if ($semantic !== null) {
            return $semantic;
        }

        return array_slice(array_values($collected), 0, $limit);
    }

    /**
     * @param array<int, Product> $collected id => product from the keyword/filter passes
     * @return list<Product>|null null = no semantic ordering available (keep keyword order)
     */
    private function semanticPass(ConciergeIntent $intent, string $search, ?int $categoryId, array $collected, int $limit): ?array
    {
        if (!$this->ai->isEnabled() || !$this->attributes->hasAnyEmbedding()) {
            return null;
        }
        $queryText = $search !== '' ? $search : implode(' ', $intent->keywords);
        if (trim($queryText) === '') {
            return null;
        }
        try {
            $queryVec = $this->ai->embed([$queryText])[0] ?? [];
        } catch (AiException) {
            return null;
        }
        if ($queryVec === []) {
            return null;
        }

        // Fast path: pgvector kNN over the whole enriched catalogue (when enabled).
        if ($this->attributes->hasPgvector()) {
            $ids = $this->attributes->pgvectorRank($queryVec, $limit, $categoryId, $intent->budgetMin, $intent->budgetMax);
            if ($ids !== []) {
                return $this->assemble($ids, $this->loadProducts($ids), $collected, $limit);
            }
        }

        // Fallback: PHP cosine over a bounded, budget/category-scoped enriched pool
        // (the keyword hits + a best-seller set, so semantics can surface items the
        // exact keywords missed).
        /** @var array<int, Product> $pool */
        $pool = $collected;
        $poolFilters = $this->baseFilters($intent, min($limit * 5, self::SEMANTIC_POOL));
        $poolFilters['sort'] = 'best_seller';
        if ($categoryId !== null) {
            $poolFilters['categoryId'] = $categoryId;
        }
        /** @var ProductRepository $products */
        $products = $this->em->getRepository(Product::class);
        $this->collect($products->findActivePaginated($poolFilters)['items'], $pool);

        $embeddings = $this->attributes->fetchEmbeddings(array_keys($pool));
        if ($embeddings === []) {
            return null;
        }

        return $this->assemble(Cosine::rank($queryVec, $embeddings, $limit), $pool, $collected, $limit);
    }

    /**
     * Order semantic ids first (mapped to real products), then append any exact
     * keyword hits not already included (so we never lose an exact match), capped.
     *
     * @param list<int> $orderedIds
     * @param array<int, Product> $map     id => product for the ordered ids
     * @param array<int, Product> $collected keyword/filter hits
     * @return list<Product>
     */
    private function assemble(array $orderedIds, array $map, array $collected, int $limit): array
    {
        $out = [];
        foreach ($orderedIds as $id) {
            if (isset($map[$id])) {
                $out[$id] = $map[$id];
            }
        }
        foreach ($collected as $id => $p) {
            if (count($out) >= $limit) {
                break;
            }
            $out[$id] ??= $p;
        }
        return array_slice(array_values($out), 0, $limit);
    }

    /**
     * Load products by id, keyed + re-validated (orderable + in stock).
     *
     * @param list<int> $ids
     * @return array<int, Product>
     */
    private function loadProducts(array $ids): array
    {
        if ($ids === []) {
            return [];
        }
        /** @var list<Product> $found */
        $found = $this->em->getRepository(Product::class)->findBy(['id' => $ids]);
        $out = [];
        foreach ($found as $p) {
            $id = $p->getId();
            if ($id !== null && $p->isOrderable() && $p->isInStock()) {
                $out[$id] = $p;
            }
        }
        return $out;
    }

    /**
     * Filters common to every pass: in-stock only, bounded, budget applied.
     *
     * @return array<string, mixed>
     */
    private function baseFilters(ConciergeIntent $intent, int $limit): array
    {
        $f = ['inStock' => true, 'limit' => $limit, 'offset' => 0];
        if ($intent->budgetMin !== null && $intent->budgetMin > 0) {
            $f['minPrice'] = $intent->budgetMin;
        }
        if ($intent->budgetMax !== null && $intent->budgetMax > 0) {
            $f['maxPrice'] = $intent->budgetMax;
        }
        return $f;
    }

    private function resolveCategoryId(?string $slug): ?int
    {
        if ($slug === null) {
            return null;
        }
        /** @var CategoryRepository $categories */
        $categories = $this->em->getRepository(Category::class);
        return $categories->findBySlug($slug)?->getId();
    }

    /**
     * @param array<int, Product> $items
     * @param array<int, Product> $collected
     */
    private function collect(array $items, array &$collected): void
    {
        foreach ($items as $p) {
            $id = $p->getId();
            // Re-validate against the same predicate the cart enforces — the
            // hard "this is real and buyable" boundary.
            if ($id !== null && !isset($collected[$id]) && $p->isOrderable() && $p->isInStock()) {
                $collected[$id] = $p;
            }
        }
    }
}
