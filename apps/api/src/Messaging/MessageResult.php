<?php

declare(strict_types=1);

namespace Bayti\Api\Messaging;

/**
 * The outcome of an outbound messaging send. `sent` is false for the null
 * channel (messaging not configured); `messageId` is the provider's id when a
 * real send succeeded. Mirrors the provider-agnostic result DTOs
 * ({@see \Bayti\Api\Shipping\ShipmentResult}).
 */
final class MessageResult
{
    public function __construct(
        public readonly bool $sent,
        public readonly ?string $messageId = null,
    ) {
    }

    public static function notSent(): self
    {
        return new self(false, null);
    }
}
