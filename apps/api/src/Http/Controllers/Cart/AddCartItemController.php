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

        // Products flagged requiresExtraMsmt need the vendor's EXTRA measurement
        // (an additional measurement beyond the account profile). Rejected here
        // when empty so a vendor never receives an un-fulfillable line. (The
        // account-profile/CUSTOM-size requirement is enforced separately.)
        if ($product->requiresExtraMsmt() && trim((string) $input->extra_measurement) === '') {
            throw HttpException::validation([
                'extra_measurement' => ['Extra measurement is required for this product.'],
            ]);
        }

        // CUSTOM size is made-to-order: it must carry the customer's body
        // measurement snapshot. Server-authoritative on the size value so a
        // spoofed is_custom flag can't bypass it. Skipped for size-optional
        // categories (bags / accessories / kaftans / mukhawars).
        if (strtoupper((string) $input->size) === 'CUSTOM'
            && !$this->isSizeOptionalCategory($product)
            && trim((string) $input->measurement) === ''
        ) {
            throw HttpException::validation([
                'measurement' => ['Measurements are required for a custom size.'],
            ]);
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

    /**
     * Categories where size selection (incl. CUSTOM) is optional, so a CUSTOM
     * line isn't forced to carry a measurement. Keyed on the bare category
     * name (the slug's trailing "-<id>" stripped).
     */
    private function isSizeOptionalCategory(Product $product): bool
    {
        $slug = $product->getCategory()?->getSlug() ?? '';
        $key = strtolower((string) preg_replace('/-\d+$/', '', $slug));
        return in_array($key, ['bags', 'accessories', 'kaftans', 'mukhawars'], true);
    }
}
