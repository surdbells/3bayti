<?php

declare(strict_types=1);

namespace Bayti\Api\Http\Controllers\Wishlist;

use Bayti\Api\Domain\User\User;
use Bayti\Api\Domain\Wishlist\Wishlist;
use Bayti\Api\Domain\Wishlist\WishlistLabel;
use Bayti\Api\Domain\Wishlist\WishlistLabelRepository;
use Bayti\Api\Domain\Wishlist\WishlistRepository;
use Bayti\Api\Http\Errors\ErrorCodes;
use Bayti\Api\Http\Errors\HttpException;
use Bayti\Api\Http\Middleware\AuthMiddleware;
use Bayti\Api\Http\PaginatedEnvelope;
use Bayti\Api\Http\Responder;
use Bayti\Api\Http\Serializers\WishlistLabelSerializer;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * GET /v3/me/wishlist/labels — the user's wishlist labels.
 *
 * Each label carries its saved-product count (computed in one grouped
 * query). Returned in a { data: [...] } envelope.
 */
final class ListWishlistLabelsController
{
    use Responder;

    public function __construct(
        protected readonly ResponseFactoryInterface $responseFactory,
        private readonly EntityManagerInterface $em,
        private readonly WishlistLabelSerializer $serializer,
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

        /** @var WishlistLabelRepository $labelRepo */
        $labelRepo = $this->em->getRepository(WishlistLabel::class);
        /** @var WishlistRepository $wishlistRepo */
        $wishlistRepo = $this->em->getRepository(Wishlist::class);

        $labels = $labelRepo->findForUser($user);
        $counts = $wishlistRepo->countsByLabelForUser($user);

        return $this->ok(PaginatedEnvelope::single(
            $this->serializer->publicShapeMany($labels, $counts),
        ));
    }
}
