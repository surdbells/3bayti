<?php

declare(strict_types=1);

namespace Bayti\Api\Http\Controllers\Order;

use Bayti\Api\Domain\Cart\Cart;
use Bayti\Api\Domain\Cart\CartItem;
use Bayti\Api\Domain\Cart\CartRepository;
use Bayti\Api\Domain\Catalog\Product;
use Bayti\Api\Domain\Order\Order;
use Bayti\Api\Domain\Order\OrderItem;
use Bayti\Api\Domain\Order\OrderRepository;
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
 * POST /v3/orders/{id}/reorder
 *
 * Re-adds the items of a past order to the customer's cart ("buy again").
 * Primary use is a FAILED order (payment never went through, so the customer
 * wants to try again) — but it works for any of the customer's own orders.
 *
 * Best-effort, per item: a product that is now inactive/removed, or that has
 * become made-to-order and the original line carries no measurement, is
 * SKIPPED rather than failing the whole request. The response reports how many
 * were added vs skipped so the client can tell the customer. Prices are
 * re-snapshotted at the CURRENT effective price (a reorder isn't a price lock).
 * Equivalent lines already in the cart are merged (capped at 999), matching
 * AddCartItemController.
 *
 * Authorization: order must belong to the caller (findForUser); cross-user /
 * unknown → 404.
 */
final class ReorderController
{
    use Responder;

    private const MAX_LINE_QUANTITY = 999;

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
        ResponseInterface $_response,
        array $args,
    ): ResponseInterface {
        $user = $request->getAttribute(AuthMiddleware::ATTR_USER);
        if (!$user instanceof User) {
            throw HttpException::unauthorized(
                ErrorCodes::AUTH_INVALID_TOKEN,
                'Authentication required.',
            );
        }

        $orderId = (int) ($args['id'] ?? 0);
        if ($orderId <= 0) {
            throw HttpException::notFound('Order not found.');
        }

        /** @var OrderRepository $orderRepo */
        $orderRepo = $this->em->getRepository(Order::class);
        $order = $orderRepo->findForUser($orderId, $user);
        if ($order === null) {
            throw HttpException::notFound('Order not found.');
        }

        /** @var CartRepository $carts */
        $carts = $this->em->getRepository(Cart::class);
        $cart = $carts->findActiveForUser($user) ?? $this->createCartFor($user);

        $added = 0;
        $skipped = 0;

        foreach ($order->getItems() as $item) {
            if (!$this->addItemToCart($cart, $item)) {
                $skipped++;
                continue;
            }
            $added++;
        }

        // Gift-card purchase orders have no real items; nothing to re-add.
        if ($added > 0) {
            $carts->saveWithItems($cart);
        }

        return $this->ok([
            'cart' => $this->serializer->listShape($cart),
            'reorder' => [
                'added' => $added,
                'skipped' => $skipped,
                'total' => $added + $skipped,
            ],
        ]);
    }

    /**
     * Re-add one order line to the cart, honouring the same server-side gates
     * AddCartItemController enforces. Returns false (caller counts it skipped)
     * when the product is unavailable or a made-to-order line lost its
     * measurement.
     */
    private function addItemToCart(Cart $cart, OrderItem $item): bool
    {
        $product = $item->getProduct();
        if (!$product instanceof Product || !$product->isActive()) {
            return false;
        }

        // Made-to-order gates: skip a line we can't re-create as fulfillable.
        if ($product->requiresExtraMsmt() && trim((string) $item->getExtraMeasurement()) === '') {
            return false;
        }
        if (strtoupper((string) $item->getSize()) === 'CUSTOM'
            && !$this->isSizeOptionalCategory($product)
            && trim((string) $item->getMeasurement()) === ''
        ) {
            return false;
        }

        $candidate = new CartItem(
            product: $product,
            quantity: max(1, $item->getQuantity()),
            // Re-snapshot at the CURRENT effective price (honours sales).
            unitPriceSnapshot: $product->effectivePrice(),
            size: $item->getSize(),
            color: $item->getColor(),
            isCustom: $item->isCustom(),
            measurement: $item->getMeasurement(),
            extraMeasurement: $item->getExtraMeasurement(),
            note: $item->getNote(),
        );

        $existing = $cart->findEquivalentItem($candidate);
        if ($existing !== null) {
            $existing->setQuantity(min(
                self::MAX_LINE_QUANTITY,
                $existing->getQuantity() + $candidate->getQuantity(),
            ));
        } else {
            $cart->addItem($candidate);
        }

        return true;
    }

    private function createCartFor(User $user): Cart
    {
        $cart = new Cart(user: $user);
        $this->em->persist($cart);
        return $cart;
    }

    /**
     * Categories where a CUSTOM size doesn't require a measurement. Mirrors
     * AddCartItemController::isSizeOptionalCategory.
     */
    private function isSizeOptionalCategory(Product $product): bool
    {
        $slug = $product->getCategory()?->getSlug() ?? '';
        $key = strtolower((string) preg_replace('/-\d+$/', '', $slug));
        return in_array($key, ['bags', 'accessories', 'kaftans', 'mukhawars'], true);
    }
}
