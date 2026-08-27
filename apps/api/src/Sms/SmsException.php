<?php

declare(strict_types=1);

namespace Bayti\Api\Sms;

/**
 * Thrown when the underlying SMS transport fails (network error,
 * provider 4xx/5xx, invalid recipient, etc.).
 *
 * Mirrors MailerException: callers MUST NOT block their action on this
 *, log + continue. A failed gift-card delivery SMS is annoying but the
 * card itself is still valid and the buyer can share the code manually.
 *
 * Kind helps operators triage:
 *   - KIND_NETWORK:   connection / DNS / TLS issue; retry-safe
 *   - KIND_TRANSPORT: provider rejected (4xx); recipient or
 *                     credentials issue; needs human investigation
 *   - KIND_UNAUTHORIZED: auth/token failure from the provider
 *   - KIND_MALFORMED: provider returned an unparsable response
 *   - KIND_DISABLED:  the SMS channel is not configured/enabled
 *   - KIND_UNKNOWN:   catch-all
 */
final class SmsException extends \RuntimeException
{
    public const KIND_NETWORK = 'network';
    public const KIND_TRANSPORT = 'transport';
    public const KIND_UNAUTHORIZED = 'unauthorized';
    public const KIND_MALFORMED = 'malformed';
    public const KIND_DISABLED = 'disabled';
    public const KIND_UNKNOWN = 'unknown';

    public function __construct(
        public readonly string $kind,
        string $message,
        ?\Throwable $previous = null,
    ) {
        parent::__construct($message, 0, $previous);
    }
}
