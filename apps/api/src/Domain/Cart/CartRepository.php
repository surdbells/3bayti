<?php

declare(strict_types=1);

namespace Bayti\Api\Domain\Cart;

use Bayti\Api\Domain\User\User;
use Doctrine\ORM\EntityRepository;

/**
 * Repository for Cart.
 *
 * @extends EntityRepository<Cart>
 */
class CartRepository extends EntityRepository
{
    /**
     * Find the active cart for a given user, eager-loading items +
     * their products in one query to avoid N+1 in serialisation.
     *
     * Returns null if the user has no active cart. The AddCartItem
     * controller treats null as "create on demand".
     */
    public function findActiveForUser(User $user): ?Cart
    {
        $result = $this->createQueryBuilder('c')
            ->select('c', 'i', 'p')
            ->leftJoin('c.items', 'i')
            ->leftJoin('i.product', 'p')
            ->where('c.user = :user')
            ->andWhere('c.status = :active')
            ->setParameter('user', $user)
            ->setParameter('active', Cart::STATUS_ACTIVE)
            ->orderBy('i.id', 'ASC')
            ->getQuery()
            ->getOneOrNullResult();

        /** @var Cart|null $result */
        return $result;
    }

    /**
     * Lookup helper for the legacy migration step in M3.1.6h.
     * Returns null if the cart hasn't been migrated yet.
     */
    public function findByLegacyCartCode(string $legacyCartCode): ?Cart
    {
        $result = $this->findOneBy(['legacyCartCode' => $legacyCartCode]);
        /** @var Cart|null $result */
        return $result;
    }

    /**
     * Persist + flush. Centralised here so controllers don't have to
     * touch the EntityManager directly for the common case.
     */
    public function save(Cart $cart): void
    {
        $em = $this->getEntityManager();
        $em->persist($cart);
        $em->flush();
    }

    /**
     * Persist a cart + all its items as one unit. Useful for cart
     * merge and add-to-cart flows that mutate multiple items in one
     * controller call.
     */
    public function saveWithItems(Cart $cart): void
    {
        $em = $this->getEntityManager();
        $em->persist($cart);
        foreach ($cart->getItems() as $item) {
            $em->persist($item);
        }
        $em->flush();
    }

    /**
     * Remove a CartItem from a cart and flush. The cart_items table
     * has FK ON DELETE CASCADE from carts, but here we're removing
     * an individual item, not the whole cart, so we delete the row
     * directly.
     */
    public function removeItem(Cart $cart, CartItem $item): void
    {
        $cart->removeItem($item);
        $em = $this->getEntityManager();
        $em->remove($item);
        $em->flush();
    }
}
