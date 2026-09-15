<?php

declare(strict_types=1);

namespace Bayti\Api\Ai\Concierge;

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

    public function __construct(private readonly EntityManagerInterface $em)
    {
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

        return array_slice(array_values($collected), 0, $limit);
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
