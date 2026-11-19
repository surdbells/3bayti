<?php

declare(strict_types=1);

namespace Bayti\Api\Notification;

use Bayti\Api\Domain\Cashback\CashbackAward;
use Bayti\Api\Domain\GiftCard\GiftCard;
use Bayti\Api\Notification\Push\PushNotificationService;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * Tells the customer their cashback was paid out (push + email). Both
 * channels are fail-safe: a send failure is logged and never propagates,
 * the gift card is already issued and committed.
 */
class CashbackNotifier
{
    public function __construct(
        private readonly PushNotificationService $push,
        private readonly MailerInterface $mailer,
        private readonly GiftCardEmailTemplateRenderer $renderer,
        private readonly LoggerInterface $logger = new NullLogger(),
    ) {
    }

    public function cashbackIssued(CashbackAward $award, GiftCard $card): void
    {
        $orderReference = $award->getOrder()->getOrderReference();

        try {
            $this->push->cashbackIssued($card, $orderReference);
        } catch (\Throwable $e) {
            $this->logger->error('cashback.notify.push_failed', [
                'award_id' => $award->getId(),
                'error'    => $e->getMessage(),
            ]);
        }

        $user = $award->getUser();
        $to   = $user->getEmail();
        if ($to === '' || filter_var($to, FILTER_VALIDATE_EMAIL) === false) {
            return;
        }
        try {
            $rendered = $this->renderer->renderCashback($card, $orderReference, $user->getFirstName());
            $this->mailer->send(
                to: $to,
                subject: $rendered->subject,
                textBody: $rendered->textBody,
                htmlBody: $rendered->htmlBody,
                context: [
                    'template'     => 'cashback.issued',
                    'award_id'     => $award->getId(),
                    'gift_card_id' => $card->getId(),
                    'order_id'     => $award->getOrder()->getId(),
                ],
            );
        } catch (\Throwable $e) {
            $this->logger->error('cashback.notify.email_failed', [
                'award_id' => $award->getId(),
                'error'    => $e->getMessage(),
            ]);
        }
    }
}
