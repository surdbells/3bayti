<?php

declare(strict_types=1);

namespace Bayti\Api\Http\Controllers\Catalog;

use Bayti\Api\Domain\Catalog\Style;
use Bayti\Api\Domain\Catalog\StyleRepository;
use Bayti\Api\Http\Errors\HttpException;
use Bayti\Api\Http\PaginatedEnvelope;
use Bayti\Api\Http\Responder;
use Bayti\Api\Http\Serializers\StyleSerializer;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * GET /v3/styles/{slug}
 *
 * Single active style, resolved by its stable slug. Backs the mobile
 * Style-Hub deep-link / hard-reload path: the style-view page reads the
 * style from router state when navigated to from the list, but on a hard
 * reload (or a shared deep link) that state is wiped, so it re-fetches
 * the style by slug here and rebuilds it via the same response transform
 * used for the list.
 *
 * Returns: { data: Style } (detailShape, same field set as the list's
 * listShape, with the embedded products array incl. legacy_product_id so
 * a product tap navigates to the PDP by legacy id).
 *
 * 404 when the slug is unknown or the style is inactive, mirrors the
 * other by-key catalog reads (GetProductByLegacyIdController).
 */
final class GetStyleController
{
    use Responder;

    public function __construct(
        protected readonly ResponseFactoryInterface $responseFactory,
        private readonly EntityManagerInterface $em,
        private readonly StyleSerializer $serializer,
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
            throw HttpException::notFound('Style not found.');
        }

        /** @var StyleRepository $styleRepo */
        $styleRepo = $this->em->getRepository(Style::class);
        $style = $styleRepo->findActiveBySlug($slug);

        if ($style === null) {
            throw HttpException::notFound('Style not found.');
        }

        // Bulk-load this style's ordered products in one query (same path
        // as the list controllers). loadProductsForStyles returns a map
        // keyed by style id; pull this style's bucket (empty if none).
        $styleId = (int) $style->getId();
        $productsByStyleId = $styleRepo->loadProductsForStyles([$styleId]);

        return $this->ok(PaginatedEnvelope::single(
            $this->serializer->detailShape($style, $productsByStyleId[$styleId] ?? []),
        ));
    }
}
