<?php

declare(strict_types=1);

namespace Bayti\Api\Notification;

/**
 * Domain-level abstraction for outbound email.
 *
 * Implementations:
 *   - ZeptoMailHttpMailer: HTTPS to api.zeptomail.com (production / prod-like)
 *   - NullMailer:          structured logger only; no actual send (dev/test)
 *   - InMemoryMailer:      collects sends in an array (tests)
 *
 * Why an interface (vs. concrete dependency on ZeptoMail):
 *   - We may switch providers later (SendGrid, Postmark, etc.) without
 *     touching the notification services
 *   - Tests inject a fake to verify "we sent X to Y" without making
 *     HTTP calls or maintaining mock SMTP infrastructure
 *   - Dev environments without API tokens can run with NullMailer and
 *     see what WOULD be sent in operational logs
 */
interface MailerInterface
{
    /**
     * Send an email to a single recipient.
     *
     * @param string $to             Recipient email address (must validate).
     * @param string $subject        Subject line.
     * @param string $textBody       Plain-text body (always required for
     *                               accessibility, spam filters, and recipients
     *                               who block HTML).
     * @param string $htmlBody       HTML body (rendered version).
     * @param array<string, mixed> $context  Forwarded to structured logs for
     *                               correlation (e.g. ['order_id' => 42,
     *                               'template' => 'order.confirmed']).
     *
     * @throws MailerException When the underlying transport fails. Callers
     *         catch this and log; they MUST NOT block the calling action
     *         (e.g. order placement) on an email failure.
     */
    public function send(
        string $to,
        string $subject,
        string $textBody,
        string $htmlBody,
        array $context = [],
    ): void;
}
