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
 * GET /v3/cart
 *
 * Returns the authenticated user's active cart. If the user has
 * no cart yet, returns an empty-cart shape with id=0, this lets
 * mobile render an empty state without needing a separate
 * "no cart" endpoint.
 *
 * Per Q7=B, this endpoint is auth-required. Guests use device-local
 * storage and call POST /v3/cart/merge after sign-in to copy their
 * device cart into the server.
 */
final class GetCartController
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

    public function __invoke(ServerRequestInterface $request): ResponseInterface
    {
        $user = $request->getAttribute(AuthMiddleware::ATTR_USER);
        if (!$user instanceof User) {
            throw HttpException::unauthorized(
                ErrorCodes::AUTH_INVALID_TOKEN,
                'Authentication required.',
            );
        }

        /** @var CartRepository $carts */
        $carts = $this->em->getRepository(Cart::class);
        $cart = $carts->findActiveForUser($user);

        if ($cart === null) {
            return $this->ok([
                'cart' => [
                    'id' => 0,
                    'status' => 'active',
                    'currency' => 'AED',
                    'cart_code' => 'PND',
                    'subtotal' => '0.00',
                    'item_count' => 0,
                    'items' => [],
                ],
            ]);
        }

        return $this->ok([
            'cart' => $this->serializer->listShape($cart),
        ]);
    }
}
