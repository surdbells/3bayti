<?php

declare(strict_types=1);

namespace Bayti\Api\Http\Controllers\Channels;

use Bayti\Api\Http\Responder;
use Bayti\Api\Infrastructure\Cache\KeyValueStore;
use Bayti\Api\Infrastructure\Cache\KeyValueStoreException;
use Bayti\Api\Messaging\WhatsApp\MetaWhatsAppWebhookVerifier;
use Bayti\Api\Messaging\WhatsApp\WhatsAppConversationService;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Log\LoggerInterface;

/**
 * POST /v3/channels/whatsapp/webhook
 *
 * Inbound Meta WhatsApp events. Every inbound TEXT message is run through the
 * Ain concierge and answered with product cards + deep links. Delivery-status
 * callbacks and non-text messages are ignored.
 *
 * INTENTIONALLY UNAUTHENTICATED (Meta has no 3bayti JWT). Safety: the raw body
 * is HMAC-verified against WHATSAPP_APP_SECRET (X-Hub-Signature-256) BEFORE it
 * is decoded or acted on — an unset secret or a bad signature is 401. Every
 * recognised/unrecognised valid event is ACKed 200 so Meta stops retrying.
 *
 * NEVER add AuthMiddleware to this route.
 */
final class WhatsAppWebhookController
{
    use Responder;

    /** How long a processed message id is remembered to drop Meta's retries. */
    private const SEEN_TTL_SECONDS = 86400;

    public function __construct(
        protected readonly ResponseFactoryInterface $responseFactory,
        private readonly MetaWhatsAppWebhookVerifier $verifier,
        private readonly WhatsAppConversationService $conversation,
        private readonly KeyValueStore $cache,
        private readonly LoggerInterface $logger,
    ) {
    }

    protected function getResponseFactory(): ResponseFactoryInterface
    {
        return $this->responseFactory;
    }

    public function __invoke(ServerRequestInterface $request): ResponseInterface
    {
        // Verify the HMAC over the EXACT raw bytes, before json_decode.
        $raw = (string) $request->getBody();
        if (!$this->verifier->verifyEvent($raw, $request->getHeaderLine('X-Hub-Signature-256'))) {
            $this->logger->warning('whatsapp.webhook.unauthorized');
            $response = $this->responseFactory->createResponse(401);
            $response->getBody()->write((string) json_encode(['error' => 'unauthorized']));
            return $response->withHeader('Content-Type', 'application/json');
        }

        $body = json_decode($raw, true);
        if (!is_array($body)) {
            return $this->ok(['ignored' => 'invalid_json']);
        }

        foreach ($this->extractTextMessages($body) as [$id, $from, $text]) {
            // Idempotency: Meta re-delivers an event until it sees a 200, and the
            // heavy concierge+send work runs before the ACK, so a slow reply can
            // race the deadline. Claim the message id once — a retry then no-ops
            // instead of re-running the LLM + re-sending a paid reply.
            if (!$this->claim($id)) {
                continue;
            }
            try {
                $this->conversation->handleInbound($from, $text);
            } catch (\Throwable $e) {
                $this->logger->warning('whatsapp.handle_failed', ['error' => $e->getMessage()]);
            }
        }

        return $this->ok(['received' => true]);
    }

    /**
     * Pull [id, from, text] triples out of the Cloud API payload
     * (entry[].changes[].value.messages[] where type === 'text'). The id is the
     * wamid used for retry de-duplication.
     *
     * @param array<string, mixed> $body
     * @return list<array{0: string, 1: string, 2: string}>
     */
    private function extractTextMessages(array $body): array
    {
        $out = [];
        $entries = $body['entry'] ?? null;
        if (!is_array($entries)) {
            return $out;
        }
        foreach ($entries as $entry) {
            $changes = is_array($entry) ? ($entry['changes'] ?? null) : null;
            if (!is_array($changes)) {
                continue;
            }
            foreach ($changes as $change) {
                $value = is_array($change) ? ($change['value'] ?? null) : null;
                $messages = is_array($value) ? ($value['messages'] ?? null) : null;
                if (!is_array($messages)) {
                    continue;
                }
                foreach ($messages as $message) {
                    if (!is_array($message) || ($message['type'] ?? null) !== 'text') {
                        continue;
                    }
                    $id = $message['id'] ?? null;
                    $from = $message['from'] ?? null;
                    $textBlock = $message['text'] ?? null;
                    $textBody = is_array($textBlock) ? ($textBlock['body'] ?? null) : null;
                    if (
                        is_string($id) && $id !== ''
                        && is_string($from) && $from !== ''
                        && is_string($textBody) && $textBody !== ''
                    ) {
                        $out[] = [$id, $from, $textBody];
                    }
                }
            }
        }
        return $out;
    }

    /**
     * Atomically claim a message id for processing (SET NX). Returns false when
     * it was already processed (a retry) so the caller skips it. Fails OPEN on a
     * cache error — a rare double-process during a Redis outage is preferable to
     * dropping a real message, and the per-number rate limit still caps cost.
     */
    private function claim(string $messageId): bool
    {
        try {
            return $this->cache->setIfAbsent('wa:seen:' . $messageId, '1', self::SEEN_TTL_SECONDS);
        } catch (KeyValueStoreException $e) {
            $this->logger->warning('whatsapp.dedup_cache_failure — processing', ['error' => $e->getMessage()]);
            return true;
        }
    }
}
