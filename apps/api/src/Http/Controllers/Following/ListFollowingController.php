<?php

declare(strict_types=1);

namespace Bayti\Api\Http\Controllers\Following;

use Bayti\Api\Domain\Following\VendorFollow;
use Bayti\Api\Domain\Following\VendorFollowRepository;
use Bayti\Api\Domain\User\User;
use Bayti\Api\Http\Errors\ErrorCodes;
use Bayti\Api\Http\Errors\HttpException;
use Bayti\Api\Http\Middleware\AuthMiddleware;
use Bayti\Api\Http\PaginatedEnvelope;
use Bayti\Api\Http\Responder;
use Bayti\Api\Http\Serializers\VendorSerializer;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * GET /v3/me/following, the stores the authenticated user follows, newest
 * first, paginated. Backs the "Following" list on web + mobile.
 *
 * Each store is the vendor publicShape with is_following=true (every entry
 * in this list is followed by definition), so the client can render the
 * Following button state without a second lookup.
 *
 * Pagination mirrors the other /me list endpoints:
 *   - limit: 1-50 (default 20)
 *   - offset: >= 0
 */
final class ListFollowingController
{
    use Responder;

    public function __construct(
        protected readonly ResponseFactoryInterface $responseFactory,
        private readonly EntityManagerInterface $em,
        private readonly VendorSerializer $serializer,
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
        $limit = max(1, min(50, (int) ($query['limit'] ?? 20)));
        $offset = max(0, (int) ($query['offset'] ?? 0));

        /** @var VendorFollowRepository $repo */
        $repo = $this->em->getRepository(VendorFollow::class);
        $rows = $repo->findForUserPaginated($user, $limit, $offset);
        $total = $repo->countForUser($user);

        $items = array_map(
            fn (VendorFollow $f): array => $this->serializer->publicShape($f->getVendor(), true),
            $rows,
        );

        return $this->ok(PaginatedEnvelope::build($items, $total, $limit, $offset));
    }
}
