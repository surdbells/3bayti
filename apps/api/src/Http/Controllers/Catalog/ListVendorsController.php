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
 * GET /v3/vendors  (public) — the store directory source.
 *
 * Active vendors, directoryShape: a publicShape superset (so existing
 * consumers keep every field) PLUS a rating aggregate + up to 5 embedded
 * newest in-stock products per card, and an optional case-insensitive
 * name search. Returns envelope { data, meta } with honest pagination.
 *
 * Query params:
 *   - limit  (default 24, clamped 1..48)
 *   - offset (>= 0)
 *   - q      (optional vendor-name search; blank/whitespace = ignored)
 *
 * Pagination (Stores H2.A): real limit/offset + has_more, computed from a
 * companion COUNT in the repo. Replaces the prior "emit everything in one
 * page" behaviour so apps/web's directory load-more works.
 */
final class ListVendorsController
{
    use Responder;

    private const DEFAULT_LIMIT = 24;
    private const MAX_LIMIT = 48;
    private const EMBEDDED_PRODUCTS_PER_VENDOR = 5;

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

        $limit = self::DEFAULT_LIMIT;
        $rawLimit = $query['limit'] ?? null;
        if (is_numeric($rawLimit)) {
            $parsed = (int) $rawLimit;
            if ($parsed >= 1 && $parsed <= self::MAX_LIMIT) {
                $limit = $parsed;
            } elseif ($parsed > self::MAX_LIMIT) {
                $limit = self::MAX_LIMIT;
            }
            // parsed < 1 -> keep DEFAULT_LIMIT
        }

        $offset = 0;
        $rawOffset = $query['offset'] ?? null;
        if (is_numeric($rawOffset) && (int) $rawOffset > 0) {
            $offset = (int) $rawOffset;
        }

        $rawQ = $query['q'] ?? null;
        $search = is_string($rawQ) && trim($rawQ) !== '' ? trim($rawQ) : null;

        /** @var VendorRepository $repo */
        $repo = $this->em->getRepository(Vendor::class);
        $result = $repo->findActiveWithStatsPaginated($limit, $offset, $search);
        $rows = $result['items'];
        $total = $result['total'];

        /** @var ProductRepository $productRepo */
        $productRepo = $this->em->getRepository(Product::class);

        // Per-vendor product fetch — N+1 bounded by `limit`, matching the
        // Spotlight endpoint. Each card gets up to 5 newest in-stock products.
        $items = [];
        foreach ($rows as $row) {
            /** @var Vendor $vendor */
            $vendor = $row['vendor'];

            $productsResult = $productRepo->findActivePaginated([
                'vendorId' => $vendor->getId(),
                'sort' => 'newest',
                'inStock' => true,
                'limit' => self::EMBEDDED_PRODUCTS_PER_VENDOR,
                'offset' => 0,
            ]);

            $items[] = $this->serializer->directoryShape(
                $vendor,
                $productsResult['items'],
                $row['rating'],
                $row['ratingCount'],
            );
        }

        return $this->ok(PaginatedEnvelope::build(
            $items,
            total: $total,
            limit: $limit,
            offset: $offset,
        ));
    }
}
