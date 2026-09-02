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
 * GET /v3/products/{slug}
 *
 * Returns: { data: ProductDetail }
 *
 * Includes approved reviews (up to 10 most recent) under recent_reviews.
 * Inactive / draft / soft-deleted products return 404, they don't exist
 * to public consumers.
 *
 * Reviews are fetched in the same query path (separate query, joined
 * by product_id), small N (27 in production total) so no risk of
 * N+1 or unbounded fetch.
 */
final class GetProductController
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
        $slug = (string) ($args['slug'] ?? '');
        if ($slug === '') {
            throw HttpException::notFound('Product not found.');
        }

        /** @var ProductRepository $productRepo */
        $productRepo = $this->em->getRepository(Product::class);
        $product = $productRepo->findBySlug($slug);

        // isOrderable() = product active AND vendor may sell (approved+active):
        // an unapproved/suspended store's product must be unreachable by direct
        // URL too, not just hidden from listings.
        if ($product === null || !$product->isOrderable()) {
            throw HttpException::notFound('Product not found.');
        }

        // Fetch approved reviews (up to 10 most recent)
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
