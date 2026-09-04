<?php

declare(strict_types=1);

namespace Bayti\Api\Domain\User;

use Doctrine\ORM\EntityManagerInterface;

/**
 * Permanently (hard) delete a customer and ALL of their data, including
 * commerce history — orders, order items, addresses, payment transactions,
 * gift cards, promo redemptions, return requests, cart, wishlist, reviews,
 * sessions and everything else the account owns.
 *
 * This is the irreversible counterpart to the soft-delete / deactivate flows
 * and is only reachable from the admin "Delete customer account" action
 * (DeleteUserController), which restricts it to non-staff, non-vendor
 * customer accounts.
 *
 * Why explicit ordered deletes (not a single DB cascade)
 * ------------------------------------------------------
 * Four FKs to `users` are ON DELETE RESTRICT by design, so an accidental user
 * delete can NEVER silently drop financial/order rows:
 *   - orders.user_id
 *   - gift_cards.buyer_user_id
 *   - promo_redemptions.user_id
 *   - order_return_requests.customer_user_id
 * Two further RESTRICT edges guard the order aggregate:
 *   - payment_transactions.order_id
 *   - order_return_request_items.order_item_id
 * We clear those blockers in dependency order inside ONE transaction, then let
 * the remaining CASCADE / SET NULL edges remove (or detach) everything else
 * the account owns. If some future table adds another RESTRICT edge we miss,
 * the FK check fails and the whole transaction rolls back — the delete fails
 * loudly rather than leaving the account half-removed.
 *
 * Deliberately retained via SET NULL (anonymised, not the person's to keep):
 *   - product_reviews.user_id (the review text stays, authorless)
 *   - gift_cards.recipient_user_id (a card this user only RECEIVED belongs to
 *     a different buyer, so only the recipient link is nulled)
 *   - staff/audit author columns (created_by / reviewed_by / decided_by / …)
 *
 * Non-final so the DeleteUserController HTTP test can spy on delete() without a
 * live PostgreSQL connection (the raw cascade SQL is verified against the real
 * schema, not the mocked-EM test harness).
 */
class HardDeleteUserService
{
    public function __construct(private readonly EntityManagerInterface $em)
    {
    }

    /**
     * Erase the user and everything they own. Runs in a single transaction;
     * throws (and rolls back) if any statement fails, leaving the account
     * untouched.
     */
    public function delete(User $user): void
    {
        $uid = $user->getId();
        if ($uid === null) {
            return;
        }

        $this->em->wrapInTransaction(function () use ($uid): void {
            $conn = $this->em->getConnection();
            $params = ['uid' => $uid];

            // 1. Return requests the customer raised (RESTRICT on
            //    customer_user_id). Cascades their items / photos / refund
            //    (all CASCADE on return_request_id), which in turn frees the
            //    order_items below (order_return_request_items is RESTRICT on
            //    order_item_id).
            $conn->executeStatement(
                'DELETE FROM order_return_requests WHERE customer_user_id = :uid',
                $params,
            );

            // 2. Payment transactions on the customer's orders (RESTRICT on
            //    order_id) — must go before the orders themselves.
            $conn->executeStatement(
                'DELETE FROM payment_transactions WHERE order_id IN '
                . '(SELECT id FROM orders WHERE user_id = :uid)',
                $params,
            );

            // 3. Promo redemptions (RESTRICT on user_id).
            $conn->executeStatement(
                'DELETE FROM promo_redemptions WHERE user_id = :uid',
                $params,
            );

            // 4. Gift cards the customer bought (RESTRICT on buyer_user_id).
            //    Cascades gift_card_transactions.
            $conn->executeStatement(
                'DELETE FROM gift_cards WHERE buyer_user_id = :uid',
                $params,
            );

            // 5. Orders (RESTRICT on user_id). Cascades order_items,
            //    order_addresses, conversations, remaining promo redemptions,
            //    and nulls payment_webhook_events / order_disputes.
            $conn->executeStatement(
                'DELETE FROM orders WHERE user_id = :uid',
                $params,
            );

            // 6. Finally the user row. Cascades cart(+items), addresses,
            //    measurements, refresh_tokens, social_identities,
            //    device_tokens, vendor_follows, wishlists, wishlist_labels,
            //    otp_attempts, user_locations and customer conversations;
            //    SET NULL on reviews, received gift cards and audit-author
            //    columns.
            $conn->executeStatement(
                'DELETE FROM users WHERE id = :uid',
                $params,
            );
        });

        // The raw DELETEs bypassed the ORM's unit of work, so drop the now
        // stale managed copy of the deleted user from the identity map. The
        // acting admin is a different entity and is left untouched.
        if ($this->em->contains($user)) {
            $this->em->detach($user);
        }
    }
}
