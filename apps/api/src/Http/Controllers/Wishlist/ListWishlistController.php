<?php

declare(strict_types=1);

namespace Bayti\Api\Http\Controllers\Wishlist;

use Bayti\Api\Domain\User\User;
use Bayti\Api\Domain\Wishlist\Wishlist;
use Bayti\Api\Domain\Wishlist\WishlistRepository;
use Bayti\Api\Http\Errors\ErrorCodes;
use Bayti\Api\Http\Errors\HttpException;
use Bayti\Api\Http\Middleware\AuthMiddleware;
use Bayti\Api\Http\PaginatedEnvelope;
use Bayti\Api\Http\Responder;
use Bayti\Api\Http\Serializers\ProductSerializer;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * GET /v3/me/wishlist?limit=&offset= — the user's saved products.
 *
 * Returns the saved PRODUCTS (Q6.4) via the existing ProductSerializer
 * list shape in a PaginatedEnvelope, newest-saved first. So the client
 * renders a wishlist with the exact same product-card shape as the
 * catalogue — no bespoke shape to learn.
 */
final class ListWishlistController
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

    public function __invoke(ServerRequestInterface $request): ResponseInterface
    {
        $user = $request->getAttribute(AuthMiddleware::ATTR_USER);
        if (!$user instanceof User) {
            throw HttpException::unauthorized(
                ErrorCodes::AUTH_INVALID_TOKEN,
                'Authentication required.',
            );
        }

        $query = $request->getQueryParams();
        $limit = max(1, min(100, (int) ($query['limit'] ?? 24)));
        $offset = max(0, (int) ($query['offset'] ?? 0));

        // Optional label filter (Q-Z3=B):
        //   absent            → all saved products
        //   label_id=0|none   → uncategorized only (label IS NULL)
        //   label_id=<int>    → that label only
        $labelFilter = false;
        if (array_key_exists('label_id', $query)) {
            $raw = $query['label_id'];
            if ($raw === 'none' || $raw === '0' || $raw === '') {
                $labelFilter = null;
            } else {
                $labelFilter = max(0, (int) $raw) ?: null;
            }
        }

        /** @var WishlistRepository $wishlistRepo */
        $wishlistRepo = $this->em->getRepository(Wishlist::class);
        $entries = $wishlistRepo->findForUserPaginated($user, $limit, $offset, $labelFilter);
        $total = $wishlistRepo->countForUser($user, $labelFilter);

        $products = array_map(
            static fn (Wishlist $entry) => $entry->getProduct(),
            $entries,
        );

        return $this->ok(PaginatedEnvelope::build(
            $this->serializer->configureFromRequest($request)->listShapeMany($products),
            $total,
            $limit,
            $offset,
        ));
    }
}
