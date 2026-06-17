<?php

declare(strict_types=1);

namespace Bayti\Api\Http\Controllers\Catalog;

use Bayti\Api\Domain\Catalog\Product;
use Bayti\Api\Domain\Catalog\ProductRepository;
use Bayti\Api\Domain\Catalog\Vendor;
use Bayti\Api\Domain\Catalog\VendorRepository;
use Bayti\Api\Http\PaginatedEnvelope;
use Bayti\Api\Http\Responder;
use Bayti\Api\Http\Serializers\VendorSerializer;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * GET /v3/featured-vendors
 *
 * Designer Spotlight feed for apps/web home page. Returns curated
 * vendors (is_active = true AND is_featured = true) with up to N
 * embedded product thumbnails per vendor.
 *
 * Query parameters
 * ================
 *   ?limit=4    (default 4; clamped to [1..12] per Q-LimitClamp=A)
 *
 * No offset/pagination — Spotlight is a curated surface. has_more
 * is always false in the response meta. If business later wants
 * paginated featured vendors, this is a future enhancement.
 *
 * Response shape
 * ==============
 * Matches the apps/web FeaturedVendor[] contract (see
 * apps/web/src/app/features/catalog/designer-card.ts).
 *
 *   {
 *     "data": [
 *       {
 *         "slug": "...",
 *         "name": "...",
 *         "description": "..." | null,
 *         "rating": 4.5 | null,
 *         "rating_count": 12,
 *         "products": [
 *           {"id": ..., "slug": "...", "image_url": "...", "name": "..."}
 *         ]
 *       }
 *     ],
 *     "meta": {"total": 4, "count": 4, "limit": 4, "offset": 0, "has_more": false}
 *   }
 *
 * Embedded products (Stores #4)
 * =============================
 * Each vendor gets up to 5 of their newest IN-STOCK products
 * (createdAt DESC; in-stock = stock_quantity > 0 OR allow_oversell,
 * matching the serializer's in_stock boolean). Computed via
 * ProductRepository::findActivePaginated filtered by vendorId + inStock.
 * This is N+1 by design at Spotlight scale (limit=4 vendors → 1 vendor
 * query + ≤4 product queries, within bounds for a home-page request).
 *
 * Rating aggregate (Q-Rating = A)
 * ===============================
 * Computed at request time via SQL AVG()/COUNT() with LEFT JOIN
 * on approved ProductReviews scoped to the vendor. Single query
 * via VendorRepository::findFeaturedWithStats. Vendors with zero
 * approved reviews get null rating + 0 rating_count.
 *
 * Fallback (Stores H0.1)
 * ======================
 * When no vendor is admin-flagged featured, this falls back to the
 * top VERIFIED vendors (VendorRepository::findTopVerifiedWithStats)
 * that have in-stock products, so the storefront's "Stores we love"
 * Spotlight is never empty just because curation hasn't been set up.
 * Curated featured vendors always take precedence; the fallback only
 * fires on a completely empty featured set.
 *
 * Failure modes
 * =============
 * Empty featured set → fall back to verified vendors. Empty featured
 * AND no verified vendors with stock → valid empty envelope, not 404.
 * Returning 200+empty array (rather than 404) lets apps/web
 * differentiate 'nothing to show right now' from 'endpoint broken'.
 */
final class ListFeaturedVendorsController
{
    use Responder;

    /** Default number of featured vendors returned when ?limit= is absent. */
    private const DEFAULT_LIMIT = 4;

    /** Max number of featured vendors allowed per request. Q-LimitClamp = A. */
    private const MAX_LIMIT = 12;

    /** Number of embedded product thumbnails per vendor (Stores #4: 5 in-stock). */
    private const EMBEDDED_PRODUCTS_PER_VENDOR = 5;

    /**
     * Fallback over-fetch multiplier (Stores H0.1). When no vendor is
     * admin-flagged featured and we fall back to top verified vendors,
     * we request this many × the caller's limit because the product
     * loop skips any fallback vendor with no in-stock products. A
     * featured vendor is trusted to have stock; an uncurated one isn't.
     */
    private const FALLBACK_OVERFETCH = 3;

    public function __construct(
        protected readonly ResponseFactoryInterface $responseFactory,
        private readonly EntityManagerInterface $em,
        private readonly VendorSerializer $serializer,
    ) {
    }

    protected function getResponseFactory(): ResponseFactoryInterface
    {
        return $this->responseFactory;
    }

    public function __invoke(ServerRequestInterface $request): ResponseInterface
    {
        $query = $request->getQueryParams();

        // Parse + clamp limit. Q-LimitClamp = A: max 12.
        // Negative / non-numeric values fall back to DEFAULT_LIMIT.
        $rawLimit = $query['limit'] ?? null;
        $limit = self::DEFAULT_LIMIT;
        if (is_string($rawLimit) && ctype_digit($rawLimit)) {
            $parsed = (int) $rawLimit;
            if ($parsed >= 1 && $parsed <= self::MAX_LIMIT) {
                $limit = $parsed;
            } elseif ($parsed > self::MAX_LIMIT) {
                $limit = self::MAX_LIMIT;
            }
            // parsed < 1 → keep DEFAULT_LIMIT
        }

        /** @var VendorRepository $vendorRepo */
        $vendorRepo = $this->em->getRepository(Vendor::class);

        // Primary source: admin-curated featured vendors.
        // Q-Sort = A: alphabetical name ASC, applied in the repo method.
        $vendorRows = $vendorRepo->findFeaturedWithStats($limit, 0);

        // Fallback (Stores H0.1): when no vendor has been admin-flagged
        // featured, fall back to top verified vendors so the storefront's
        // "Stores we love" Spotlight is never empty just because curation
        // hasn't been configured yet. We over-fetch (FALLBACK_OVERFETCH ×
        // limit) because the loop below skips any fallback vendor with no
        // in-stock products and stops once we have `limit` populated cards.
        $isFallback = false;
        if ($vendorRows === []) {
            $vendorRows = $vendorRepo->findTopVerifiedWithStats(
                $limit * self::FALLBACK_OVERFETCH,
                0,
            );
            $isFallback = true;
        }

        // Still nothing (no verified vendors either) → valid empty
        // envelope. Don't 404 — an unpopulated storefront is a legitimate
        // state distinct from endpoint failure.
        if ($vendorRows === []) {
            return $this->ok(PaginatedEnvelope::build(
                items: [],
                total: 0,
                limit: $limit,
                offset: 0,
            ));
        }

        /** @var ProductRepository $productRepo */
        $productRepo = $this->em->getRepository(Product::class);

        // Per-vendor product fetch (N+1 by design at Spotlight scale).
        // Each vendor gets up to EMBEDDED_PRODUCTS_PER_VENDOR newest
        // in-stock products. In the fallback path, vendors with zero
        // in-stock products are skipped so Spotlight cards are never
        // empty; we stop once we have `limit` populated cards.
        $items = [];
        foreach ($vendorRows as $row) {
            /** @var Vendor $vendor */
            $vendor = $row['vendor'];

            $productsResult = $productRepo->findActivePaginated([
                'vendorId' => $vendor->getId(),
                'sort' => 'newest',
                'inStock' => true,
                'limit' => self::EMBEDDED_PRODUCTS_PER_VENDOR,
                'offset' => 0,
            ]);

            if ($isFallback && $productsResult['items'] === []) {
                continue;
            }

            $items[] = $this->serializer->featuredShape(
                vendor: $vendor,
                embeddedProducts: $productsResult['items'],
                rating: $row['rating'],
                ratingCount: $row['ratingCount'],
            );

            if (count($items) >= $limit) {
                break;
            }
        }

        return $this->ok(PaginatedEnvelope::build(
            items: $items,
            total: count($items),
            limit: $limit,
            offset: 0,
        ));
    }
}
