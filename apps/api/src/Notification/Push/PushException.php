<?php

declare(strict_types=1);

namespace Bayti\Api\Notification\Push;

/**
 * Thrown when the underlying push transport fails for a single token
 * (network error, provider 4xx/5xx, etc.).
 *
 * Mirrors MailerException: callers MUST NOT block their action on
 * this — log + continue. A failed push is non-critical to order
 * processing.
 *
 * Kind helps operators triage AND lets the sender decide whether to
 * prune the token:
 *   - KIND_NETWORK:      connection / DNS / TLS; retry-safe, keep token
 *   - KIND_TRANSPORT:    provider rejected (generic 4xx/5xx)
 *   - KIND_RATE_LIMIT:   429; back off, keep token
 *   - KIND_AUTH:         401/403; misconfigured credentials, keep token
 *   - KIND_UNREGISTERED: the token is permanently invalid (the device
 *                        uninstalled / token rotated). The caller
 *                        should deactivate it so we stop targeting it.
 *   - KIND_UNKNOWN:      catch-all
 */
final class PushException extends \RuntimeException
{
    public const KIND_NETWORK = 'network';
    public const KIND_TRANSPORT = 'transport';
    public const KIND_RATE_LIMIT = 'rate_limit';
    public const KIND_AUTH = 'auth';
    public const KIND_UNREGISTERED = 'unregistered';
    public const KIND_UNKNOWN = 'unknown';

    public function __construct(
        public readonly string $kind,
        string $message,
        ?\Throwable $previous = null,
    ) {
        parent::__construct($message, 0, $previous);
    }

    /** True when the token should be deactivated (permanently dead). */
    public function isTokenDead(): bool
    {
        return $this->kind === self::KIND_UNREGISTERED;
    }
}
