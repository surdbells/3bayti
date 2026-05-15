<?php

declare(strict_types=1);

namespace Bayti\Api\Notification;

use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * MailerInterface implementation that logs the would-be send to
 * structured logs instead of making an HTTP call.
 *
 * Bound in dev/staging when ZEPTOMAIL_API_TOKEN is unset, so
 * developers see "what WOULD have been sent" in their logs without
 * having to provision real credentials. Also the default in tests.
 */
final class NullMailer implements MailerInterface
{
    public function __construct(
        private readonly LoggerInterface $logger = new NullLogger(),
    ) {
    }

    public function send(
        string $to,
        string $subject,
        string $textBody,
        string $htmlBody,
        array $context = [],
    ): void {
        $this->logger->info('mail.would_send', array_merge($context, [
            'to' => $to,
            'subject' => $subject,
            'text_body_length' => strlen($textBody),
            'html_body_length' => strlen($htmlBody),
        ]));
    }
}
