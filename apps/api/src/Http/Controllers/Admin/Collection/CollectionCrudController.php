<?php declare(strict_types=1);
namespace Bayti\Api\Http\Controllers\Admin\Collection;

use Bayti\Api\Domain\Catalog\ProductCollection;
use Bayti\Api\Domain\Catalog\ProductCollectionRepository;
use Bayti\Api\Http\Errors\HttpException;
use Bayti\Api\Http\Responder;
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

        $slug = strtolower(trim(preg_replace('/[^a-z0-9]+/i', '-', $name) ?? '', '-'));
        $slug = ($slug !== '' ? $slug : 'collection') . '-' . substr(bin2hex(random_bytes(4)), 0, 8);

        $col = new ProductCollection($name, $slug);
        if (isset($body['description']))     $col->setDescription((string) $body['description']);
        if (isset($body['cover_image_url'])) $col->setCoverImageUrl((string) $body['cover_image_url']);
        if (isset($body['is_active']))       $col->setActive((bool) $body['is_active']);
        if (isset($body['display_order']))   $col->setDisplayOrder((int) $body['display_order']);

        /** @var ProductCollectionRepository $repo */
        $repo = $this->em->getRepository(ProductCollection::class);
        $repo->save($col);
        return $this->created(['data' => $this->shape($col)]);
    }

    public function update(ServerRequestInterface $request): ResponseInterface
    {
        $col  = $this->findOrFail((int) $request->getAttribute('id'));
        $body = (array) ($request->getParsedBody() ?? []);

        if (isset($body['name']) && $body['name'] !== '')   $col->setName((string) $body['name']);
        if (array_key_exists('description', $body))         $col->setDescription($body['description'] !== '' ? (string) $body['description'] : null);
        if (array_key_exists('cover_image_url', $body))     $col->setCoverImageUrl($body['cover_image_url'] !== '' ? (string) $body['cover_image_url'] : null);
        if (array_key_exists('is_active', $body))           $col->setActive((bool) $body['is_active']);
        if (array_key_exists('display_order', $body))       $col->setDisplayOrder($body['display_order'] !== null ? (int) $body['display_order'] : null);

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

    // ── helpers ─────────────────────────────────────────────────────

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
