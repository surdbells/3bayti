<?php

declare(strict_types=1);

namespace Bayti\Api\Http\Controllers\Cart;

use Bayti\Api\Domain\Cart\Cart;
use Bayti\Api\Domain\Cart\CartItem;
use Bayti\Api\Domain\Cart\CartRepository;
use Bayti\Api\Domain\Catalog\Product;
use Bayti\Api\Domain\Catalog\ProductRepository;
use Bayti\Api\Domain\User\User;
use Bayti\Api\Http\Controllers\Cart\Dto\AddCartItemInput;
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
 * POST /v3/cart/items
 *
 * Adds a product to the authenticated user's cart.
 *
 * Server responsibilities (the legacy add_cart body trusts the
 * client with all this; v3 derives server-side):
 *
 *   - Resolve the product; reject if not found / inactive
 *   - Snapshot the current product.price into cart_items.unit_price_snapshot
 *   - If an equivalent line exists in the cart (same product +
 *     variant attributes), increment its quantity instead of
 *     creating a duplicate row
 *   - Cap total line quantity at 999 (matches AddCartItemInput
 *     bounds; protects from a client that keeps tapping "+")
 *   - Auto-create the user's active cart if they don't have one yet
 *
 * Returns 201 Created with the updated cart shape.
 */
final class AddCartItemController
{
    use Responder;

    private const MAX_LINE_QUANTITY = 999;

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

    public function __invoke(ServerRequestInterface $request): ResponseInterface
    {
        $user = $request->getAttribute(AuthMiddleware::ATTR_USER);
        if (!$user instanceof User) {
            throw HttpException::unauthorized(
                ErrorCodes::AUTH_INVALID_TOKEN,
                'Authentication required.',
            );
        }

        $input = $this->validator->parse($request, AddCartItemInput::class);

        /** @var ProductRepository $products */
        $products = $this->em->getRepository(Product::class);
        $product = $products->find($input->product_id);
        if (!$product instanceof Product || !$product->isActive()) {
            throw HttpException::notFound('Product not found.');
        }

        /** @var CartRepository $carts */
        $carts = $this->em->getRepository(Cart::class);
        $cart = $carts->findActiveForUser($user) ?? $this->createCartFor($user);

        $candidate = new CartItem(
            product: $product,
            quantity: $input->quantity ?? 1,
            unitPriceSnapshot: $product->getPrice(),
            size: $input->size,
            color: $input->color,
            isCustom: $input->is_custom,
            measurement: $input->measurement,
            extraMeasurement: $input->extra_measurement,
            note: $input->note,
        );

        $existing = $cart->findEquivalentItem($candidate);
        if ($existing !== null) {
            // Merge into existing line; cap at MAX_LINE_QUANTITY.
            $newQty = min(
                self::MAX_LINE_QUANTITY,
                $existing->getQuantity() + $candidate->getQuantity(),
            );
            $existing->setQuantity($newQty);
        } else {
            $cart->addItem($candidate);
        }

        $carts->saveWithItems($cart);

        return $this->created([
            'cart' => $this->serializer->listShape($cart),
        ]);
    }

    private function createCartFor(User $user): Cart
    {
        $cart = new Cart(user: $user);
        $this->em->persist($cart);
        return $cart;
    }
}
