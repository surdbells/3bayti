<?php

declare(strict_types=1);

namespace Bayti\Api\Notification\Push;

use Bayti\Api\Domain\Notification\DeviceToken;
use Bayti\Api\Domain\Notification\DeviceTokenRepository;
use Bayti\Api\Domain\Order\Order;
use Bayti\Api\Domain\User\User;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * Customer-facing push notifications for order lifecycle events.
 *
 * The push counterpart to OrderNotificationService: the same lifecycle
 * moments that fan out email also fan out push (wired in Z.6). Method
 * names mirror OrderNotificationService so call sites can invoke both
 * side by side.
 *
 * Fan-out + pruning
 * -----------------
 * Each lifecycle method builds a PushMessage and sends it to EVERY
 * active device token the customer has registered (a user may have
 * several devices). Sends are independent: one token failing never
 * stops the others. When a send fails with KIND_UNREGISTERED (the
 * device uninstalled / token rotated), the token is deactivated so we
 * stop targeting it.
 *
 * Failure policy
 * --------------
 * Mirrors the email service: a push failure NEVER blocks or surfaces
 * to the calling action (order placement, webhook handling, etc.).
 * Every failure is logged and swallowed. Push is non-critical.
 *
 * Repository resolution is LAZY (per call) via the optional
 * EntityManager — same locked pattern as OrderNotificationService —
 * so the service constructs cleanly even in contexts where the
 * device_tokens mapping isn't needed.
 *
 * data payload
 * ------------
 * Every message carries a `type` (the lifecycle event key, e.g.
 * 'order.paid') and `order_id`/`order_reference` so the mobile app can
 * deep-link from a tapped notification to the right order screen.
 */
final class PushNotificationService
{
    public function __construct(
        private readonly PushSenderInterface $sender,
        private readonly LoggerInterface $logger = new NullLogger(),
        private readonly ?EntityManagerInterface $em = null,
    ) {
    }

    // -----------------------------------------------------------------
    // Lifecycle hooks — mirror OrderNotificationService customer events
    // -----------------------------------------------------------------

    /** Customer just submitted the order (still pending_payment). */
    public function orderPlaced(Order $order): void
    {
        $this->pushToCustomer($order, 'order.placed', 'Order received', sprintf(
            'We\'ve received your order %s. Complete payment to confirm it.',
            $order->getOrderReference(),
        ));
    }

    /** Payment confirmed. */
    public function orderPaid(Order $order): void
    {
        $this->pushToCustomer($order, 'order.paid', 'Payment confirmed', sprintf(
            'Your order %s is confirmed. We\'ll let you know when it ships.',
            $order->getOrderReference(),
        ));
    }

    /** Payment failed. */
    public function orderPaymentFailed(Order $order): void
    {
        $this->pushToCustomer($order, 'order.payment_failed', 'Payment failed', sprintf(
            'Payment for order %s didn\'t go through. No charge was made — you can try again.',
            $order->getOrderReference(),
        ));
    }

    /** An item in the order was shipped. */
    public function itemShipped(Order $order): void
    {
        $this->pushToCustomer($order, 'order.shipped', 'Your order has shipped', sprintf(
            'Good news — items from order %s are on the way.',
            $order->getOrderReference(),
        ));
    }

    /** An item in the order was delivered. */
    public function itemDelivered(Order $order): void
    {
        $this->pushToCustomer($order, 'order.delivered', 'Order delivered', sprintf(
            'Your order %s has been delivered. Enjoy!',
            $order->getOrderReference(),
        ));
    }

    /** Order cancelled. */
    public function orderCancelled(Order $order): void
    {
        $this->pushToCustomer($order, 'order.cancelled', 'Order cancelled', sprintf(
            'Your order %s has been cancelled.',
            $order->getOrderReference(),
        ));
    }

    /** Order refunded. */
    public function orderRefunded(Order $order): void
    {
        $this->pushToCustomer($order, 'order.refunded', 'Refund issued', sprintf(
            'A refund for order %s has been issued.',
            $order->getOrderReference(),
        ));
    }

    // -----------------------------------------------------------------
    // Internals
    // -----------------------------------------------------------------

    /**
     * Build the message and fan out to all of the customer's active
     * device tokens. Never throws.
     */
    private function pushToCustomer(Order $order, string $type, string $title, string $body): void
    {
        $user = $order->getUser();

        $tokens = $this->activeTokensFor($user);
        if ($tokens === []) {
            // Nothing to do — customer has no registered devices.
            return;
        }

        $message = new PushMessage(
            title: $title,
            body: $body,
            data: [
                'type' => $type,
                'order_id' => (string) ($order->getId() ?? ''),
                'order_reference' => $order->getOrderReference(),
            ],
        );

        $context = [
            'event' => $type,
            'order_id' => $order->getId(),
            'user_id' => $user->getId(),
        ];

        foreach ($tokens as $deviceToken) {
            $this->sendOne($deviceToken, $message, $context);
        }
    }

    /**
     * Send to a single device token, swallowing failures. Prunes the
     * token if FCM reports it permanently dead.
     */
    /** @param array<string, mixed> $context */
    private function sendOne(DeviceToken $deviceToken, PushMessage $message, array $context): void
    {
        try {
            $this->sender->sendToToken($deviceToken->getToken(), $message, $context);
        } catch (PushException $e) {
            if ($e->isTokenDead()) {
                $this->pruneDeadToken($deviceToken, $context);
                return;
            }
            $this->logger->warning('push.send_failed', array_merge($context, [
                'kind' => $e->kind,
                'error' => $e->getMessage(),
            ]));
        } catch (\Throwable $e) {
            // Defensive: a non-PushException must never bubble into the
            // calling action.
            $this->logger->error('push.send_unexpected_error', array_merge($context, [
                'class' => $e::class,
                'error' => $e->getMessage(),
            ]));
        }
    }

    /** Deactivate a permanently-dead token; swallow persistence errors. */
    /** @param array<string, mixed> $context */
    private function pruneDeadToken(DeviceToken $deviceToken, array $context): void
    {
        $this->logger->info('push.token_pruned', array_merge($context, [
            'reason' => 'unregistered',
        ]));
        try {
            $deviceToken->deactivate();
            $this->em?->flush();
        } catch (\Throwable $e) {
            $this->logger->error('push.token_prune_failed', array_merge($context, [
                'error' => $e->getMessage(),
            ]));
        }
    }

    /**
     * The user's active device tokens. Returns [] (and logs) if the
     * repository can't be resolved, so callers degrade gracefully.
     *
     * @return list<DeviceToken>
     */
    private function activeTokensFor(User $user): array
    {
        if ($this->em === null) {
            return [];
        }
        try {
            $repo = $this->em->getRepository(DeviceToken::class);
            if (!$repo instanceof DeviceTokenRepository) {
                return [];
            }
            return $repo->findActiveForUser($user);
        } catch (\Throwable $e) {
            $this->logger->error('push.token_lookup_failed', [
                'user_id' => $user->getId(),
                'error' => $e->getMessage(),
            ]);
            return [];
        }
    }
}
