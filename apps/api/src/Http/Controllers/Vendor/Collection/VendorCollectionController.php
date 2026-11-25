<?php

declare(strict_types=1);

namespace Bayti\Api\Http\Controllers\Vendor\Collection;

use Bayti\Api\Domain\Catalog\CollectionCurationService;
use Bayti\Api\Domain\Catalog\CollectionProduct;
use Bayti\Api\Domain\Catalog\CollectionProductRepository;
use Bayti\Api\Domain\Catalog\Product;
use Bayti\Api\Domain\Catalog\ProductCollection;
use Bayti\Api\Domain\Catalog\ProductCollectionRepository;
use Bayti\Api\Domain\Catalog\ProductRepository;
use Bayti\Api\Domain\Catalog\StaleCollectionMembershipException;
use Bayti\Api\Domain\Catalog\Vendor;
use Bayti\Api\Domain\Catalog\VendorRepository;
use Bayti\Api\Domain\User\User;
use Bayti\Api\Http\Errors\ErrorCodes;
use Bayti\Api\Http\Errors\HttpException;
use Bayti\Api\Http\Middleware\AuthMiddleware;
use Bayti\Api\Http\Responder;
use Bayti\Api\Http\Serializers\ProductSerializer;
use Bayti\Api\Http\Validator\IdListParser;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * Vendors submit their OWN products to admin-curated collections (routed by
 * method). Collections stay admin-curated: vendors cannot create, edit,
 * delete or reorder them, and never see or touch another store's members.
 * They write the same collection_products join as the admin builder, so
 * vendor-added products are ordinary members the admin can see, reorder and
 * remove.
 *
 *   GET /v3/vendor/collections                   open collections + my_product_count
 *   GET /v3/vendor/collections/{id}/products     my products in one collection
 *   PUT /v3/vendor/collections/{id}/products     set my products in one collection
 *   GET /v3/vendor/products/{id}/collections     the open collections my product is in
 *   PUT /v3/vendor/products/{id}/collections     set them
 *
 * Scope: the caller's stores (VendorRepository::findIdsByOwnerUser, the same
 * resolution as the other vendor product endpoints). Only OPEN collections
 * exist for a vendor: active AND switched "Open to vendors"
 * (ProductCollection::isOpenToVendors). An inactive or closed one is a 404 /
 * an unknown id, and its existing members are left as they are. Only
 * the vendor's own, not soft-deleted products. Another store's product id is
 * treated exactly like an unknown id (404 / 422), so ids are never revealed.
 * Ids are v3 ids only.
 *
 * Writes go through CollectionCurationService (advisory locks, UnitOfWork, so
 * the EntityAuditListener records each change with the vendor's user as the
 * actor). New members are appended at the END of the curation order.
 */
final class VendorCollectionController
{
    use Responder;

    public function __construct(
        protected readonly ResponseFactoryInterface $responseFactory,
        private readonly EntityManagerInterface $em,
        private readonly ProductSerializer $productSerializer,
        private readonly CollectionCurationService $curation,
    ) {}

    protected function getResponseFactory(): ResponseFactoryInterface { return $this->responseFactory; }

    /**
     * GET /v3/vendor/collections?limit=20&offset=0
     *
     * Open collections in storefront order, each with my_product_count (how
     * many of the caller's live products it holds). No admin-only fields.
     */
    public function list(ServerRequestInterface $request): ResponseInterface
    {
        $vendorIds = $this->vendorIdsOrFail($request);
        $q      = $request->getQueryParams();
        $limit  = max(1, min(100, (int) ($q['limit']  ?? 20)));
        $offset = max(0, (int) ($q['offset'] ?? 0));

        /** @var ProductCollectionRepository $repo */
        $repo   = $this->em->getRepository(ProductCollection::class);
        $result = $repo->findPaginated($limit, $offset, openToVendorsOnly: true);

        $counts = $this->joinRepo()->countsByCollectionForVendors(
            array_map(static fn (ProductCollection $c): int => (int) $c->getId(), $result['items']),
            $vendorIds,
        );

        $data = [];
        foreach ($result['items'] as $c) {
            $data[] = [
                'id'               => $c->getId(),
                'name'             => $c->getName(),
                'slug'             => $c->getSlug(),
                'description'      => $c->getDescription(),
                'cover_image_url'  => $c->getCoverImageUrl(),
                'my_product_count' => $counts[(int) $c->getId()] ?? 0,
            ];
        }

        return $this->ok([
            'data' => $data,
            'meta' => ['total' => $result['total'], 'limit' => $limit, 'offset' => $offset],
        ]);
    }

    /**
     * GET /v3/vendor/collections/{id}/products
     *
     * The caller's own (not soft-deleted) products in this open collection,
     * in curation order, as vendor product cards (the GET /vendor/products
     * shape). Other stores' members are never included.
     */
    public function listProducts(ServerRequestInterface $request): ResponseInterface
    {
        $vendorIds  = $this->vendorIdsOrFail($request);
        $collection = $this->openCollectionOrFail($request->getAttribute('id'));

        return $this->ownProductsResponse((int) $collection->getId(), $vendorIds);
    }

    /**
     * PUT /v3/vendor/collections/{id}/products
     *
     * Body: { "product_ids": [12, 7], "expected_product_ids": [12] }
     *   product_ids: the COMPLETE set of the caller's products that should be
     *     in this collection (an empty array removes all of them; duplicates
     *     collapse). Missing ones are appended at the end (in this order),
     *     dropped ones removed, the rest untouched.
     *   expected_product_ids (optional): the caller's own ids the client
     *     loaded (any order); when they differ from what is stored nothing is
     *     written: 409 CONFLICT_STALE, error.details.current_product_ids.
     * Ids that are unknown, another store's, or soft-deleted → 422
     * product_ids. Responds like the GET.
     */
    public function setProducts(ServerRequestInterface $request): ResponseInterface
    {
        $vendorIds  = $this->vendorIdsOrFail($request);
        $collection = $this->openCollectionOrFail($request->getAttribute('id'));

        $body = (array) ($request->getParsedBody() ?? []);
        $ids = IdListParser::fromArray($body['product_ids'] ?? null, 'product_ids');
        $expected = ($body['expected_product_ids'] ?? null) === null
            ? null
            : IdListParser::fromArray($body['expected_product_ids'], 'expected_product_ids');

        $products = $this->loadOwnProducts($ids, $vendorIds);

        try {
            $this->curation->setVendorProductsInCollection($collection, $vendorIds, $products, $expected);
        } catch (StaleCollectionMembershipException $e) {
            throw HttpException::conflict(
                ErrorCodes::CONFLICT_STALE,
                "Your products in this collection were changed elsewhere since you loaded them. Reload to see the latest, then re-apply your changes.",
                $e->currentIds === null ? [] : ['current_product_ids' => $e->currentIds],
            );
        }

        return $this->ownProductsResponse((int) $collection->getId(), $vendorIds);
    }

    /**
     * GET /v3/vendor/products/{id}/collections
     *
     * The OPEN collections the caller's product is in:
     * {"data": [{id, name, slug, sort_order}]} in storefront collection order.
     * Unknown, foreign or soft-deleted product → 404.
     */
    public function forProduct(ServerRequestInterface $request): ResponseInterface
    {
        $vendorIds = $this->vendorIdsOrFail($request);
        $product   = $this->ownProductOrFail($request->getAttribute('id'), $vendorIds);

        return $this->ok(['data' => $this->openMemberships((int) $product->getId())]);
    }

    /**
     * PUT /v3/vendor/products/{id}/collections
     *
     * Body: { "collection_ids": [3, 8], "expected_collection_ids": [3] }
     *   collection_ids: the COMPLETE set of OPEN collections the product
     *     should be in (empty = none). Newly listed ones get it appended at
     *     the end; it keeps its position in ones it stays in; it is removed
     *     from open ones not listed. Memberships in INACTIVE or CLOSED collections
     *     (which the vendor cannot see) are kept as they are.
     *   expected_collection_ids (optional): the open collection ids the
     *     client loaded; a mismatch writes nothing: 409 CONFLICT_STALE,
     *     error.details.current_collection_ids (ascending).
     * Unknown, inactive or closed collection ids → 422 collection_ids; unknown,
     * foreign or soft-deleted product → 404. Responds like the GET.
     */
    public function setForProduct(ServerRequestInterface $request): ResponseInterface
    {
        $vendorIds = $this->vendorIdsOrFail($request);
        $product   = $this->ownProductOrFail($request->getAttribute('id'), $vendorIds);

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
                if ($c->isOpenToVendors()) {
                    $byId[(int) $c->getId()] = $c;
                }
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
            $this->curation->setProductCollections($product, $collections, $expected, openToVendorsOnly: true);
        } catch (StaleCollectionMembershipException $e) {
            throw HttpException::conflict(
                ErrorCodes::CONFLICT_STALE,
                "This product's collections were changed elsewhere since you loaded them. Reload to see the latest, then re-apply your changes.",
                $e->currentIds === null ? [] : ['current_collection_ids' => $e->currentIds],
            );
        }

        return $this->ok(['data' => $this->openMemberships((int) $product->getId())]);
    }

    // ── helpers ─────────────────────────────────────────────────────

    /** @return list<int> the caller's store ids (401 without a user, 403 without a store) */
    private function vendorIdsOrFail(ServerRequestInterface $request): array
    {
        $user = $request->getAttribute(AuthMiddleware::ATTR_USER);
        if (!$user instanceof User) {
            throw HttpException::unauthorized(ErrorCodes::AUTH_INVALID_TOKEN, 'Authentication required.');
        }
        /** @var VendorRepository $vendorRepo */
        $vendorRepo = $this->em->getRepository(Vendor::class);
        $ids = $vendorRepo->findIdsByOwnerUser($user);
        if ($ids === []) {
            throw HttpException::forbidden('No approved vendor account.');
        }
        return $ids;
    }

    /** @param mixed $rawId the {id:[0-9]+} route attribute */
    private function openCollectionOrFail(mixed $rawId): ProductCollection
    {
        $id = self::routeId($rawId);
        /** @var ProductCollectionRepository $repo */
        $repo = $this->em->getRepository(ProductCollection::class);
        $collection = $id > 0 ? $repo->find($id) : null;
        if ($collection === null || !$collection->isOpenToVendors()) {
            throw HttpException::notFound('Collection not found.');
        }
        return $collection;
    }

    /**
     * @param mixed     $rawId     the {id:[0-9]+} route attribute
     * @param list<int> $vendorIds
     */
    private function ownProductOrFail(mixed $rawId, array $vendorIds): Product
    {
        $id = self::routeId($rawId);
        /** @var ProductRepository $repo */
        $repo = $this->em->getRepository(Product::class);
        $product = $id > 0 ? $repo->find($id) : null;
        if ($product === null || !self::isOwnLive($product, $vendorIds)) {
            throw HttpException::notFound('Product not found.');
        }
        return $product;
    }

    /**
     * Resolve product ids to the caller's own live products, in request
     * order. Any id that is unknown, another store's or soft-deleted is a 422
     * listing the offenders (without saying which case it was).
     *
     * @param list<int> $ids
     * @param list<int> $vendorIds
     * @return list<Product>
     */
    private function loadOwnProducts(array $ids, array $vendorIds): array
    {
        if ($ids === []) {
            return [];
        }
        /** @var ProductRepository $repo */
        $repo = $this->em->getRepository(Product::class);
        $byId = [];
        foreach ($repo->findBy(['id' => $ids]) as $p) {
            if (self::isOwnLive($p, $vendorIds)) {
                $byId[(int) $p->getId()] = $p;
            }
        }
        $missing = array_values(array_diff($ids, array_keys($byId)));
        if ($missing !== []) {
            throw HttpException::validation([
                'product_ids' => ['Unknown product id(s): ' . implode(', ', $missing)],
            ]);
        }
        return array_map(static fn (int $id): Product => $byId[$id], $ids);
    }

    /** @param list<int> $vendorIds */
    private static function isOwnLive(Product $p, array $vendorIds): bool
    {
        return in_array((int) $p->getVendor()->getId(), $vendorIds, true)
            && $p->getStatus() !== Product::STATUS_SOFT_DELETED;
    }

    /** @param list<int> $vendorIds */
    private function ownProductsResponse(int $collectionId, array $vendorIds): ResponseInterface
    {
        $ids = $this->joinRepo()->productIdsForCollectionAndVendors($collectionId, $vendorIds);

        $products = [];
        if ($ids !== []) {
            /** @var ProductRepository $repo */
            $repo = $this->em->getRepository(Product::class);
            $byId = [];
            foreach ($repo->findBy(['id' => $ids]) as $p) {
                $byId[(int) $p->getId()] = $p;
            }
            foreach ($ids as $id) {
                if (isset($byId[$id])) {
                    $products[] = $byId[$id];
                }
            }
        }

        return $this->ok([
            'data' => $this->productSerializer->vendorManageShapeMany($products),
            'meta' => ['total' => count($products)],
        ]);
    }

    /** @return list<array{id: int, name: string, slug: string, sort_order: int}> */
    private function openMemberships(int $productId): array
    {
        $out = [];
        foreach ($this->joinRepo()->membershipsForProducts([$productId]) as $row) {
            if (!$row['is_active'] || !$row['vendor_contributions_enabled']) {
                continue;
            }
            $out[] = [
                'id'         => $row['id'],
                'name'       => $row['name'],
                'slug'       => $row['slug'],
                'sort_order' => $row['sort_order'],
            ];
        }
        return $out;
    }

    private static function routeId(mixed $rawId): int
    {
        return is_int($rawId) || (is_string($rawId) && ctype_digit($rawId)) ? (int) $rawId : 0;
    }

    private function joinRepo(): CollectionProductRepository
    {
        /** @var CollectionProductRepository $repo */
        $repo = $this->em->getRepository(CollectionProduct::class);
        return $repo;
    }
}
