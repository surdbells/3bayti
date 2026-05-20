<?php

declare(strict_types=1);

namespace Bayti\Api\Notification\Push;

/**
 * An outbound push notification's content, transport-agnostic.
 *
 * Maps onto FCM's HTTP v1 `message.notification` (title/body) plus
 * `message.data` (a string→string map delivered to the app for deep
 * linking and client-side handling).
 *
 * FCM requires data values to be strings; the constructor enforces
 * that shape so callers can't accidentally pass non-strings that FCM
 * would reject at send time.
 */
final class PushMessage
{
    /**
     * @param string $title Notification title (visible).
     * @param string $body  Notification body (visible).
     * @param array<string, string> $data Custom key/value payload for
     *        the app (e.g. ['type' => 'order.paid', 'order_id' => '42']).
     *        Used for deep-linking from a tapped notification. All
     *        values MUST be strings (FCM contract).
     */
    public function __construct(
        public readonly string $title,
        public readonly string $body,
        public readonly array $data = [],
    ) {
    }
}
