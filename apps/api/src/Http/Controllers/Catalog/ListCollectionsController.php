<?php

declare(strict_types=1);

namespace Bayti\Api\Http\Controllers\Catalog;

use Bayti\Api\Domain\Catalog\ProductCollection;
use Bayti\Api\Domain\Catalog\ProductCollectionRepository;
use Bayti\Api\Http\Responder;
use Bayti\Api\Http\Serializers\CollectionSerializer;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * GET /v3/collections
 *
 * Public "shop by collection" list: every ACTIVE admin-curated collection in
 * display order, each with a representative image (first curated product's
 * image) + a live product count. Powers the web home "Shop by collection"
 * section and the mobile collection chips.
 *
 * Collections with zero storefront-visible products are still returned (the
 * admin can curate them ahead of launch); the client decides whether to hide
 * empties. Public, unauthenticated, no pagination (collection counts are
 * small).
 */
final class ListCollectionsController
{
    use Responder;

    public function __construct(
        protected readonly ResponseFactoryInterface $responseFactory,
        private readonly EntityManagerInterface $em,
        private readonly CollectionSerializer $serializer,
    ) {
    }

    protected function getResponseFactory(): ResponseFactoryInterface
    {
        return $this->responseFactory;
    }

    public function __invoke(ServerRequestInterface $request): ResponseInterface
    {
        /** @var ProductCollectionRepository $repo */
        $repo = $this->em->getRepository(ProductCollection::class);
        $collections = $repo->findActiveForStorefront();

        $data = [];
        foreach ($collections as $c) {
            $id = (int) $c->getId();
            $data[] = $this->serializer->listShape(
                $c,
                $repo->representativeImageUrl($id),
                $repo->countActiveProducts($id),
            );
        }

        return $this->ok([
            'data' => $data,
            'meta' => ['total' => count($data)],
        ]);
    }
}
