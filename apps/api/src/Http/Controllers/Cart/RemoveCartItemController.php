<?php

declare(strict_types=1);

namespace Bayti\Api\Http\Controllers\Cart;

use Bayti\Api\Domain\Cart\Cart;
use Bayti\Api\Domain\Cart\CartRepository;
use Bayti\Api\Domain\User\User;
use Bayti\Api\Http\Errors\ErrorCodes;
use Bayti\Api\Http\Errors\HttpException;
use Bayti\Api\Http\Middleware\AuthMiddleware;
use Bayti\Api\Http\Responder;
use Bayti\Api\Http\Serializers\CartSerializer;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * DELETE /v3/cart/items/{id}
 *
 * Removes a line from the authenticated user's active cart.
 *
 * Authorization: same cross-tenant defence as UpdateCartItemController -
 * an item id belonging to another user's cart returns 404, not 403,
 * to avoid leaking existence.
 *
 * Returns the updated cart shape (200, not 204) so mobile can refresh
 * its UI in one round-trip after the delete.
 */
final class RemoveCartItemController
{
    use Responder;

    public function __construct(
        protected readonly ResponseFactoryInterface $responseFactory,
        private readonly EntityManagerInterface $em,
        private readonly CartSerializer $serializer,
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
        $user = $request->getAttribute(AuthMiddleware::ATTR_USER);
        if (!$user instanceof User) {
            throw HttpException::unauthorized(
                ErrorCodes::AUTH_INVALID_TOKEN,
                'Authentication required.',
            );
        }

        $itemId = (int) ($args['id'] ?? 0);
        if ($itemId < 1) {
            throw HttpException::notFound('Cart item not found.');
        }

        /** @var CartRepository $carts */
        $carts = $this->em->getRepository(Cart::class);
        $cart = $carts->findActiveForUser($user);
        if ($cart === null) {
            throw HttpException::notFound('Cart item not found.');
        }

        $item = null;
        foreach ($cart->getItems() as $candidate) {
            if ($candidate->getId() === $itemId) {
                $item = $candidate;
                break;
            }
        }
        if ($item === null) {
            throw HttpException::notFound('Cart item not found.');
        }

        $carts->removeItem($cart, $item);

        return $this->ok([
            'cart' => $this->serializer->listShape($cart),
        ]);
    }
}
