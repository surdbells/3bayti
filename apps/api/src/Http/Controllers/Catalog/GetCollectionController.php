<?php

declare(strict_types=1);

namespace Bayti\Api\Http\Controllers\Catalog;

use Bayti\Api\Domain\Catalog\Product;
use Bayti\Api\Domain\Catalog\ProductCollection;
use Bayti\Api\Domain\Catalog\ProductCollectionRepository;
use Bayti\Api\Domain\Catalog\ProductRepository;
use Bayti\Api\Http\Errors\HttpException;
use Bayti\Api\Http\Responder;
use Bayti\Api\Http\Serializers\CollectionSerializer;
use Bayti\Api\Http\Serializers\ProductSerializer;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * GET /v3/collections/{slug}
 *
 * Collection detail + first page of curated products, mirroring
 * GetCategoryController's contract so the web/mobile PLPs can reuse the same
 * rendering. Returns:
 *   - data: collection header (name/slug/description/image_url/product_count)
 *           + embedded `products` (first page, newest first)
 *   - meta: { total_products, page_size }
 *
 * 404 on empty / unknown / inactive slug. A valid-but-empty collection returns
 * 200 with products: [] (empty isn't an error).
 */
final class GetCollectionController
{
    use Responder;

    private const PAGE_SIZE = 20;

    public function __construct(
        protected readonly ResponseFactoryInterface $responseFactory,
        private readonly EntityManagerInterface $em,
        private readonly CollectionSerializer $serializer,
        private readonly ProductSerializer $productSerializer,
    ) {
    }

    protected function getResponseFactory(): ResponseFactoryInterface
    {
        return $this->responseFactory;
    }

    /**
     * @param array<string, string> $args
     */
    public function __invoke(
        ServerRequestInterface $request,
        ResponseInterface $response,
        array $args,
    ): ResponseInterface {
        $slug = (string) ($args['slug'] ?? '');
        if ($slug === '') {
            throw HttpException::notFound('Collection not found.');
        }

        /** @var ProductCollectionRepository $repo */
        $repo = $this->em->getRepository(ProductCollection::class);
        $collection = $repo->findBySlug($slug, activeOnly: true);
        if ($collection === null) {
            throw HttpException::notFound('Collection not found.');
        }
        $id = (int) $collection->getId();

        /** @var ProductRepository $productRepo */
        $productRepo = $this->em->getRepository(Product::class);
        $productsResult = $productRepo->findActivePaginated([
            'collectionId' => $id,
            'sort' => 'newest',
            'limit' => self::PAGE_SIZE,
            'offset' => 0,
        ]);

        $data = $this->serializer->detailShape(
            $collection,
            $repo->representativeImageUrl($id),
            $repo->countActiveProducts($id),
        );
        $data['products'] = $this->productSerializer
            ->configureFromRequest($request)
            ->listShapeMany($productsResult['items']);

        return $this->ok([
            'data' => $data,
            'meta' => [
                'total_products' => $productsResult['total'],
                'page_size' => self::PAGE_SIZE,
            ],
        ]);
    }
}
