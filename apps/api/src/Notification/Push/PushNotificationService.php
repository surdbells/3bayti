<?php

declare(strict_types=1);

namespace Bayti\Api\Notification\Push;

use Bayti\Api\Domain\Cart\Cart;
use Bayti\Api\Domain\Notification\DeviceToken;
use Bayti\Api\Domain\Notification\DeviceTokenRepository;
use Bayti\Api\Domain\Order\Order;
use Bayti\Api\Domain\User\User;
use Bayti\Api\Notification\LocaleResolver;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * Customer-facing push notifications for order lifecycle events plus the
 * three marketing pushes (cart abandonment, post-delivery review prompt,
 * re-engagement nudge).
 *
 * The push counterpart to OrderNotificationService: the same lifecycle
 * moments that fan out email also fan out push (wired in Z.6). Method
 * names mirror OrderNotificationService so call sites can invoke both
 * side by side.
 *
 * Localization
 * ------------
 * Every message is bilingual (English + Arabic). The recipient User's
 * locale is resolved per send via LocaleResolver::normalizeToShortTag
 * (the same short-tag normalization the email OrderNotificationService
 * uses) and the matching copy is chosen. Unknown / unsupported locales
 * fall back to English. Resolution is at SEND time, not snapshot time -
 * same rationale as the email locale resolver.
 *
 * Marketing opt-out
 * -----------------
 * The three marketing methods (cartAbandoned, orderReviewPrompt,
 * reEngagementNudge) early-return when the recipient has
 * marketing_push_opt_out = true. Order-lifecycle pushes IGNORE the flag -
 * they are transactional and always send.
 *
 * Fan-out + pruning
 * -----------------
 * Each method builds a PushMessage and sends it to EVERY active device
 * token the customer has registered. Sends are independent: one token
 * failing never stops the others. A KIND_UNREGISTERED failure deactivates
 * the dead token.
 *
 * Failure policy
 * --------------
 * Mirrors the email service: a push failure NEVER blocks or surfaces to
 * the calling action. Every failure is logged and swallowed. Push is
 * non-critical.
 *
 * Repository resolution is LAZY (per call) via the optional EntityManager.
 *
 * data payload
 * ------------
 * Every message carries a `type` plus the deep-link payload the mobile
 * app uses to route a tapped notification:
 *   order.*               -> {order_id, order_reference}      -> /orders/{order_id}
 *   chat.message          -> {conversation_uuid, order_reference} -> /chat
 *   gift_card.expiry_nudge-> {card_id, balance}               -> gift-card detail
 *   cart.abandoned        -> {cart_id}                        -> /cart
 *   order.review_prompt   -> {order_id, order_reference}      -> /orders/{order_id}
 *   re_engagement.nudge   -> {}                               -> home/shop tab
 */
class PushNotificationService
{
    public function __construct(
        private readonly PushSenderInterface $sender,
        private readonly LocaleResolver $localeResolver,
        private readonly LoggerInterface $logger = new NullLogger(),
        private readonly ?EntityManagerInterface $em = null,
    ) {
    }

    // -----------------------------------------------------------------
    // Lifecycle hooks, mirror OrderNotificationService customer events
    // (transactional: ALWAYS send, ignore marketing opt-out)
    // -----------------------------------------------------------------

    /** Customer just submitted the order (still pending_payment). */
    public function orderPlaced(Order $order): void
    {
        $ref = $order->getOrderReference();
        $this->pushToCustomer($order, 'order.placed', [
            'en' => ['Order received', sprintf("We've received your order %s. Complete payment to confirm it.", $ref)],
            'ar' => ['تم استلام الطلب', sprintf('لقد استلمنا طلبك %s. أكمل الدفع لتأكيده.', $ref)],
        ]);
    }

    /** Payment confirmed. */
    public function orderPaid(Order $order): void
    {
        $ref = $order->getOrderReference();
        $this->pushToCustomer($order, 'order.paid', [
            'en' => ['Payment confirmed', sprintf("Your order %s is confirmed. We'll let you know when it ships.", $ref)],
            'ar' => ['تم تأكيد الدفع', sprintf('تم تأكيد طلبك %s. سنخبرك عند شحنه.', $ref)],
        ]);
    }

    /** Payment failed. */
    public function orderPaymentFailed(Order $order): void
    {
        $ref = $order->getOrderReference();
        $this->pushToCustomer($order, 'order.payment_failed', [
            'en' => ['Payment failed', sprintf("Payment for order %s didn't go through. No charge was made — you can try again.", $ref)],
            'ar' => ['فشل الدفع', sprintf('لم تتم عملية الدفع للطلب %s. لم يتم خصم أي مبلغ — يمكنك المحاولة مرة أخرى.', $ref)],
        ]);
    }

    /**
     * Scheduled reminder that an order still needs payment (pending_payment)
     * or that a failed charge can be retried. Fired by the
     * orders:send-payment-reminders cron, not a lifecycle transition.
     *
     * Transactional, it concerns the customer's own unfinished order, so
     * (like orderPlaced / orderPaymentFailed) it is NOT gated on the
     * marketing-push opt-out. Deep-links to /orders/{order_id} via the
     * order.payment_reminder type, where the app offers "Complete payment".
     */
    public function orderPaymentReminder(Order $order, string $reason = 'pending', bool $followup = false): void
    {
        $ref = $order->getOrderReference();
        // Distinct data.type per stage so each has its own push idempotency
        // guard; both deep-link to /orders/{id}.
        $type = $followup ? 'order.payment_reminder2' : 'order.payment_reminder';

        if ($reason === 'failed') {
            $copy = $followup
                ? [
                    'en' => ['Last reminder: payment needed', sprintf("Order %s still isn't paid. Tap to try again before it's cancelled.", $ref)],
                    'ar' => ['تذكير أخير: الدفع مطلوب', sprintf('لا يزال الطلب %s غير مدفوع. اضغط لإعادة المحاولة قبل إلغائه.', $ref)],
                ]
                : [
                    'en' => ['Payment needed', sprintf("Payment for order %s didn't go through. Tap to try again.", $ref)],
                    'ar' => ['الدفع مطلوب', sprintf('لم تتم عملية الدفع للطلب %s. اضغط لإعادة المحاولة.', $ref)],
                ];
            $this->pushToCustomer($order, $type, $copy);
            return;
        }

        $copy = $followup
            ? [
                'en' => ['Last reminder to pay', sprintf('Order %s is still awaiting payment. Tap to complete it before it expires.', $ref)],
                'ar' => ['تذكير أخير للدفع', sprintf('لا يزال الطلب %s بانتظار الدفع. اضغط لإكماله قبل انتهاء صلاحيته.', $ref)],
            ]
            : [
                'en' => ['Complete your payment', sprintf('Your order %s is still awaiting payment. Tap to complete it.', $ref)],
                'ar' => ['أكمل الدفع', sprintf('لا يزال طلبك %s بانتظار الدفع. اضغط لإكماله.', $ref)],
            ];
        $this->pushToCustomer($order, $type, $copy);
    }

    /** An item in the order was accepted by the seller. */
    public function itemAccepted(Order $order): void
    {
        $ref = $order->getOrderReference();
        $this->pushToCustomer($order, 'order.accepted', [
            'en' => ['Order confirmed', sprintf('The seller has confirmed items from order %s and is preparing them.', $ref)],
            'ar' => ['تم تأكيد الطلب', sprintf('أكد البائع المنتجات من الطلب %s ويقوم بتجهيزها.', $ref)],
        ]);
    }

    /** An item in the order is being prepared. */
    public function itemPreparing(Order $order): void
    {
        $ref = $order->getOrderReference();
        $this->pushToCustomer($order, 'order.preparing', [
            'en' => ['Order being prepared', sprintf('Items from order %s are being prepared for shipment.', $ref)],
            'ar' => ['جاري تجهيز الطلب', sprintf('يتم تجهيز منتجات الطلب %s للشحن.', $ref)],
        ]);
    }

    /** An item in the order was rejected by the seller. */
    public function itemRejected(Order $order): void
    {
        $ref = $order->getOrderReference();
        $this->pushToCustomer($order, 'order.rejected', [
            'en' => ['Item unavailable', sprintf("Sorry — an item in order %s couldn't be fulfilled. Tap for details and any refund.", $ref)],
            'ar' => ['منتج غير متوفر', sprintf('عذراً — تعذّر تجهيز أحد منتجات الطلب %s. اضغط للتفاصيل وأي استرداد.', $ref)],
        ]);
    }

    /** An item in the order was shipped. */
    public function itemShipped(Order $order): void
    {
        $ref = $order->getOrderReference();
        $this->pushToCustomer($order, 'order.shipped', [
            'en' => ['Your order has shipped', sprintf('Good news — items from order %s are on the way.', $ref)],
            'ar' => ['تم شحن طلبك', sprintf('أخبار سارة — منتجات الطلب %s في الطريق إليك.', $ref)],
        ]);
    }

    /** An item in the order was delivered. */
    public function itemDelivered(Order $order): void
    {
        $ref = $order->getOrderReference();
        $this->pushToCustomer($order, 'order.delivered', [
            'en' => ['Order delivered', sprintf('Your order %s has been delivered. Enjoy!', $ref)],
            'ar' => ['تم توصيل الطلب', sprintf('تم توصيل طلبك %s. نتمنى لك تجربة سعيدة!', $ref)],
        ]);
    }

    /** Order cancelled. */
    public function orderCancelled(Order $order): void
    {
        $ref = $order->getOrderReference();
        $this->pushToCustomer($order, 'order.cancelled', [
            'en' => ['Order cancelled', sprintf('Your order %s has been cancelled.', $ref)],
            'ar' => ['تم إلغاء الطلب', sprintf('تم إلغاء طلبك %s.', $ref)],
        ]);
    }

    /** Order refunded. */
    public function orderRefunded(Order $order): void
    {
        $ref = $order->getOrderReference();
        $this->pushToCustomer($order, 'order.refunded', [
            'en' => ['Refund issued', sprintf('A refund for order %s has been issued.', $ref)],
            'ar' => ['تم إصدار استرداد', sprintf('تم إصدار استرداد للطلب %s.', $ref)],
        ]);
    }

    /**
     * Generic order status-change push, the counterpart to
     * OrderNotificationService::orderStatusChanged. Fired by the admin
     * order-status override endpoint when the new status has no
     * dedicated lifecycle push. Deep-links to /orders/{order_id} via the
     * order.status_changed type. Never throws.
     */
    public function orderStatusChanged(Order $order): void
    {
        $ref = $order->getOrderReference();
        [$enStatus, $arStatus] = $this->statusLabels($order->getStatus());
        $this->pushToCustomer($order, 'order.status_changed', [
            'en' => ['Order update', sprintf('Your order %s is now %s.', $ref, $enStatus)],
            'ar' => ['تحديث الطلب', sprintf('طلبك %s الآن %s.', $ref, $arStatus)],
        ]);
    }

    /**
     * Friendly [en, ar] labels for an order status, so the status-change push
     * names the actual state instead of "there is an update".
     *
     * @return array{0: string, 1: string}
     */
    private function statusLabels(string $status): array
    {
        return match ($status) {
            'paid'       => ['confirmed', 'مؤكد'],
            'fulfilling' => ['being prepared', 'قيد التجهيز'],
            'shipped'    => ['on its way', 'في الطريق'],
            'delivered'  => ['delivered', 'تم التوصيل'],
            'cancelled'  => ['cancelled', 'ملغى'],
            'refunded'   => ['refunded', 'تم الاسترداد'],
            default      => [str_replace('_', ' ', $status), str_replace('_', ' ', $status)],
        };
    }

    // -----------------------------------------------------------------
    // Gift card + chat
    // -----------------------------------------------------------------

    /**
     * Gift card expiry nudge (M3.5).
     * Called by the nightly expiry-check CLI script for cards expiring
     * within 30 days that have a non-zero balance.
     * Fire-and-forget: never throws.
     */
    public function giftCardExpiryNudge(\Bayti\Api\Domain\GiftCard\GiftCard $card): void
    {
        $user   = $card->getRecipientUser() ?? $card->getBuyerUser();
        $tokens = $this->activeTokensFor($user);
        if ($tokens === []) {
            return;
        }

        $expiresAt = $card->getExpiresAt();
        $daysLeft  = $expiresAt !== null
            ? (int) (new \DateTimeImmutable())->diff($expiresAt)->days
            : 30;

        $locale  = $this->localeFor($user);
        $balance = $card->getBalance();

        if ($locale === User::LOCALE_AR) {
            $title = 'بطاقة هديتك على وشك الانتهاء';
            $body  = sprintf(
                'لديك %s درهم متبقٍ في بطاقة هدايا 3bayti. استخدمها خلال %d يوم قبل انتهاء صلاحيتها.',
                $balance,
                $daysLeft,
            );
        } else {
            $title = 'Your gift card expires soon';
            $body  = sprintf(
                'You have AED %s left on your 3bayti gift card. Use it within %d day%s before it expires.',
                $balance,
                $daysLeft,
                $daysLeft === 1 ? '' : 's',
            );
        }

        $message = new PushMessage(
            title: $title,
            body: $body,
            data: [
                'type'    => 'gift_card.expiry_nudge',
                'card_id' => (string) ($card->getId() ?? ''),
                'balance' => $balance,
            ],
        );

        $context = [
            'event'   => 'gift_card.expiry_nudge',
            'card_id' => $card->getId(),
            'user_id' => $user->getId(),
        ];

        foreach ($tokens as $deviceToken) {
            $this->sendOne($deviceToken, $message, $context);
        }
    }

    /**
     * Push a new-chat-message ping to the recipient's devices. Never throws.
     */
    public function chatMessage(
        \Bayti\Api\Domain\User\User $recipient,
        \Bayti\Api\Domain\Chat\Conversation $conversation,
        string $counterpartyName,
        string $preview,
    ): void {
        $tokens = $this->activeTokensFor($recipient);
        if ($tokens === []) {
            return;
        }

        $orderReference = $conversation->getOrder()->getOrderReference();
        // Title is the counterparty's name (locale-independent); the body
        // is the message preview the sender wrote, also content, not copy.
        $message = new PushMessage(
            title: $counterpartyName,
            body: mb_substr($preview, 0, 140),
            data: [
                'type'              => 'chat.message',
                'conversation_uuid' => $conversation->getUuid(),
                'order_reference'   => $orderReference,
            ],
        );

        $context = [
            'event'             => 'chat.message',
            'conversation_uuid' => $conversation->getUuid(),
            'user_id'           => $recipient->getId(),
        ];

        foreach ($tokens as $deviceToken) {
            $this->sendOne($deviceToken, $message, $context);
        }
    }

    // -----------------------------------------------------------------
    // Marketing pushes (MUST respect marketing_push_opt_out)
    // -----------------------------------------------------------------

    /**
     * Abandoned-cart recovery nudge. Marketing, skipped if the cart
     * owner opted out of marketing push. Deep-links to /cart.
     * Never throws.
     */
    public function cartAbandoned(Cart $cart): void
    {
        $user = $cart->getUser();
        if ($user === null || $user->isMarketingPushOptedOut()) {
            return;
        }

        $tokens = $this->activeTokensFor($user);
        if ($tokens === []) {
            return;
        }

        $locale = $this->localeFor($user);
        if ($locale === User::LOCALE_AR) {
            $title = 'لقد تركت شيئًا في سلتك';
            $body  = 'سلة التسوق الخاصة بك في انتظارك. أكمل طلبك قبل نفاد المنتجات.';
        } else {
            $title = 'You left something in your cart';
            $body  = 'Your cart is waiting for you. Complete your order before it sells out.';
        }

        $message = new PushMessage(
            title: $title,
            body: $body,
            data: [
                'type'    => 'cart.abandoned',
                'cart_id' => (string) ($cart->getId() ?? ''),
            ],
        );

        $context = [
            'event'   => 'cart.abandoned',
            'cart_id' => $cart->getId(),
            'user_id' => $user->getId(),
        ];

        foreach ($tokens as $deviceToken) {
            $this->sendOne($deviceToken, $message, $context);
        }
    }

    /**
     * Post-delivery review prompt. Marketing, skipped if the customer
     * opted out of marketing push. Deep-links to /orders/{order_id}.
     * Never throws.
     */
    public function orderReviewPrompt(Order $order): void
    {
        $user = $order->getUser();
        if ($user->isMarketingPushOptedOut()) {
            return;
        }

        $tokens = $this->activeTokensFor($user);
        if ($tokens === []) {
            return;
        }

        $ref    = $order->getOrderReference();
        $locale = $this->localeFor($user);
        if ($locale === User::LOCALE_AR) {
            $title = 'كيف كان طلبك؟';
            $body  = sprintf('شارك رأيك في طلبك %s — تقييمك يساعد متسوقين آخرين.', $ref);
        } else {
            $title = 'How was your order?';
            $body  = sprintf('Tell us what you thought of order %s — your review helps other shoppers.', $ref);
        }

        $message = new PushMessage(
            title: $title,
            body: $body,
            data: [
                'type'            => 'order.review_prompt',
                'order_id'        => (string) ($order->getId() ?? ''),
                'order_reference' => $ref,
            ],
        );

        $context = [
            'event'    => 'order.review_prompt',
            'order_id' => $order->getId(),
            'user_id'  => $user->getId(),
        ];

        foreach ($tokens as $deviceToken) {
            $this->sendOne($deviceToken, $message, $context);
        }
    }

    /**
     * Generic re-engagement nudge for lapsed users. Marketing, skipped
     * if the user opted out of marketing push. No deep-link payload;
     * the app routes it to the home/shop tab. Never throws.
     */
    public function reEngagementNudge(User $user): void
    {
        if ($user->isMarketingPushOptedOut()) {
            return;
        }

        $tokens = $this->activeTokensFor($user);
        if ($tokens === []) {
            return;
        }

        $locale = $this->localeFor($user);
        if ($locale === User::LOCALE_AR) {
            $title = 'لقد اشتقنا إليك';
            $body  = 'وصلت منتجات جديدة إلى 3bayti. تصفح أحدث الإطلالات الآن.';
        } else {
            $title = 'We miss you';
            $body  = 'New arrivals just landed on 3bayti. Come see the latest styles.';
        }

        $message = new PushMessage(
            title: $title,
            body: $body,
            data: [
                'type' => 're_engagement.nudge',
            ],
        );

        $context = [
            'event'   => 're_engagement.nudge',
            'user_id' => $user->getId(),
        ];

        foreach ($tokens as $deviceToken) {
            $this->sendOne($deviceToken, $message, $context);
        }
    }

    // -----------------------------------------------------------------
    // Internals
    // -----------------------------------------------------------------

    /**
     * Build a localized lifecycle message and fan out to all of the
     * customer's active device tokens. Never throws.
     *
     * @param array<string, array{0: string, 1: string}> $copy
     *        Locale-keyed [title, body] pairs. Must contain at least an
     *        'en' entry; resolved against the customer's locale with an
     *        English fallback.
     */
    private function pushToCustomer(Order $order, string $type, array $copy): void
    {
        $user = $order->getUser();

        $tokens = $this->activeTokensFor($user);
        if ($tokens === []) {
            // Nothing to do, customer has no registered devices.
            return;
        }

        $locale = $this->localeFor($user);
        [$title, $body] = $copy[$locale] ?? $copy[User::LOCALE_EN];

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
     * Resolve the recipient User's short locale tag ('en' | 'ar') for
     * copy selection. Reuses the same normalization the email
     * LocaleResolver applies to User.locale (BCP-47 → short tag, region
     * stripped, unsupported → English).
     */
    private function localeFor(User $user): string
    {
        return LocaleResolver::normalizeToShortTag($user->getLocale());
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
