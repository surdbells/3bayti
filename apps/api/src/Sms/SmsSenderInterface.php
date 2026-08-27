<?php

declare(strict_types=1);

namespace Bayti\Api\Sms;

/**
 * Domain-level abstraction for outbound transactional SMS.
 *
 * Implementations:
 *   - MessageCentralSmsSender: HTTPS to MessageCentral CPaaS (prod,
 *     env-gated, only selected when explicitly enabled + configured)
 *   - NullSmsSender:           structured logger only; no actual send
 *                              (dev/test default, and the prod default
 *                              until the operator confirms the endpoint)
 *
 * Why an interface (mirrors MailerInterface):
 *   - We may switch SMS providers later without touching callers
 *   - Tests inject a fake to verify "we sent X to Y" without HTTP
 *   - Dev/prod-without-SMS-config run with NullSmsSender and see what
 *     WOULD be sent in operational logs, never breaking the boot
 */
interface SmsSenderInterface
{
    /**
     * Send a single SMS.
     *
     * @param string $toPhone Recipient phone (E.164-ish, e.g. '+971501234567').
     * @param string $message The message text.
     *
     * @throws SmsException When the underlying transport fails. Callers
     *         catch this and log; they MUST NOT block the calling action
     *         (e.g. gift-card activation) on an SMS failure.
     */
    public function send(string $toPhone, string $message): void;

    /**
     * Whether this sender performs REAL sends. False only for the no-op
     * NullSmsSender (SMS not configured / not enabled).
     *
     * Callers use this to record an unsent SMS honestly, as NOT delivered
     * (pending), instead of marking the channel delivered off a silent no-op.
     * A channel left pending is retried once real SMS is enabled.
     */
    public function isEnabled(): bool;
}
