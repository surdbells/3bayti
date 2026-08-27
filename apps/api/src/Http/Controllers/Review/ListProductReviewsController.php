<?php

declare(strict_types=1);

namespace Bayti\Api\Http\Controllers\Review;

use Bayti\Api\Domain\Catalog\Product;
use Bayti\Api\Domain\Catalog\ProductReview;
use Bayti\Api\Domain\Catalog\ProductReviewRepository;
use Bayti\Api\Http\Errors\HttpException;
use Bayti\Api\Http\Responder;
use Bayti\Api\Http\Serializers\ReviewSerializer;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * GET /v3/products/{productId}/reviews[?limit=&offset=], public
 * (approved) reviews for a product, newest first. No auth; only
 * approved reviews are exposed (pending/rejected/spam stay hidden).
 */
final class ListProductReviewsController
{
    use Responder;

    public function __construct(
        protected readonly ResponseFactoryInterface $responseFactory,
        private readonly EntityManagerInterface $em,
        private readonly ReviewSerializer $serializer,
    ) {
    }

    protected function getResponseFactory(): ResponseFactoryInterface
    {
        return $this->responseFactory;
    }

    /**
     * @param array<string, string> $args
     */
    public function __invoke(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $productId = (int) ($args['productId'] ?? 0);
        $product   = $productId > 0 ? $this->em->find(Product::class, $productId) : null;
        if (!$product instanceof Product) {
            throw HttpException::notFound('Product not found.');
        }

        $q      = $request->getQueryParams();
        $limit  = max(1, min(100, (int) ($q['limit'] ?? 20)));
        $offset = max(0, (int) ($q['offset'] ?? 0));

        /** @var ProductReviewRepository $repo */
        $repo   = $this->em->getRepository(ProductReview::class);
        $result = $repo->findApprovedForProductPaginated($product, $limit, $offset);

        return $this->ok([
            'data' => array_map([$this->serializer, 'publicShape'], $result['items']),
            'meta' => ['total' => $result['total'], 'limit' => $limit, 'offset' => $offset],
        ]);
    }
}
