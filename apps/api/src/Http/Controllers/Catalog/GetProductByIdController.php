<?php

declare(strict_types=1);

namespace Bayti\Api\Http\Controllers\Catalog;

use Bayti\Api\Domain\Catalog\Product;
use Bayti\Api\Domain\Catalog\ProductRepository;
use Bayti\Api\Domain\Catalog\ProductReview;
use Bayti\Api\Http\Errors\HttpException;
use Bayti\Api\Http\PaginatedEnvelope;
use Bayti\Api\Http\Responder;
use Bayti\Api\Http\Serializers\ProductSerializer;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * GET /v3/products/by-id/{id}
 *
 * Public product detail keyed by the v3 primary key. The storefront now
 * navigates entirely by v3 id (legacy ids are no longer referenced), so
 * this is the canonical numeric detail endpoint, it resolves BOTH
 * legacy-migrated products and v3-native products (which have no
 * legacy_product_id and so 404'd on the by-legacy-id shim). The response
 * shape is identical to {@see GetProductController} / by-legacy-id.
 *
 * Behaviour mirrors those endpoints: 404 for an unknown id, or an
 * inactive / draft / soft-deleted product. Returns detailShape with up to
 * 10 recent approved reviews.
 *
 * The `by-id` path segment keeps this 3-segment route from ever colliding
 * with the 2-segment `/v3/products/{slug}` route.
 */
final class GetProductByIdController
{
    use Responder;

    public function __construct(
        protected readonly ResponseFactoryInterface $responseFactory,
        private readonly EntityManagerInterface $em,
        private readonly ProductSerializer $serializer,
    ) {
    }

    protected function getResponseFactory(): ResponseFactoryInterface
    {
        return $this->responseFactory;
    }

    public function __invoke(
        ServerRequestInterface $request,
        ResponseInterface $response,
        array $args,
    ): ResponseInterface {
        $rawId = (string) ($args['id'] ?? '');
        if ($rawId === '' || !ctype_digit($rawId)) {
            throw HttpException::notFound('Product not found.');
        }
        $id = (int) $rawId;

        /** @var ProductRepository $productRepo */
        $productRepo = $this->em->getRepository(Product::class);
        $product = $productRepo->find($id);

        if ($product === null || !$product->isActive()) {
            throw HttpException::notFound('Product not found.');
        }

        $reviews = $this->em->getRepository(ProductReview::class)
            ->createQueryBuilder('r')
            ->where('r.product = :product')
            ->andWhere('r.status = :approved')
            ->setParameter('product', $product)
            ->setParameter('approved', ProductReview::STATUS_APPROVED)
            ->orderBy('r.createdAt', 'DESC')
            ->setMaxResults(10)
            ->getQuery()
            ->getResult();

        return $this->ok(PaginatedEnvelope::single(
            $this->serializer->configureFromRequest($request)->detailShape($product, $reviews),
        ));
    }
}
