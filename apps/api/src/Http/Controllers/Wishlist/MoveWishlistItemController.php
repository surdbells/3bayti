<?php

declare(strict_types=1);

namespace Bayti\Api\Http\Controllers\Wishlist;

use Bayti\Api\Domain\Catalog\Product;
use Bayti\Api\Domain\User\User;
use Bayti\Api\Domain\Wishlist\Wishlist;
use Bayti\Api\Domain\Wishlist\WishlistLabel;
use Bayti\Api\Domain\Wishlist\WishlistLabelRepository;
use Bayti\Api\Domain\Wishlist\WishlistRepository;
use Bayti\Api\Http\Errors\ErrorCodes;
use Bayti\Api\Http\Errors\HttpException;
use Bayti\Api\Http\Middleware\AuthMiddleware;
use Bayti\Api\Http\Responder;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * PATCH /v3/me/wishlist/{productId}  body { label_id }
 * — move a saved product to a label (or to uncategorized).
 *
 * label_id > 0  → file under that label (must belong to the user, 404
 *                 otherwise).
 * label_id null/0/absent → uncategorize (clear the label).
 *
 * 404 if the product isn't currently on the user's wishlist.
 */
final class MoveWishlistItemController
{
    use Responder;

    public function __construct(
        protected readonly ResponseFactoryInterface $responseFactory,
        private readonly EntityManagerInterface $em,
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
        $user = $request->getAttribute(AuthMiddleware::ATTR_USER);
        if (!$user instanceof User) {
            throw HttpException::unauthorized(ErrorCodes::AUTH_INVALID_TOKEN, 'Authentication required.');
        }

        $productId = (int) ($args['productId'] ?? 0);

        $body = (array) ($request->getParsedBody() ?? []);
        $rawLabel = $body['label_id'] ?? null;
        $labelId = ($rawLabel === null || $rawLabel === '' || (int) $rawLabel <= 0)
            ? null
            : (int) $rawLabel;

        $product = $productId > 0 ? $this->em->find(Product::class, $productId) : null;
        if (!$product instanceof Product) {
            throw HttpException::notFound('Wishlist item not found.');
        }

        /** @var WishlistRepository $wishlistRepo */
        $wishlistRepo = $this->em->getRepository(Wishlist::class);
        $entry = $wishlistRepo->findOneForUserAndProduct($user, $product);
        if ($entry === null) {
            throw HttpException::notFound('Wishlist item not found.');
        }

        $label = null;
        if ($labelId !== null) {
            /** @var WishlistLabelRepository $labelRepo */
            $labelRepo = $this->em->getRepository(WishlistLabel::class);
            $label = $labelRepo->findOneForUser($user, $labelId);
            if ($label === null) {
                throw HttpException::notFound('Label not found.');
            }
        }

        $entry->setLabel($label);
        $wishlistRepo->save($entry);

        return $this->noContent();
    }
}
