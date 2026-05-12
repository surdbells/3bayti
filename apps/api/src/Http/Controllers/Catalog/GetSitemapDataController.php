<?php

declare(strict_types=1);

namespace Bayti\Api\Http\Controllers\Catalog;

use Bayti\Api\Domain\Catalog\Category;
use Bayti\Api\Domain\Catalog\Product;
use Bayti\Api\Domain\Catalog\Vendor;
use Bayti\Api\Http\Responder;
use DateTimeInterface;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * GET /v3/sitemap-data
 *
 * Build-time sitemap data for apps/web's generate-sitemap.mjs script.
 *
 * Response shape (mirrors v2 contract exactly so the script works
 * unchanged when apps/web flips to v3):
 *
 *   {
 *     "categories": [{ slug, last_modified }, ...],
 *     "products":   [{ slug, last_modified }, ...],
 *     "vendors":    [{ slug, last_modified }, ...]
 *   }
 *
 * NOT wrapped in { data: ... } envelope because the script was written
 * against the v2 shape which returns the object directly. The script
 * does `(await res.json()).categories` — wrapping would break it.
 *
 * Pagination: NONE. This endpoint emits ALL active records. It's a
 * build-time call (runs once per deploy), so the cost is acceptable
 * even at 2000 products. If we grow to 100K+ products we'll need
 * to chunk this — but that's a year+ out.
 *
 * Caching: NONE for now. The endpoint is called once per build (every
 * few hours at most). At ~2000 product rows it's <100ms. We can add
 * HTTP caching headers later if needed.
 *
 * Includes only ACTIVE records — soft-deleted/draft excluded from
 * the sitemap (we don't want search engines indexing dead URLs).
 */
final class GetSitemapDataController
{
    use Responder;

    public function __construct(
        protected readonly ResponseFactoryInterface $responseFactory,
        private readonly EntityManagerInterface $em,
    ) {
    }

    protected function getResponseFactory(): ResponseFactoryInterface
    {
        return $this->responseFactory;
    }

    public function __invoke(ServerRequestInterface $request): ResponseInterface
    {
        // Use raw SQL via DBAL — avoids hydrating full entities for
        // what is essentially a slug + timestamp dump.
        $conn = $this->em->getConnection();

        $categories = $conn->fetchAllAssociative("
            SELECT slug, updated_at
            FROM categories
            WHERE is_active = TRUE
            ORDER BY updated_at DESC
        ");

        $vendors = $conn->fetchAllAssociative("
            SELECT slug, updated_at
            FROM vendors
            WHERE is_active = TRUE
            ORDER BY updated_at DESC
        ");

        // Products limited to 50K to bound payload size — if we exceed
        // that we have bigger architectural problems. Current production
        // is 1928, so plenty of headroom.
        $products = $conn->fetchAllAssociative("
            SELECT slug, updated_at
            FROM products
            WHERE is_active = TRUE
            ORDER BY updated_at DESC
            LIMIT 50000
        ");

        return $this->ok([
            'categories' => array_map([$this, 'formatRow'], $categories),
            'products' => array_map([$this, 'formatRow'], $products),
            'vendors' => array_map([$this, 'formatRow'], $vendors),
        ]);
    }

    /**
     * @param array{slug: string, updated_at: string} $row
     * @return array{slug: string, last_modified: string}
     */
    private function formatRow(array $row): array
    {
        // updated_at comes from Postgres as 'YYYY-MM-DD HH:MM:SS+TZ' string.
        // Reformat to ISO 8601 for consistency with the rest of v3 timestamps.
        $dt = new \DateTimeImmutable($row['updated_at']);
        return [
            'slug' => $row['slug'],
            'last_modified' => $dt->format(DateTimeInterface::ATOM),
        ];
    }
}
