<?php

declare(strict_types=1);

namespace Bayti\Api\Domain\Chat;

use Bayti\Api\Domain\User\User;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Sends a customer or vendor chat message through PII moderation. A clean
 * message is persisted and recorded on the conversation (bumping the
 * recipient's unread counter); a message that trips the moderator is
 * persisted in a BLOCKED state for audit but is never delivered, keeping
 * personal contact details out of the thread without silently dropping the
 * attempt. HTTP concerns stay in the controller: this returns a SendResult
 * and never throws for a block.
 */
final class ChatMessageSender
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly ModerationService $moderation,
    ) {
    }

    /**
     * @param Conversation::PARTY_CUSTOMER|Conversation::PARTY_VENDOR $senderParty
     */
    public function send(Conversation $conversation, User $sender, string $senderParty, string $content): SendResult
    {
        $result = $this->moderation->check($content);

        $message = match ($senderParty) {
            Conversation::PARTY_CUSTOMER => Message::fromCustomer($conversation, $sender, $content),
            Conversation::PARTY_VENDOR   => Message::fromVendor($conversation, $sender, $content),
            default => throw new \InvalidArgumentException("Unsupported sender party: {$senderParty}"),
        };

        if ($result->isFlagged) {
            $message->block((string) $result->flagTypeString());
            $this->em->persist($message);
            $this->em->flush();
            return SendResult::blocked($message, $result);
        }

        $this->em->persist($message);
        $conversation->recordMessage($senderParty, $content);
        $this->em->flush();

        return SendResult::delivered($message);
    }

    /**
     * Send a curated quick-start prompt (P1). The text comes from the seeded
     * catalog, so it skips PII moderation (it can't contain contact details);
     * always delivered.
     *
     * @param Conversation::PARTY_CUSTOMER|Conversation::PARTY_VENDOR $senderParty
     */
    public function sendPrompt(Conversation $conversation, User $sender, string $senderParty, ChatPrompt $prompt): SendResult
    {
        $message = $senderParty === Conversation::PARTY_CUSTOMER
            ? Message::fromCustomerPrompt($conversation, $sender, $prompt)
            : Message::fromVendorPrompt($conversation, $sender, $prompt);

        $this->em->persist($message);
        $conversation->recordMessage($senderParty, $prompt->getText());
        $this->em->flush();

        return SendResult::delivered($message);
    }
}
