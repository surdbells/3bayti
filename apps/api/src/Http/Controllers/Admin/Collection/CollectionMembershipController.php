<?php

declare(strict_types=1);

namespace Bayti\Api\Http\Controllers\Admin\Collection;

use Bayti\Api\Domain\Catalog\CollectionCurationService;
use Bayti\Api\Domain\Catalog\CollectionProduct;
use Bayti\Api\Domain\Catalog\CollectionProductRepository;
use Bayti\Api\Domain\Catalog\Product;
use Bayti\Api\Domain\Catalog\ProductCollection;
use Bayti\Api\Domain\Catalog\ProductCollectionRepository;
use Bayti\Api\Domain\Catalog\ProductRepository;
use Bayti\Api\Domain\Catalog\StaleCollectionMembershipException;
use Bayti\Api\Http\Errors\ErrorCodes;
use Bayti\Api\Http\Errors\HttpException;
use Bayti\Api\Http\Responder;
use Bayti\Api\Http\Validator\IdListParser;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * Product-side view of admin collection curation (routed by method). A product
 * can belong to any number of collections (collection_products is a
 * many-to-many join); these endpoints read and set that membership from the
 * PRODUCT's point of view, complementing the collection-side
 * GET/PUT /v3/admin/collections/{id}/products.
 *
 *   GET /v3/admin/collections/memberships?product_ids=1,2,3   batch badges (builder cards)
 *   GET /v3/admin/products/{id}/collections                    one product's collections
 *   PUT /v3/admin/products/{id}/collections                    set its COMPLETE membership set
 *
 * Product ids are v3 ids (never legacy ids). Each collection list is ordered
 * like the admin collections list: display_order ASC NULLS LAST, then id DESC.
 */
final class CollectionMembershipController
{
    use Responder;

    /** Max distinct product ids per batch membership lookup. */
    private const MAX_BATCH = 200;

    public function __construct(
        protected readonly ResponseFactoryInterface $responseFactory,
        private readonly EntityManagerInterface $em,
        private readonly CollectionCurationService $curation,
    ) {}

    protected function getResponseFactory(): ResponseFactoryInterface { return $this->responseFactory; }

    /**
     * GET /v3/admin/collections/memberships?product_ids=1,2,3
     *
     * For each requested product (1..200 distinct ids), the collections it is
     * curated into: {"data": {"<product_id>": [{id, name, slug, is_active}]}}.
     * EVERY requested id is a key, unknown/unassigned ids map to []. One query.
     */
    public function forProducts(ServerRequestInterface $request): ResponseInterface
    {
        $productIds = IdListParser::fromCsv(
            $request->getQueryParams()['product_ids'] ?? null,
            'product_ids',
            self::MAX_BATCH,
        );

        /** @var array<int, list<array<string, mixed>>> $byProduct */
        $byProduct = [];
        foreach ($productIds as $pid) {
            $byProduct[$pid] = [];
        }
        foreach ($this->joinRepo()->membershipsForProducts($productIds) as $row) {
            $byProduct[$row['product_id']][] = [
                'id'        => $row['id'],
                'name'      => $row['name'],
                'slug'      => $row['slug'],
                'is_active' => $row['is_active'],
            ];
        }

        // Cast to object: keys are product ids, so the payload must always be
        // a JSON object (never a list), whatever ids were asked for.
        return $this->ok(['data' => (object) $byProduct]);
    }

    /**
     * GET /v3/admin/products/{id}/collections
     *
     * The collections this product is curated into, with its position
     * (sort_order) in each. 404 for an unknown product.
     */
    public function forProduct(ServerRequestInterface $request): ResponseInterface
    {
        $product = $this->findProductOrFail($request->getAttribute('id'));
        return $this->ok(['data' => $this->productCollections((int) $product->getId())]);
    }

    /**
     * PUT /v3/admin/products/{id}/collections
     *
     * Body: { "collection_ids": [3, 8] } — the product's COMPLETE membership
     * set (an empty array removes it from every collection; duplicates
     * collapse). It is removed from collections not listed, appended at the
     * END of newly listed ones, and keeps its position in collections it
     * stays in. Unknown collection ids → 422; unknown product → 404.
     *
     * Optional precondition "expected_collection_ids": the collection ids
     * (any order) the client loaded for this product. When the stored set
     * differs (e.g. a collection builder saved this product in or out since)
     * nothing is written and the response is 409 CONFLICT_STALE with
     * error.details.current_collection_ids (ascending). Omitted / null = no
     * check.
     */
    public function setForProduct(ServerRequestInterface $request): ResponseInterface
    {
        $product = $this->findProductOrFail($request->getAttribute('id'));
        $body = (array) ($request->getParsedBody() ?? []);
        $ids = IdListParser::fromArray($body['collection_ids'] ?? null, 'collection_ids');
        $expected = ($body['expected_collection_ids'] ?? null) === null
            ? null
            : IdListParser::fromArray($body['expected_collection_ids'], 'expected_collection_ids');

        $collections = [];
        if ($ids !== []) {
            /** @var ProductCollectionRepository $collectionRepo */
            $collectionRepo = $this->em->getRepository(ProductCollection::class);
            $byId = [];
            foreach ($collectionRepo->findBy(['id' => $ids]) as $c) {
                $byId[(int) $c->getId()] = $c;
            }
            $missing = array_values(array_diff($ids, array_keys($byId)));
            if ($missing !== []) {
                throw HttpException::validation([
                    'collection_ids' => ['Unknown collection id(s): ' . implode(', ', $missing)],
                ]);
            }
            foreach ($ids as $id) {
                $collections[] = $byId[$id];
            }
        }

        try {
            $this->curation->setProductCollections($product, $collections, $expected);
        } catch (StaleCollectionMembershipException $e) {
            throw HttpException::conflict(
                ErrorCodes::CONFLICT_STALE,
                "This product's collections were changed elsewhere since you loaded them. Reload to see the latest, then re-apply your changes.",
                $e->currentIds === null ? [] : ['current_collection_ids' => $e->currentIds],
            );
        }

        return $this->ok(['data' => $this->productCollections((int) $product->getId())]);
    }

    // ── helpers ─────────────────────────────────────────────────────

    /** @return list<array{id: int, name: string, slug: string, is_active: bool, sort_order: int}> */
    private function productCollections(int $productId): array
    {
        return array_map(static fn (array $row): array => [
            'id'         => $row['id'],
            'name'       => $row['name'],
            'slug'       => $row['slug'],
            'is_active'  => $row['is_active'],
            'sort_order' => $row['sort_order'],
        ], $this->joinRepo()->membershipsForProducts([$productId]));
    }

    /** @param mixed $rawId the {id:[0-9]+} route attribute (a digit string) */
    private function findProductOrFail(mixed $rawId): Product
    {
        $id = is_int($rawId) || (is_string($rawId) && ctype_digit($rawId)) ? (int) $rawId : 0;
        if ($id <= 0) {
            throw HttpException::notFound('Product not found.');
        }
        /** @var ProductRepository $repo */
        $repo = $this->em->getRepository(Product::class);
        $product = $repo->find($id);
        if ($product === null) {
            throw HttpException::notFound('Product not found.');
        }
        return $product;
    }

    private function joinRepo(): CollectionProductRepository
    {
        /** @var CollectionProductRepository $repo */
        $repo = $this->em->getRepository(CollectionProduct::class);
        return $repo;
    }
}
