<?php

declare(strict_types=1);

namespace Bayti\Api\Domain\Chat;

/**
 * Outcome of an attempt to send a chat message. A delivered message has
 * been persisted and recorded on the conversation; a blocked message has
 * been persisted (for audit) but NOT delivered — the recipient never sees
 * it and their unread counter is untouched.
 */
final class SendResult
{
    private function __construct(
        public readonly bool $delivered,
        public readonly Message $message,
        public readonly ?ModerationResult $moderation = null,
    ) {
    }

    public static function delivered(Message $message): self
    {
        return new self(true, $message);
    }

    public static function blocked(Message $message, ModerationResult $moderation): self
    {
        return new self(false, $message, $moderation);
    }
}
