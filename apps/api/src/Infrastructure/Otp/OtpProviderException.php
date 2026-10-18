<?php

declare(strict_types=1);

namespace Bayti\Api\Infrastructure\Otp;

/**
 * Raised when the CPaaS provider fails an operation that should
 * have succeeded, network errors, invalid auth, account out of
 * credits, malformed responses.
 *
 * NOT raised for "wrong code", that's a false return from verify()
 * because it's part of the normal user flow, not a failure.
 *
 * Callers (typically OtpService) should treat this as a hard 5xx
 * for the user. Don't retry within the request, the OtpAttempt
 * row stays uncommitted (the OtpService wraps the send in a
 * transaction) so a retry produces a fresh OTP.
 */
final class OtpProviderException extends \RuntimeException
{
    /**
     * The machine-readable failure kind the provider raised ('upstream',
     * 'unauthorized', 'network', 'transport', 'malformed', 'missing_id',
     * 'unsupported_country', 'no_provider', ...). Exposed so callers (e.g. the
     * OtpProviderFailureClassifier) don't have to parse it back out of the
     * message, which is always "<kind>: <detail>" (or just "<kind>").
     */
    public readonly string $kind;

    public function __construct(
        string $reason,
        string $detail = '',
        ?\Throwable $previous = null,
    ) {
        $this->kind = $reason;
        $message = $detail !== '' ? "{$reason}: {$detail}" : $reason;
        parent::__construct($message, 0, $previous);
    }
}
