<?php

declare(strict_types=1);

namespace Bayti\Api\Messaging;

/**
 * A failure sending through a messaging channel (WhatsApp today).
 *
 * `kind` lets callers/tests distinguish causes without string-matching the
 * message. Mirrors {@see \Bayti\Api\Shipping\ShippingException} /
 * {@see \Bayti\Api\Ai\AiException}. The WhatsApp conversation handler treats a
 * send failure as best-effort (logs, never breaks the webhook ACK).
 */
final class MessagingException extends \RuntimeException
{
    public const KIND_NOT_CONFIGURED = 'not_configured';
    public const KIND_NETWORK        = 'network';
    public const KIND_AUTH           = 'auth';
    public const KIND_RATE_LIMITED   = 'rate_limited';
    public const KIND_TRANSPORT      = 'transport';
    public const KIND_MALFORMED      = 'malformed';

    public function __construct(
        public readonly string $kind,
        string $message,
        ?\Throwable $previous = null,
    ) {
        parent::__construct($message, 0, $previous);
    }

    public static function notConfigured(): self
    {
        return new self(self::KIND_NOT_CONFIGURED, 'Messaging channel is not configured.');
    }
}
