<?php

declare(strict_types=1);

namespace Bayti\Api\Http\Controllers\Catalog;

use Bayti\Api\Domain\Catalog\Product;
use Bayti\Api\Domain\Catalog\ProductRepository;
use Bayti\Api\Domain\Catalog\RecommendationsService;
use Bayti\Api\Http\Errors\HttpException;
use Bayti\Api\Http\Responder;
use Bayti\Api\Http\Serializers\RecommendationsSerializer;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * GET /v3/products/{slug}/recommendations?limit=10
 *
 * Public per-product recommendations endpoint (M3.2.X.12-G).
 * Returns up to N recommended products for the supplied product
 * slug, drawn from the denormalized product_recommendations table
 * (populated by the X.12-E cron) with popular-fallback when
 * pre-computed rows are missing.
 *
 * Authentication: not required, recommendations are public.
 * Q-VendorScope = A: marketplace-wide recommendations cross vendors.
 *
 * No audit emission, public read endpoint without sensitive data.
 *
 * Limit clamping per Q-OutputSize = B: clamped to [3, 20] in the
 * RecommendationsService rather than 400'ing on out-of-range input.
 */
final class GetProductRecommendationsController
{
    use Responder;

    public function __construct(
        protected readonly ResponseFactoryInterface $responseFactory,
        private readonly EntityManagerInterface $em,
        private readonly RecommendationsService $recommendations,
        private readonly RecommendationsSerializer $serializer,
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
        ResponseInterface $_response,
        array $args,
    ): ResponseInterface {
        $slug = $args['slug'] ?? '';
        if ($slug === '') {
            throw HttpException::notFound('Product not found.');
        }

        /** @var ProductRepository $productRepo */
        $productRepo = $this->em->getRepository(Product::class);
        $product = $productRepo->findBySlug($slug);
        if ($product === null) {
            throw HttpException::notFound('Product not found.');
        }
        $productId = $product->getId();
        if ($productId === null) {
            // Defensive: a persisted Product should always have an id;
            // a null id here would mean Doctrine returned an unfinished
            // entity. Treat as not-found.
            throw HttpException::notFound('Product not found.');
        }

        /** @var array<string, mixed> $query */
        $query = $request->getQueryParams();
        $limit = $this->parseLimit($query['limit'] ?? null);

        $recs = $this->recommendations->getRecommendationsForProduct($productId, $limit);

        return $this->ok($this->serializer->shape($recs, $limit));
    }

    private function parseLimit(mixed $raw): int
    {
        if (!is_string($raw) && !is_int($raw)) {
            return RecommendationsService::DEFAULT_LIMIT;
        }
        $rawStr = (string) $raw;
        if (!is_numeric($rawStr)) {
            return RecommendationsService::DEFAULT_LIMIT;
        }
        return (int) $rawStr;
    }
}
