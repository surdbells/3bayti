<?php

declare(strict_types=1);

namespace Bayti\Api\Http\Controllers\Cart;

use Bayti\Api\Domain\Cart\Cart;
use Bayti\Api\Domain\Cart\CartItem;
use Bayti\Api\Domain\Cart\CartRepository;
use Bayti\Api\Domain\Catalog\Product;
use Bayti\Api\Domain\Catalog\ProductRepository;
use Bayti\Api\Domain\User\User;
use Bayti\Api\Http\Controllers\Cart\Dto\MergeAnonCartInput;
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
 * POST /v3/cart/merge
 *
 * Merges a device-local cart into the authenticated user's
 * server cart. Called by mobile right after sign-in for users
 * who built a cart as a guest.
 *
 * Locked Q7=B behaviour:
 *
 *   For each incoming item, find an equivalent line in the server
 *   cart (matching product + size + color + is_custom +
 *   measurement + extra_measurement). If found, add quantities
 *   (capped at 999 per line). If not, append a new line.
 *
 * Empty items[] is a no-op success — first sign-in for users
 * with no guest cart returns the empty server cart in 200 OK.
 *
 * Unknown product_ids are SKIPPED (not failed) — a deleted product
 * shouldn't block a sign-in flow. Skipped items return in the
 * response under `skipped` so mobile can show a one-line notice.
 *
 * Returns the merged cart shape so mobile can refresh in one
 * round-trip.
 */
final class MergeAnonCartController
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

        $input = $this->validator->parse($request, MergeAnonCartInput::class);

        /** @var CartRepository $carts */
        $carts = $this->em->getRepository(Cart::class);
        /** @var ProductRepository $products */
        $products = $this->em->getRepository(Product::class);

        $cart = $carts->findActiveForUser($user) ?? $this->createCartFor($user);

        $skipped = [];

        foreach ($input->items as $incoming) {
            $productId = (int) ($incoming['product_id'] ?? 0);
            $quantity = (int) ($incoming['quantity'] ?? 1);

            $product = $products->find($productId);
            if (!$product instanceof Product || !$product->isActive()) {
                $skipped[] = [
                    'product_id' => $productId,
                    'reason' => 'product_unavailable',
                ];
                continue;
            }

            $candidate = new CartItem(
                product: $product,
                quantity: $quantity,
                unitPriceSnapshot: $product->getPrice(),
                size: isset($incoming['size']) && is_string($incoming['size']) ? $incoming['size'] : null,
                color: isset($incoming['color']) && is_string($incoming['color']) ? $incoming['color'] : null,
                isCustom: (bool) ($incoming['is_custom'] ?? false),
                measurement: isset($incoming['measurement']) && is_string($incoming['measurement']) ? $incoming['measurement'] : null,
                extraMeasurement: isset($incoming['extra_measurement']) && is_string($incoming['extra_measurement']) ? $incoming['extra_measurement'] : null,
                note: isset($incoming['note']) && is_string($incoming['note']) ? $incoming['note'] : null,
            );

            $existing = $cart->findEquivalentItem($candidate);
            if ($existing !== null) {
                $newQty = min(
                    self::MAX_LINE_QUANTITY,
                    $existing->getQuantity() + $candidate->getQuantity(),
                );
                $existing->setQuantity($newQty);
            } else {
                $cart->addItem($candidate);
            }
        }

        $carts->saveWithItems($cart);

        return $this->ok([
            'cart' => $this->serializer->listShape($cart),
            'skipped' => $skipped,
        ]);
    }

    private function createCartFor(User $user): Cart
    {
        $cart = new Cart(user: $user);
        $this->em->persist($cart);
        return $cart;
    }
}
