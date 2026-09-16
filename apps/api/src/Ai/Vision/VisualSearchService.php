<?php

declare(strict_types=1);

namespace Bayti\Api\Ai\Vision;

use Bayti\Api\Ai\Enrichment\Cosine;
use Bayti\Api\Ai\Enrichment\ProductAiAttributesStore;
use Bayti\Api\Domain\Catalog\Product;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Visual product search: a query image → a vector (via {@see VisionEmbedderInterface})
 * → nearest products by embedding, dropping anything not orderable + in stock
 * (the same anti-hallucination boundary as the rest of Ain). Uses pgvector kNN
 * when available, else a bounded PHP-cosine pass over sellable enriched products.
 *
 * Reuses the products' EXISTING text enrichment (product_ai_attributes.embedding)
 * — no separate image-embedding store — so it works the moment vision is enabled
 * and the catalogue has been enriched by ai:build-product-attributes.
 */
final class VisualSearchService
{
    public const DEFAULT_LIMIT = 24;
    private const MIN_LIMIT = 1;
    private const MAX_LIMIT = 48;
    private const POOL = 400;

    public function __construct(
        private readonly VisionEmbedderInterface $vision,
        private readonly ProductAiAttributesStore $attributes,
        private readonly EntityManagerInterface $em,
    ) {
    }

    public function search(string $bytes, string $mime, int $limit = self::DEFAULT_LIMIT): VisualSearchResult
    {
        $limit = max(self::MIN_LIMIT, min(self::MAX_LIMIT, $limit));

        if (!$this->vision->isEnabled()) {
            return new VisualSearchResult(null, []);
        }

        $embedding = $this->vision->embedImage($bytes, $mime);
        if ($embedding->isEmpty()) {
            return new VisualSearchResult($embedding->description, []);
        }

        // Over-fetch so the orderable/in-stock gate still leaves a full page.
        $rankLimit = $limit * 2;

        // Prefer pgvector kNN, but FALL BACK to PHP-cosine when it yields nothing
        // (e.g. the vector column exists but isn't backfilled yet, or a transient
        // kNN error) — mirroring the concierge's ProductRetrievalService so a
        // half-rolled-out pgvector never silently zeroes out visual search.
        $ids = [];
        if ($this->attributes->hasPgvector()) {
            $ids = $this->attributes->pgvectorRank($embedding->vector, $rankLimit, null, null, null);
        }
        if ($ids === []) {
            $ids = Cosine::rank($embedding->vector, $this->attributes->fetchSellableEmbeddings(self::POOL), $rankLimit);
        }

        $products = $this->loadProducts($ids, $limit);

        return new VisualSearchResult($embedding->description, $products);
    }

    /**
     * Hydrate + gate (orderable + in stock) the ranked ids, preserving the kNN
     * order, capped at $limit.
     *
     * @param list<int> $ids nearest-first
     * @return list<Product>
     */
    private function loadProducts(array $ids, int $limit): array
    {
        if ($ids === []) {
            return [];
        }
        $products = $this->em->getRepository(Product::class)->findBy(['id' => $ids]);

        $byId = [];
        foreach ($products as $product) {
            $pid = $product->getId();
            if ($pid !== null) {
                $byId[$pid] = $product;
            }
        }

        $out = [];
        foreach ($ids as $id) {
            if (count($out) >= $limit) {
                break;
            }
            $product = $byId[$id] ?? null;
            if ($product instanceof Product && $product->isOrderable() && $product->isInStock()) {
                $out[] = $product;
            }
        }
        return $out;
    }
}
