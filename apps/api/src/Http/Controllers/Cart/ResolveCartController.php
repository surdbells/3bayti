<?php

declare(strict_types=1);

namespace Bayti\Api\Http\Controllers\Cart;

use Bayti\Api\Domain\Cart\Cart;
use Bayti\Api\Domain\Cart\CartItem;
use Bayti\Api\Domain\Catalog\Product;
use Bayti\Api\Domain\Catalog\ProductRepository;
use Bayti\Api\Http\Controllers\Cart\Dto\ResolveCartInput;
use Bayti\Api\Http\Responder;
use Bayti\Api\Http\Serializers\CartSerializer;
use Bayti\Api\Http\Validator\RequestValidator;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * POST /v3/cart/resolve  (public, no auth)
 *
 * Resolves a guest device-local cart payload into a server-priced cart
 * for display. For each incoming line we look up the product and emit a
 * line with its CURRENT name, image, and unit price, plus computed line
 * and cart subtotals. The result is returned through CartSerializer so
 * the shape is identical to GET /v3/cart, but it is NOT persisted.
 *
 * Why this exists: guests have no server cart (Q7=B, no anonymous
 * session), so the storefront keeps a device-local cart. Synthesising
 * prices client-side risks showing a stale price if the product changed
 * after it was added. This endpoint lets the storefront render the
 * cart drawer / cart page with authoritative, up-to-date prices.
 *
 * Mirrors the merge resolution (same product lookup + CartItem build)
 * but writes nothing. Unknown / inactive products are dropped and
 * reported in `removed` so the client can prune its local cart.
 *
 * Read-only, no auth, no PII: it exposes only product data that is
 * already publicly queryable via the catalog endpoints.
 */
final class ResolveCartController
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

    public function __invoke(ServerRequestInterface $request): ResponseInterface
    {
        $input = $this->validator->parse($request, ResolveCartInput::class);

        /** @var ProductRepository $products */
        $products = $this->em->getRepository(Product::class);

        // Transient cart, never persisted. A legacy cart code satisfies
        // the entity's "not fully anonymous" guard without needing a user.
        $cart = new Cart(legacyCartCode: 'PND');

        /** @var list<int> $removed */
        $removed = [];

        foreach ($input->items as $incoming) {
            $productId = (int) ($incoming['product_id'] ?? 0);
            $quantity = (int) ($incoming['quantity'] ?? 1);

            $product = $products->find($productId);
            if (!$product instanceof Product || !$product->isActive()) {
                if ($productId > 0) {
                    $removed[] = $productId;
                }
                continue;
            }

            // Price is read live from the product, so a price change since
            // the item was added locally is reflected here.
            $cart->addItem(new CartItem(
                product: $product,
                quantity: $quantity,
                unitPriceSnapshot: $product->getPrice(),
                size: isset($incoming['size']) && is_string($incoming['size']) ? $incoming['size'] : null,
                color: isset($incoming['color']) && is_string($incoming['color']) ? $incoming['color'] : null,
                isCustom: (bool) ($incoming['is_custom'] ?? false),
                measurement: isset($incoming['measurement']) && is_string($incoming['measurement']) ? $incoming['measurement'] : null,
                extraMeasurement: isset($incoming['extra_measurement']) && is_string($incoming['extra_measurement']) ? $incoming['extra_measurement'] : null,
                note: isset($incoming['note']) && is_string($incoming['note']) ? $incoming['note'] : null,
            ));
        }

        return $this->ok([
            'cart' => $this->serializer->listShape($cart),
            'removed' => array_values(array_unique($removed)),
        ]);
    }
}
