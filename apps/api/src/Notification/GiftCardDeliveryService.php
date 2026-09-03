<?php

declare(strict_types=1);

namespace Bayti\Api\Notification;

use Bayti\Api\Domain\GiftCard\GiftCard;
use Bayti\Api\Sms\SmsException;
use Bayti\Api\Sms\SmsSenderInterface;
use DateTimeImmutable;
use DateTimeZone;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * Delivers a gift card to its recipient over email and/or SMS.
 *
 * Mirrors OrderNotificationService::safeSend resilience:
 *   - each channel is rendered + sent inside its own try/catch
 *   - MailerException / SmsException / any \Throwable is caught + logged
 *   - delivery NEVER throws back to the caller (activation webhook +
 *     the cron must not fail because a notification failed)
 *
 * Idempotency
 * ===========
 * needsEmailDelivery() / needsSmsDelivery() guard each send; the
 * *_delivered_at timestamps (set by mark*Delivered()) make a second
 * deliver() call a no-op for any already-delivered channel. A channel
 * that FAILED its send leaves the timestamp null, so the scheduled-
 * delivery cron retries it on the next run (safety net).
 *
 * Persistence
 * ===========
 * After marking any channel delivered, deliver() flushes so the
 * timestamp is committed immediately, this preserves the idempotency
 * guarantee even if the process dies before the next flush.
 */
/**
 * Non-final so the cron command test can mock deliver() to assert
 * per-card dispatch + failure isolation (mirrors the
 * CartNotificationService mocking pattern in the cart-reminders test).
 */
class GiftCardDeliveryService
{
    private LoggerInterface $logger;

    public function __construct(
        private readonly GiftCardEmailTemplateRenderer $renderer,
        private readonly MailerInterface $mailer,
        private readonly SmsSenderInterface $smsSender,
        private readonly EntityManagerInterface $em,
        ?LoggerInterface $logger = null,
    ) {
        $this->logger = $logger ?? new NullLogger();
    }

    /**
     * Deliver the card over every pending channel. Idempotent +
     * non-blocking. Returns silently; failures are logged.
     */
    public function deliver(GiftCard $card): void
    {
        $changed = false;

        if ($card->needsEmailDelivery()) {
            $changed = $this->deliverEmail($card);
        }

        if ($card->needsSmsDelivery()) {
            $changed = $this->deliverSms($card) || $changed;
        }

        if ($changed) {
            try {
                $this->em->flush();
            } catch (\Throwable $e) {
                // Persisting the delivery markers failed. Log + continue.
                // The cron's needs* guards will retry next run (the
                // in-memory mark is lost on rollback, so this is safe).
                $this->logger->error('gift_card.delivery.flush_failed', [
                    'gift_card_id' => $card->getId(),
                    'error' => $e->getMessage(),
                    'class' => $e::class,
                ]);
            }
        }
    }

    /**
     * Deliver only if the card is DUE now, i.e. it has no future
     * scheduled-delivery date still pending. A future-dated card is
     * left for the gift-cards:dispatch-scheduled cron.
     *
     * This is the hook every activation path uses (the Noon payment
     * webhook, the status-poll activation fallback, and the admin
     * activate endpoint) so an immediately-purchased card reaches its
     * recipient the moment payment confirms, while scheduled cards wait
     * for their date. Uses the service's UTC now() so the comparison
     * matches the GiftCard domain's UTC convention.
     *
     * Idempotent + non-blocking, delegates to deliver().
     */
    public function deliverIfDue(GiftCard $card): void
    {
        $scheduledAt = $card->getScheduledDeliveryAt();
        if ($scheduledAt !== null && $scheduledAt > $this->now()) {
            return; // future-scheduled, the cron will deliver it
        }
        $this->deliver($card);
    }

    /**
     * Manually (re)send the card NOW to every channel that has a recipient
     * contact, regardless of prior delivery — the admin "Send to recipient"
     * action. Unlike deliver(), this bypasses the needs*Delivery() idempotency
     * guards, so a card can be re-sent (e.g. the recipient lost the original
     * email, or we're reaching out after enabling SMS).
     *
     * Returns a per-channel outcome so the caller can report exactly what
     * happened:
     *   - 'sent'           delivered + the *_delivered_at timestamp refreshed
     *   - 'failed'         a recipient exists but the send errored (logged)
     *   - 'not_configured' SMS only: no SMS provider is wired (NullSmsSender)
     *   - 'no_recipient'   no email / phone on file for that channel
     *
     * Non-blocking: the private deliver* helpers catch + log their own
     * failures, so this never throws.
     *
     * @return array{email: string, sms: string}
     */
    public function resend(GiftCard $card): array
    {
        $result = ['email' => 'no_recipient', 'sms' => 'no_recipient'];
        $sentAny = false;

        $email = $card->effectiveRecipientEmail();
        if ($email !== null && $email !== '') {
            $ok = $this->deliverEmail($card);
            $result['email'] = $ok ? 'sent' : 'failed';
            if ($ok) {
                $sentAny = true;
            }
        }

        $phone = $card->effectiveRecipientPhone();
        if ($phone !== null && $phone !== '') {
            if (!$this->smsSender->isEnabled()) {
                // Distinguish "no SMS provider wired" from a genuine failure so
                // the admin toast can say so instead of a scary "failed".
                $result['sms'] = 'not_configured';
            } else {
                $ok = $this->deliverSms($card);
                $result['sms'] = $ok ? 'sent' : 'failed';
                if ($ok) {
                    $sentAny = true;
                }
            }
        }

        if ($sentAny) {
            try {
                $this->em->flush();
            } catch (\Throwable $e) {
                $this->logger->error('gift_card.resend.flush_failed', [
                    'gift_card_id' => $card->getId(),
                    'error' => $e->getMessage(),
                    'class' => $e::class,
                ]);
            }
        }

        return $result;
    }

    /** @return bool true if the email was delivered + the card marked. */
    private function deliverEmail(GiftCard $card): bool
    {
        // Effective target: the buyer-provided delivery email, or the claimed
        // recipient account's email as a fallback. In the automatic deliver()
        // path this equals the stored email (that path is gated on it), so this
        // only widens reach for the manual resend / claimed-card case.
        $to = $card->effectiveRecipientEmail();
        if ($to === null || $to === '') {
            return false;
        }

        try {
            $rendered = $this->renderer->render($card);
            $this->mailer->send(
                to: $to,
                subject: $rendered->subject,
                textBody: $rendered->textBody,
                htmlBody: $rendered->htmlBody,
                context: [
                    'template' => 'gift_card.delivery',
                    'gift_card_id' => $card->getId(),
                ],
            );
            $card->markEmailDelivered($this->now());
            $this->logger->info('gift_card.delivery.email_sent', [
                'gift_card_id' => $card->getId(),
            ]);
            return true;
        } catch (MailerException $e) {
            $this->logger->error('gift_card.delivery.email_failed', [
                'gift_card_id' => $card->getId(),
                'kind' => $e->kind,
                'error' => $e->getMessage(),
            ]);
            return false;
        } catch (\Throwable $e) {
            $this->logger->error('gift_card.delivery.email_unexpected_error', [
                'gift_card_id' => $card->getId(),
                'error' => $e->getMessage(),
                'class' => $e::class,
            ]);
            return false;
        }
    }

    /** @return bool true if the SMS was delivered + the card marked. */
    private function deliverSms(GiftCard $card): bool
    {
        // Effective target: buyer-provided delivery phone, or the claimed
        // recipient account's phone as a fallback (see deliverEmail()).
        $to = $card->effectiveRecipientPhone();
        if ($to === null || $to === '') {
            return false;
        }

        // SMS not configured (NullSmsSender), record honestly as NOT delivered
        // instead of marking the channel delivered off a silent no-op. The card
        // stays pending on SMS, so once real SMS is enabled the scheduled
        // dispatcher (gift-cards:dispatch-scheduled) actually sends it.
        if (!$this->smsSender->isEnabled()) {
            $this->logger->info('gift_card.delivery.sms_skipped_not_configured', [
                'gift_card_id' => $card->getId(),
                'to' => $to,
            ]);
            return false;
        }

        try {
            $this->smsSender->send($to, $this->composeSms($card));
            $card->markSmsDelivered($this->now());
            $this->logger->info('gift_card.delivery.sms_sent', [
                'gift_card_id' => $card->getId(),
            ]);
            return true;
        } catch (SmsException $e) {
            $this->logger->error('gift_card.delivery.sms_failed', [
                'gift_card_id' => $card->getId(),
                'kind' => $e->kind,
                'error' => $e->getMessage(),
            ]);
            return false;
        } catch (\Throwable $e) {
            $this->logger->error('gift_card.delivery.sms_unexpected_error', [
                'gift_card_id' => $card->getId(),
                'error' => $e->getMessage(),
                'class' => $e::class,
            ]);
            return false;
        }
    }

    private function composeSms(GiftCard $card): string
    {
        return sprintf(
            'You received a 3bayti gift card worth %s %s! Code: %s. Redeem in the 3bayti app.',
            $card->getDenomination(),
            $card->getCurrency(),
            $card->formattedCode(),
        );
    }

    private function now(): DateTimeImmutable
    {
        return new DateTimeImmutable('now', new DateTimeZone('UTC'));
    }
}
