<?php

declare(strict_types=1);

namespace Bayti\Api\Messaging;

use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * Disabled default. Guarantees the container boots without any WhatsApp config
 * and that the conversation handler degrades to a no-op (logging what WOULD be
 * sent) when WHATSAPP_ENABLED is off — the same posture as
 * {@see \Bayti\Api\Sms\NullSmsSender}. Never throws.
 */
final class NullMessagingChannel implements MessagingChannelInterface
{
    private LoggerInterface $logger;

    public function __construct(?LoggerInterface $logger = null)
    {
        $this->logger = $logger ?? new NullLogger();
    }

    public function isEnabled(): bool
    {
        return false;
    }

    public function sendText(string $to, string $text): MessageResult
    {
        $this->logger->info('whatsapp.send_skipped', [
            'to' => $this->maskPhone($to),
            'length' => strlen($text),
        ]);
        return MessageResult::notSent();
    }

    private function maskPhone(string $phone): string
    {
        $len = strlen($phone);
        return $len <= 4 ? '***' : str_repeat('*', $len - 3) . substr($phone, -3);
    }
}
