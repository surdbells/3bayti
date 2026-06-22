<?php

declare(strict_types=1);

namespace Bayti\Api\Http\Controllers\Style;

use Bayti\Api\Domain\Catalog\Style;
use Bayti\Api\Domain\Catalog\StyleRepository;
use Bayti\Api\Domain\User\User;
use Bayti\Api\Http\Errors\ErrorCodes;
use Bayti\Api\Http\Errors\HttpException;
use Bayti\Api\Http\Middleware\AuthMiddleware;
use Bayti\Api\Http\PaginatedEnvelope;
use Bayti\Api\Http\Responder;
use Bayti\Api\Http\Serializers\StyleSerializer;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * GET /v3/me/styles — the authenticated customer's own submitted styles.
 *
 * Backs the "My Styles" tab of the mobile Style Hub. Unlike GET /v3/styles
 * (anonymous, type-filtered community/editorial), this endpoint is owner-
 * scoped: it returns only the styles the authenticated user created
 * (created_by_user), regardless of style_type, and takes NO ?type param.
 *
 * Mirrors ListStylesController's product-embedding + pagination shape so
 * the mobile gets the SAME { data: Style[], meta } envelope and the same
 * registered response transform (transformStylesListResponse) can reshape
 * it to the legacy Styles[] form. The only differences are the auth guard
 * (CreateStyleController pattern) and the owner-scoped repository query.
 *
 * Pagination caps mirror ListStylesController:
 *   - limit: 1-50 (defaults to 10, matching mobile's body)
 *   - offset: >= 0
 */
final class ListMyStylesController
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
        $user = $request->getAttribute(AuthMiddleware::ATTR_USER);
        if (!$user instanceof User) {
            throw HttpException::unauthorized(ErrorCodes::AUTH_INVALID_TOKEN, 'Authentication required.');
        }

        $query = $request->getQueryParams();

        $limit = max(1, min(50, (int) ($query['limit'] ?? 10)));
        $offset = max(0, (int) ($query['offset'] ?? 0));

        /** @var StyleRepository $styleRepo */
        $styleRepo = $this->em->getRepository(Style::class);
        $result = $styleRepo->findByOwnerPaginated($user, $limit, $offset);

        // Bulk-load embedded products for the entire page in one query
        // (vs N+1 if the serializer fetched per-style) — same as
        // ListStylesController.
        $styleIds = array_map(static fn (Style $s) => (int) $s->getId(), $result['items']);
        $productsByStyleId = $styleRepo->loadProductsForStyles($styleIds);

        return $this->ok(PaginatedEnvelope::build(
            $this->serializer->listShapeMany($result['items'], $productsByStyleId),
            $result['total'],
            $limit,
            $offset,
        ));
    }
}
