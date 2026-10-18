<?php

declare(strict_types=1);

namespace Bayti\Api\Tests\Support\Otp;

use Bayti\Api\Infrastructure\Otp\OtpProviderIncidentReporter;

/**
 * OtpProviderIncidentReporter double: records every report (stands in for
 * Sentry), optionally throwing to prove the monitor survives a Sentry outage.
 */
final class RecordingOtpIncidentReporter implements OtpProviderIncidentReporter
{
    /**
     * @var list<array{provider: string, reason: string, message: string, customersBlocked: bool, details: array<string, mixed>, test: bool}>
     */
    public array $reports = [];

    public function __construct(private readonly bool $throw = false)
    {
    }

    public function reportAccountFailure(
        string $provider,
        string $reason,
        string $message,
        bool $customersBlocked,
        array $details = [],
        bool $test = false,
    ): void {
        if ($this->throw) {
            throw new \RuntimeException('sentry transport down');
        }
        $this->reports[] = [
            'provider' => $provider,
            'reason' => $reason,
            'message' => $message,
            'customersBlocked' => $customersBlocked,
            'details' => $details,
            'test' => $test,
        ];
    }
}
