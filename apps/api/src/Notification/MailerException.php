<?php

declare(strict_types=1);

namespace Bayti\Api\Notification;

/**
 * Thrown when the underlying mail transport fails (network error,
 * provider 4xx/5xx, invalid recipient, etc.).
 *
 * Callers MUST NOT block their action on this — log + continue.
 * A failed order confirmation email is annoying for the customer
 * but the order itself is still valid.
 *
 * Kind helps operators triage:
 *   - KIND_NETWORK: connection / DNS / TLS issue; retry-safe
 *   - KIND_TRANSPORT: provider rejected (4xx); recipient or
 *                     credentials issue; needs human investigation
 *   - KIND_RATE_LIMIT: 429; back off and retry later
 *   - KIND_AUTH: 401/403 from provider; misconfigured API token
 *   - KIND_UNKNOWN: catch-all
 */
final class MailerException extends \RuntimeException
{
    public const KIND_NETWORK = 'network';
    public const KIND_TRANSPORT = 'transport';
    public const KIND_RATE_LIMIT = 'rate_limit';
    public const KIND_AUTH = 'auth';
    public const KIND_UNKNOWN = 'unknown';

    public function __construct(
        public readonly string $kind,
        string $message,
        ?\Throwable $previous = null,
    ) {
        parent::__construct($message, 0, $previous);
    }
}
