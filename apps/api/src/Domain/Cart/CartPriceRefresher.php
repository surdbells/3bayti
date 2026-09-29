<?php

declare(strict_types=1);

namespace Bayti\Api\Domain\Cart;

use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * Keeps cart line prices in step with the catalog.
 *
 * Every read of an active cart (GET /v3/cart, quote, gift-card previews,
 * guest resolve, add/update/remove/merge responses) and checkout itself runs
 * the cart through here first. Each line is re-synced to its product's
 * current effectivePrice(), the price the customer is actually charged, so a
 * vendor price change or a sale starting/ending always reaches the cart before
 * the customer can pay. Lines whose price moved keep the previously-seen price
 * (CartItem::repriceTo) for the "price updated" badge.
 *
 * Only active carts are touched: converted/archived carts are historical and
 * their prices already live on the order. A persisted cart is flushed when
 * something changed; a transient cart (guest resolve, id null) is re-priced in
 * memory only.
 */
final class CartPriceRefresher
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly LoggerInterface $logger = new NullLogger(),
    ) {
    }

    /**
     * @return list<CartItem> the lines whose charged price changed on this call
     */
    public function refresh(Cart $cart): array
    {
        if (!$cart->isActive()) {
            return [];
        }

        $changed = [];
        foreach ($cart->getItems() as $item) {
            if ($item->repriceTo($item->getProduct()->effectivePrice())) {
                $changed[] = $item;
            }
        }

        if ($changed === []) {
            return [];
        }

        $cart->notePricesRefreshed();
        if ($cart->getId() !== null) {
            $this->em->flush();
        }

        $this->logger->info('cart.prices_refreshed', [
            'cart_id' => $cart->getId(),
            'lines' => array_map(
                static fn (CartItem $i): array => [
                    'product_id' => $i->getProduct()->getId(),
                    'unit_price' => $i->getUnitPriceSnapshot(),
                    'previous_unit_price' => $i->getPreviousUnitPrice(),
                ],
                $changed,
            ),
        ]);

        return $changed;
    }
}
