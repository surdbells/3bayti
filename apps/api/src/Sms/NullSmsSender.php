<?php

declare(strict_types=1);

namespace Bayti\Api\Sms;

use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * No-op SMS sender — logs the intended send but performs no network
 * call. Used in dev/test, and as the PRODUCTION DEFAULT until the
 * operator confirms + enables the real MessageCentral SMS endpoint.
 *
 * This guarantees the container always boots even when no SMS provider
 * is configured: the delivery pipeline simply records what it would
 * have sent and marks the channel delivered (best-effort, non-blocking).
 *
 * The logger is nullable with a `?LoggerInterface = null` + null-coalesce
 * default (NOT a `= new NullLogger()` literal-object default) so the
 * class can be safely autowired in the PHP-DI COMPILED container.
 */
final class NullSmsSender implements SmsSenderInterface
{
    private LoggerInterface $logger;

    public function __construct(?LoggerInterface $logger = null)
    {
        $this->logger = $logger ?? new NullLogger();
    }

    public function send(string $toPhone, string $message): void
    {
        $this->logger->info('sms.null_sender.would_send', [
            'to' => $toPhone,
            'length' => strlen($message),
        ]);
    }

    /** No-op sender — never a real send, so callers don't mark a channel delivered. */
    public function isEnabled(): bool
    {
        return false;
    }
}
