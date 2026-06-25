<?php

declare(strict_types=1);

namespace Bayti\Api\Http\Controllers\Wishlist;

use Bayti\Api\Domain\Catalog\Product;
use Bayti\Api\Domain\Catalog\ProductRepository;
use Bayti\Api\Domain\User\User;
use Bayti\Api\Domain\Wishlist\Wishlist;
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
 * DELETE /v3/me/wishlist/{productId} — un-save a product.
 *
 * Idempotent: returns 204 whether or not the product was actually
 * saved. "Make it not be on my wishlist" is the goal; if it's already
 * absent, the goal is met. (Mirrors the idempotent-POST posture from
 * the other direction.)
 */
final class RemoveWishlistItemController
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
            throw HttpException::unauthorized(
                ErrorCodes::AUTH_INVALID_TOKEN,
                'Authentication required.',
            );
        }

        $productId = (int) ($args['productId'] ?? 0);

        // No product / not a real product → nothing to remove. Still
        // 204 (idempotent), so we don't leak product existence either.
        // Resolve in the same legacy-id-first precedence the mobile cards
        // use (see ProductRepository::findByIdOrLegacyId).
        /** @var ProductRepository $productRepo */
        $productRepo = $this->em->getRepository(Product::class);
        $product = $productRepo->findByIdOrLegacyId($productId);
        if ($product instanceof Product) {
            /** @var WishlistRepository $wishlistRepo */
            $wishlistRepo = $this->em->getRepository(Wishlist::class);
            $existing = $wishlistRepo->findOneForUserAndProduct($user, $product);
            if ($existing !== null) {
                $wishlistRepo->remove($existing);
            }
        }

        return $this->noContent();
    }
}
