<?php

declare(strict_types=1);

namespace Bayti\Api\Infrastructure\Otp;

/**
 * Reports an SMS-provider ACCOUNT incident to error tracking (Sentry in
 * production — SentryOtpProviderIncidentReporter). An interface so the health
 * monitor can be unit-tested without the global Sentry hub.
 */
interface OtpProviderIncidentReporter
{
    /**
     * Report one occurrence. Implementations group every occurrence of the same
     * provider + reason into ONE issue (stable fingerprint).
     *
     * @param string               $message          human summary, constant per provider + reason
     * @param bool                 $customersBlocked true when no backup provider delivered the code
     * @param array<string, mixed> $details          extra, already-redacted context (never customer data)
     * @param bool                 $test             a drill from `otp:test-alert`: grouped separately
     */
    public function reportAccountFailure(
        string $provider,
        string $reason,
        string $message,
        bool $customersBlocked,
        array $details = [],
        bool $test = false,
    ): void;
}
