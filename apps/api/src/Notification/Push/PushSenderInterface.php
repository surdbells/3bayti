<?php

declare(strict_types=1);

namespace Bayti\Api\Notification\Push;

/**
 * Domain-level abstraction for outbound push notifications.
 *
 * Implementations:
 *   - FcmHttpV1Sender: HTTPS to FCM HTTP v1 (production / prod-like).
 *                      FCM relays to APNs for iOS (Q-Z4=A), so a single
 *                      adapter covers both platforms.
 *   - NullPushSender:  structured logger only; no actual send (dev).
 *   - InMemoryPushSender: collects sends in an array (tests).
 *
 * Why an interface (vs. concrete FCM dependency):
 *   - Tests inject a fake to verify "we pushed X to token Y" without
 *     HTTP calls or FCM credentials.
 *   - Dev environments without a service account run with
 *     NullPushSender and see what WOULD be sent in logs.
 *   - Provider could change without touching PushNotificationService.
 *
 * Single-token send (not multicast): FCM's HTTP v1 send endpoint
 * targets one token per request. PushNotificationService fans out
 * across a user's active tokens by calling sendToToken per token, so
 * a single dead token can be pruned individually.
 */
interface PushSenderInterface
{
    /**
     * Deliver a push message to a single device token.
     *
     * @param string $token   The device's FCM registration token.
     * @param PushMessage $message  Title/body/data.
     * @param array<string, mixed> $context  Forwarded to structured
     *        logs for correlation (e.g. ['order_id' => 42,
     *        'event' => 'order.paid']).
     *
     * @throws PushException When the send fails. Callers catch this and
     *         log; they MUST NOT block the calling action on a push
     *         failure. A PushException with kind=UNREGISTERED signals
     *         the caller should deactivate the token.
     */
    public function sendToToken(
        string $token,
        PushMessage $message,
        array $context = [],
    ): void;
}
