<?php

declare(strict_types=1);

namespace Bayti\Api\Http\Controllers\Cart;

use Bayti\Api\Domain\Cart\Cart;
use Bayti\Api\Domain\Cart\CartRepository;
use Bayti\Api\Domain\User\User;
use Bayti\Api\Http\Controllers\Cart\Dto\UpdateCartItemInput;
use Bayti\Api\Http\Errors\ErrorCodes;
use Bayti\Api\Http\Errors\HttpException;
use Bayti\Api\Http\Middleware\AuthMiddleware;
use Bayti\Api\Http\Responder;
use Bayti\Api\Http\Serializers\CartSerializer;
use Bayti\Api\Http\Validator\RequestValidator;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * PATCH /v3/cart/items/{id}
 *
 * Updates a cart line item's quantity.
 *
 * Replaces mobile's legacy /customer/IncreaseItem +
 * /customer/decreaseItem endpoints, which take a delta. v3 uses
 * absolute quantity instead — idempotent (re-applying the same
 * PATCH doesn't compound), and clients don't need a sequence
 * number to avoid race-condition double-counting.
 *
 * To remove a line, clients call DELETE /v3/cart/items/{id};
 * quantity=0 here is rejected at the DTO layer (the validation
 * message points them at the correct endpoint).
 *
 * Authorization: line must belong to the authenticated user's
 * active cart. Cross-tenant access (item id from another user's
 * cart) returns 404 — not 403 — to avoid leaking item existence.
 */
final class UpdateCartItemController
{
    use Responder;

    public function __construct(
        protected readonly ResponseFactoryInterface $responseFactory,
        private readonly RequestValidator $validator,
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

        $input = $this->validator->parse($request, UpdateCartItemInput::class);

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

        $item->setQuantity($input->quantity ?? 1);

        $carts->saveWithItems($cart);

        return $this->ok([
            'cart' => $this->serializer->listShape($cart),
        ]);
    }
}
