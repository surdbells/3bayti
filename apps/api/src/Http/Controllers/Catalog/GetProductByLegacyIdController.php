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
 * GET /v3/products/by-legacy-id/{id}
 *
 * Mobile-compatibility shim during the M3.1.5 strangler-fig flip.
 *
 * Why this exists
 * ===============
 * The slug-based GetProductController (GET /v3/products/{slug}) is the
 * canonical product detail endpoint. New consumers should use it.
 *
 * The mobile app (M3.1.5-era) was built against the legacy backend,
 * which identified products by integer ids — the original primary
 * key from the legacy WordPress/CodeIgniter schema. The migration
 * preserved those ids in the `legacy_product_id` column.
 *
 * Rather than force mobile to cache a slug map locally (fragile —
 * stale on category renames, requires download-then-resolve flow),
 * we accept the legacy id at this dedicated endpoint and resolve it
 * server-side. The response shape is IDENTICAL to GetProductController.
 *
 * This endpoint will be retired when mobile is rebuilt against slug
 * semantics in a future phase (M3.1.10+).
 *
 * Behaviour: same as GetProductController — 404 for unknown id,
 * inactive, draft, or soft-deleted products. Returns detailShape with
 * up to 10 recent approved reviews.
 *
 * The path segment `by-legacy-id` is deliberately distinctive so it
 * can't ever collide with a real product slug. The path also has 3
 * segments after `/v3/`, which Slim won't confuse with the 2-segment
 * `/v3/products/{slug}` route regardless of ordering.
 */
final class GetProductByLegacyIdController
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
            // Slim's route pattern doesn't constrain to digits, so we
            // validate here. Non-numeric ids are treated as "not found"
            // rather than 400 — they could be slug-like strings someone
            // accidentally routed through this endpoint, and a 404
            // response is the most useful default.
            throw HttpException::notFound('Product not found.');
        }
        $legacyId = (int) $rawId;

        /** @var ProductRepository $productRepo */
        $productRepo = $this->em->getRepository(Product::class);
        $product = $productRepo->findByLegacyId($legacyId);

        if ($product === null || !$product->isActive()) {
            throw HttpException::notFound('Product not found.');
        }

        // Same review-fetch as GetProductController. The two could share
        // a private helper or trait, but at 10 lines of code duplication
        // the indirection isn't worth the lookup cost when reading the
        // controllers.
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
