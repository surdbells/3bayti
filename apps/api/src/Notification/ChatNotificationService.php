<?php

declare(strict_types=1);

namespace Bayti\Api\Notification;

use Bayti\Api\Domain\Chat\Conversation;
use Bayti\Api\Domain\Chat\Message;
use Bayti\Api\Domain\Notification\NotificationLog;
use Bayti\Api\Domain\User\User;
use Bayti\Api\Notification\Push\PushNotificationService;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * Notifies the recipient of a delivered chat message by email + push,
 * debounced per party so an active back-and-forth pings once per burst
 * rather than on every message. The recipient party is the OPPOSITE of the
 * sender (customer sends → vendor is notified). Fire-and-forget: a mailer
 * or push failure is logged, never propagated, so it can't break the send.
 *
 * Not final so it can be substituted with a no-op in controller tests that
 * exercise the send path without real email/push.
 */
class ChatNotificationService
{
    /** Debounce window: at most one notification per recipient per 10 minutes (until they read). */
    public const WINDOW_SECONDS = 600;
    private const TEMPLATE = 'chat.message';

    public function __construct(
        private readonly MailerInterface $mailer,
        private readonly PushNotificationService $push,
        private readonly EntityManagerInterface $em,
        private readonly LoggerInterface $logger = new NullLogger(),
    ) {
    }

    /**
     * @param Conversation::PARTY_CUSTOMER|Conversation::PARTY_VENDOR $recipientParty
     */
    public function maybeNotify(Conversation $conversation, string $recipientParty, Message $message): void
    {
        $now = new \DateTimeImmutable();
        if (!$conversation->shouldNotify($recipientParty, $now, self::WINDOW_SECONDS)) {
            return;
        }

        [$recipient, $counterpartyName] = $this->resolve($conversation, $recipientParty);
        if (!$recipient instanceof User || $recipient->getEmail() === '') {
            return;
        }

        // Stamp the debounce anchor up front so a slow/failing mailer can't
        // cause a retry storm; reading the thread will re-arm it.
        $conversation->markNotified($recipientParty, $now);

        $preview = $message->getContent();
        $this->sendEmail($recipient, $counterpartyName, $conversation, $preview);

        try {
            $this->push->chatMessage($recipient, $conversation, $counterpartyName, $preview);
        } catch (\Throwable $e) {
            $this->logger->error('chat.notify.push_failed', [
                'conversation_uuid' => $conversation->getUuid(),
                'error'             => $e->getMessage(),
            ]);
        }

        $this->em->flush();
    }

    /**
     * @return array{0: ?User, 1: string} [recipient user, counterparty display name]
     */
    private function resolve(Conversation $conversation, string $recipientParty): array
    {
        if ($recipientParty === Conversation::PARTY_CUSTOMER) {
            // Customer is notified; the counterparty is the vendor.
            return [$conversation->getCustomer(), $conversation->getVendor()->getName()];
        }

        // Vendor is notified; the counterparty is the customer.
        $customer = $conversation->getCustomer();
        $name = trim(($customer->getFirstName() ?? '') . ' ' . ($customer->getLastName() ?? ''));
        return [$conversation->getVendor()->getOwnerUser(), $name !== '' ? $name : 'A customer'];
    }

    private function sendEmail(User $recipient, string $counterpartyName, Conversation $conversation, string $preview): void
    {
        $to = $recipient->getEmail();
        $orderReference = $conversation->getOrder()->getOrderReference();
        $subject = "New message about order {$orderReference}";
        $snippet = mb_substr(trim($preview), 0, 160);

        $textBody = "{$counterpartyName} sent you a message about order {$orderReference}:\n\n"
            . "\"{$snippet}\"\n\n"
            . "Open the 3bayti app to reply. For your protection, keep the conversation in the app.";

        $safeName = htmlspecialchars($counterpartyName, ENT_QUOTES);
        $safeRef = htmlspecialchars($orderReference, ENT_QUOTES);
        $safeSnippet = htmlspecialchars($snippet, ENT_QUOTES);
        $htmlBody = "<p><strong>{$safeName}</strong> sent you a message about order <strong>{$safeRef}</strong>:</p>"
            . "<blockquote>{$safeSnippet}</blockquote>"
            . "<p>Open the 3bayti app to reply. For your protection, please keep the conversation in the app.</p>";

        $orderId = $conversation->getOrder()->getId();
        $orderId = $orderId !== null ? (int) $orderId : null;

        try {
            $this->mailer->send(
                to: $to,
                subject: $subject,
                textBody: $textBody,
                htmlBody: $htmlBody,
                context: [
                    'template'          => self::TEMPLATE,
                    'conversation_uuid' => $conversation->getUuid(),
                    'order_reference'   => $orderReference,
                ],
            );
            $this->em->persist(NotificationLog::sent($orderId, self::TEMPLATE, $to));
        } catch (MailerException $e) {
            $this->logger->error('chat.notify.email_failed', [
                'conversation_uuid' => $conversation->getUuid(),
                'kind'              => $e->kind,
                'error'             => $e->getMessage(),
            ]);
            $this->em->persist(NotificationLog::failed($orderId, self::TEMPLATE, $to, $e->kind, $e->getMessage()));
        } catch (\Throwable $e) {
            $this->logger->error('chat.notify.email_unexpected', [
                'conversation_uuid' => $conversation->getUuid(),
                'error'             => $e->getMessage(),
            ]);
            $this->em->persist(NotificationLog::failed($orderId, self::TEMPLATE, $to, $e::class, $e->getMessage()));
        }
    }
}
