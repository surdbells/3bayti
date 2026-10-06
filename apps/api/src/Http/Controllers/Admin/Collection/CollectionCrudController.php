<?php declare(strict_types=1);
namespace Bayti\Api\Http\Controllers\Admin\Collection;

use Bayti\Api\Domain\Catalog\CollectionProduct;
use Bayti\Api\Domain\Catalog\CollectionProductRepository;
use Bayti\Api\Domain\Catalog\Product;
use Bayti\Api\Domain\Catalog\ProductCollection;
use Bayti\Api\Domain\Catalog\ProductCollectionRepository;
use Bayti\Api\Domain\Catalog\ProductRepository;
use Bayti\Api\Http\Errors\HttpException;
use Bayti\Api\Http\Responder;
use Bayti\Api\Http\Serializers\ProductSerializer;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * All Collection CRUD in one controller (routed by method). M3.4-H.
 *
 *   GET    /v3/admin/collections            list
 *   POST   /v3/admin/collections            create
 *   GET    /v3/admin/collections/{id}       detail
 *   PUT    /v3/admin/collections/{id}       update
 *   DELETE /v3/admin/collections/{id}       hard-delete
 */
final class CollectionCrudController
{
    use Responder;

    public function __construct(
        protected readonly ResponseFactoryInterface $responseFactory,
        private readonly EntityManagerInterface $em,
        private readonly ProductSerializer $productSerializer,
    ) {}

    protected function getResponseFactory(): ResponseFactoryInterface { return $this->responseFactory; }

    public function list(ServerRequestInterface $request): ResponseInterface
    {
        $q      = $request->getQueryParams();
        $limit  = max(1, min(100, (int) ($q['limit']  ?? 20)));
        $offset = max(0, (int) ($q['offset'] ?? 0));
        /** @var ProductCollectionRepository $repo */
        $repo   = $this->em->getRepository(ProductCollection::class);
        $result = $repo->findPaginated($limit, $offset);
        return $this->ok([
            'data' => array_map([$this, 'shape'], $result['items']),
            'meta' => ['total' => $result['total'], 'limit' => $limit, 'offset' => $offset],
        ]);
    }

    public function get(ServerRequestInterface $request): ResponseInterface
    {
        $col = $this->findOrFail((int) $request->getAttribute('id'));
        return $this->ok(['data' => $this->shape($col)]);
    }

    public function create(ServerRequestInterface $request): ResponseInterface
    {
        $body = (array) ($request->getParsedBody() ?? []);
        $name = trim((string) ($body['name'] ?? $body['collection'] ?? ''));
        if ($name === '') throw HttpException::badRequest('name is required.');
        $this->assertNameLength($name);

        $slug = strtolower(trim(preg_replace('/[^a-z0-9]+/i', '-', $name) ?? '', '-'));
        $slug = ($slug !== '' ? $slug : 'collection') . '-' . substr(bin2hex(random_bytes(4)), 0, 8);

        $col = new ProductCollection($name, $slug);
        if (array_key_exists('description', $body))   $col->setDescription($this->normaliseDescription($body['description']));
        if (isset($body['cover_image_url']))           $col->setCoverImageUrl((string) $body['cover_image_url']);
        if (isset($body['is_active']))                 $col->setActive((bool) $body['is_active']);
        if (array_key_exists('display_order', $body)) $col->setDisplayOrder($this->normaliseDisplayOrder($body['display_order']));

        /** @var ProductCollectionRepository $repo */
        $repo = $this->em->getRepository(ProductCollection::class);
        $repo->save($col);
        return $this->created(['data' => $this->shape($col)]);
    }

    public function update(ServerRequestInterface $request): ResponseInterface
    {
        $col  = $this->findOrFail((int) $request->getAttribute('id'));
        $body = (array) ($request->getParsedBody() ?? []);

        if (isset($body['name']) && trim((string) $body['name']) !== '') {
            $name = trim((string) $body['name']);
            $this->assertNameLength($name);
            $col->setName($name);
        }
        if (array_key_exists('description', $body))         $col->setDescription($this->normaliseDescription($body['description']));
        if (array_key_exists('cover_image_url', $body))     $col->setCoverImageUrl($body['cover_image_url'] !== '' ? (string) $body['cover_image_url'] : null);
        if (array_key_exists('is_active', $body))           $col->setActive((bool) $body['is_active']);
        if (array_key_exists('display_order', $body))       $col->setDisplayOrder($this->normaliseDisplayOrder($body['display_order']));

        /** @var ProductCollectionRepository $repo */
        $repo = $this->em->getRepository(ProductCollection::class);
        $repo->save($col);
        return $this->ok(['data' => $this->shape($col)]);
    }

    public function delete(ServerRequestInterface $request): ResponseInterface
    {
        $col  = $this->findOrFail((int) $request->getAttribute('id'));
        /** @var ProductCollectionRepository $repo */
        $repo = $this->em->getRepository(ProductCollection::class);
        $repo->delete($col);
        return $this->noContent();
    }

    /**
     * GET /v3/admin/collections/{id}/products
     *
     * The products curated into this collection, in curation order. Returns
     * admin product cards so the portal picker can render current members.
     */
    public function listProducts(ServerRequestInterface $request): ResponseInterface
    {
        $col = $this->findOrFail((int) $request->getAttribute('id'));

        /** @var CollectionProductRepository $joinRepo */
        $joinRepo = $this->em->getRepository(CollectionProduct::class);
        $ids = $joinRepo->productIdsForCollection((int) $col->getId());

        $products = $this->loadProductsInOrder($ids);

        return $this->ok([
            'data' => $this->productSerializer->configureFromRequest($request)->listShapeMany($products),
            'meta' => ['total' => count($products)],
        ]);
    }

    /**
     * PUT /v3/admin/collections/{id}/products
     *
     * Replace the collection's curated product set. Body:
     *   { "product_ids": [12, 7, 44] }
     * Array ORDER is the curation order (index 0 = the "first product", whose
     * image fronts the collection on storefront cards). An empty array clears
     * the collection. Unknown product ids → 422.
     */
    public function setProducts(ServerRequestInterface $request): ResponseInterface
    {
        $col = $this->findOrFail((int) $request->getAttribute('id'));
        $body = (array) ($request->getParsedBody() ?? []);
        $raw = $body['product_ids'] ?? null;
        if (!is_array($raw)) {
            throw HttpException::badRequest('product_ids must be an array.');
        }

        // Normalise to a de-duplicated, order-preserving list of positive ints.
        $ids = [];
        foreach ($raw as $v) {
            if ((is_int($v) || (is_string($v) && ctype_digit($v))) && (int) $v > 0) {
                $id = (int) $v;
                if (!in_array($id, $ids, true)) {
                    $ids[] = $id;
                }
            } else {
                throw HttpException::validation(['product_ids' => ['Each product id must be a positive integer.']]);
            }
        }

        // Every id must resolve to a real product (the FK would reject
        // otherwise, but we want a clean 422 listing the offenders).
        $products = $this->loadProductsInOrder($ids);
        if (count($products) !== count($ids)) {
            $found = array_map(static fn (Product $p): int => (int) $p->getId(), $products);
            $missing = array_values(array_diff($ids, $found));
            throw HttpException::validation([
                'product_ids' => ['Unknown product id(s): ' . implode(', ', $missing)],
            ]);
        }

        /** @var CollectionProductRepository $joinRepo */
        $joinRepo = $this->em->getRepository(CollectionProduct::class);
        // Full re-set: clear, then re-insert in the supplied order.
        $joinRepo->deleteForCollection((int) $col->getId());
        foreach ($products as $i => $product) {
            $this->em->persist(new CollectionProduct($col, $product, $i));
        }
        $this->em->flush();

        return $this->ok([
            'data' => $this->productSerializer->configureFromRequest($request)->listShapeMany($products),
            'meta' => ['total' => count($products)],
        ]);
    }

    // ── helpers ─────────────────────────────────────────────────────

    /** name is VARCHAR(200); reject longer input as 422 instead of a DB 500. */
    private function assertNameLength(string $name): void
    {
        if (mb_strlen($name) > 200) {
            throw HttpException::validation(['name' => ['Name must be 200 characters or fewer.']]);
        }
    }

    /** Blank/null description is stored as NULL (not ''), so the storefront gets null. */
    private function normaliseDescription(mixed $raw): ?string
    {
        if ($raw === null) {
            return null;
        }
        $d = trim((string) $raw);
        return $d === '' ? null : $d;
    }

    /** display_order is SMALLINT; accept null/'' (unset) or a whole number 0..32767. */
    private function normaliseDisplayOrder(mixed $raw): ?int
    {
        if ($raw === null || $raw === '') {
            return null;
        }
        if ((is_int($raw) || (is_string($raw) && ctype_digit($raw))) && (int) $raw >= 0 && (int) $raw <= 32767) {
            return (int) $raw;
        }
        throw HttpException::validation(['display_order' => ['Display order must be a whole number from 0 to 32767.']]);
    }

    /**
     * Load products for the given ids, preserving the id-list order (findBy
     * returns DB order, which would lose the admin's curation sequence).
     *
     * @param list<int> $ids
     * @return list<Product>
     */
    private function loadProductsInOrder(array $ids): array
    {
        if ($ids === []) {
            return [];
        }
        /** @var ProductRepository $productRepo */
        $productRepo = $this->em->getRepository(Product::class);
        /** @var list<Product> $found */
        $found = $productRepo->findBy(['id' => $ids]);

        $byId = [];
        foreach ($found as $p) {
            $byId[(int) $p->getId()] = $p;
        }
        $ordered = [];
        foreach ($ids as $id) {
            if (isset($byId[$id])) {
                $ordered[] = $byId[$id];
            }
        }
        return $ordered;
    }

    private function findOrFail(int $id): ProductCollection
    {
        /** @var ProductCollectionRepository $repo */
        $repo = $this->em->getRepository(ProductCollection::class);
        $col  = $repo->find($id);
        if ($col === null) throw HttpException::notFound('Collection not found.');
        return $col;
    }

    /** @return array<string,mixed> */
    public function shape(ProductCollection $c): array
    {
        return [
            'id'              => $c->getId(),
            'collection'      => $c->getName(),
            'name'            => $c->getName(),
            'slug'            => $c->getSlug(),
            'description'     => $c->getDescription(),
            'cover_image_url' => $c->getCoverImageUrl(),
            'is_active'       => $c->isActive(),
            'display_order'   => $c->getDisplayOrder(),
            'created_at'      => $c->getCreatedAt()->format(\DateTimeInterface::ATOM),
        ];
    }
}
