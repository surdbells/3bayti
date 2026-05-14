<?php

declare(strict_types=1);

namespace Bayti\Api\Http\Controllers\Catalog;

use Bayti\Api\Domain\Catalog\Style;
use Bayti\Api\Domain\Catalog\StyleRepository;
use Bayti\Api\Http\PaginatedEnvelope;
use Bayti\Api\Http\Responder;
use Bayti\Api\Http\Serializers\StyleSerializer;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * GET /v3/styles
 *
 * Paginated listing of active styles. Supports type filtering:
 *
 *   ?type=community  (default) — user/community-generated styles
 *   ?type=editorial            — admin/editorial-curated styles
 *   ?limit=10&offset=0
 *
 * Returns: { data: Style[], meta: PaginationMeta }
 *
 * Each Style includes an embedded `products` array bulk-loaded via
 * StyleRepository::loadProductsForStyles to avoid the N+1 query that
 * would otherwise hit when serializing many styles' product strips.
 *
 * Pagination caps:
 *   - limit: 1-50 (defaults to 10, matching mobile's body)
 *   - offset: >= 0
 *
 * Read-only — admin/curators manage styles outside the v3 API (or via
 * a future admin endpoint). No user-facing create/edit/delete in
 * M3.1.5.5 per the locked decision (Q4).
 *
 * Unknown ?type values fall back to 'community' (rather than 400)
 * for resilience — mobile sends a static type string and a typo
 * shouldn't break the page.
 */
final class ListStylesController
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

    public function __invoke(ServerRequestInterface $request): ResponseInterface
    {
        $query = $request->getQueryParams();

        $limit = max(1, min(50, (int) ($query['limit'] ?? 10)));
        $offset = max(0, (int) ($query['offset'] ?? 0));

        $styleType = $this->parseStyleType((string) ($query['type'] ?? 'community'));

        /** @var StyleRepository $styleRepo */
        $styleRepo = $this->em->getRepository(Style::class);
        $result = $styleRepo->findActivePaginatedByType($styleType, $limit, $offset);

        // Bulk-load embedded products for the entire page in one
        // query (vs N+1 if the serializer fetched per-style).
        $styleIds = array_map(static fn (Style $s) => (int) $s->getId(), $result['items']);
        $productsByStyleId = $styleRepo->loadProductsForStyles($styleIds);

        return $this->ok(PaginatedEnvelope::build(
            $this->serializer->listShapeMany($result['items'], $productsByStyleId),
            $result['total'],
            $limit,
            $offset,
        ));
    }

    /**
     * Map the request's string type ('community' / 'editorial') to
     * the smallint enum stored in the DB. Unknown values fall back
     * to community for resilience (see class docblock).
     */
    private function parseStyleType(string $type): int
    {
        return match (strtolower(trim($type))) {
            'editorial' => Style::TYPE_EDITORIAL,
            'community' => Style::TYPE_COMMUNITY,
            default => Style::TYPE_COMMUNITY,
        };
    }
}
