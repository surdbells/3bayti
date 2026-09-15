<?php

declare(strict_types=1);

namespace Bayti\Api\Ai;

/**
 * A failure talking to the AI provider (intent parsing, ranking, embeddings).
 *
 * `kind` lets callers/tests distinguish causes without string-matching the
 * message — a caller precondition (provider not configured) vs. a genuine
 * upstream/network failure. Mirrors {@see \Bayti\Api\Shipping\ShippingException}.
 *
 * The concierge pipeline treats every kind as "AI unavailable → degrade to the
 * deterministic keyword/filter path" rather than surfacing a 500 to the shopper,
 * so a bad model call never breaks browsing.
 */
final class AiException extends \RuntimeException
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
        return new self(self::KIND_NOT_CONFIGURED, 'AI provider is not configured.');
    }
}
