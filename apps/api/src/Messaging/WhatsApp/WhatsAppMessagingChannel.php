<?php

declare(strict_types=1);

namespace Bayti\Api\Messaging\WhatsApp;

use Bayti\Api\Messaging\MessageResult;
use Bayti\Api\Messaging\MessagingChannelInterface;

/**
 * Real WhatsApp channel over the Cloud API client. Selected in DI only when
 * WHATSAPP_ENABLED=true and the token + phone-number id are configured.
 */
final class WhatsAppMessagingChannel implements MessagingChannelInterface
{
    public function __construct(
        private readonly WhatsAppCloudClient $client,
    ) {
    }

    public function isEnabled(): bool
    {
        return true;
    }

    public function sendText(string $to, string $text): MessageResult
    {
        $response = $this->client->postMessage([
            'messaging_product' => 'whatsapp',
            'recipient_type' => 'individual',
            'to' => $this->normalise($to),
            'type' => 'text',
            'text' => [
                'preview_url' => true,
                'body' => $text,
            ],
        ]);

        return new MessageResult(true, $this->extractMessageId($response));
    }

    /** WhatsApp wants the E.164 number WITHOUT the leading '+'. */
    private function normalise(string $to): string
    {
        return ltrim(trim($to), '+');
    }

    /**
     * @param array<string, mixed> $response
     */
    private function extractMessageId(array $response): ?string
    {
        $messages = $response['messages'] ?? null;
        if (is_array($messages) && isset($messages[0]) && is_array($messages[0])) {
            $id = $messages[0]['id'] ?? null;
            if (is_string($id) && $id !== '') {
                return $id;
            }
        }
        return null;
    }
}
