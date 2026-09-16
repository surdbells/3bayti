<?php

declare(strict_types=1);

namespace Bayti\Api\Messaging\WhatsApp;

use Bayti\Api\Messaging\MessagingException;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Exception\GuzzleException;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * Thin HTTP client for the Meta WhatsApp Cloud API (Graph API).
 *
 * Auth is a static bearer token (`WHATSAPP_ACCESS_TOKEN`), sent on every call
 * and never logged. Errors mirror the OpenAiClient posture: connect failures →
 * MessagingException(network); 401/403 → auth; 429 → rate_limited; other non-2xx
 * → transport; unparsable JSON → malformed. The Guzzle client is injected so
 * tests drive it with a MockHandler.
 *
 * Base URL is env-overridable (`WHATSAPP_BASE_URL`, default
 * https://graph.facebook.com/v21.0) so the Graph version can be pinned without a
 * code change.
 */
final class WhatsAppCloudClient
{
    private LoggerInterface $logger;

    public function __construct(
        private readonly Client $http,
        private readonly string $baseUrl,
        private readonly string $phoneNumberId,
        private readonly string $accessToken,
        ?LoggerInterface $logger = null,
    ) {
        $this->logger = $logger ?? new NullLogger();
    }

    /**
     * POST a message payload to /{phoneNumberId}/messages.
     *
     * @param array<string, mixed> $body
     * @return array<string, mixed>
     */
    public function postMessage(array $body): array
    {
        $path = '/' . rawurlencode($this->phoneNumberId) . '/messages';

        try {
            $response = $this->http->post($this->baseUrl . $path, [
                'headers' => [
                    'Authorization' => 'Bearer ' . $this->accessToken,
                    'Accept' => 'application/json',
                ],
                'json' => $body,
                'http_errors' => false,
            ]);
        } catch (ConnectException $e) {
            throw new MessagingException(MessagingException::KIND_NETWORK, $e->getMessage(), $e);
        } catch (GuzzleException $e) {
            throw new MessagingException(MessagingException::KIND_TRANSPORT, $e->getMessage(), $e);
        }

        $status = $response->getStatusCode();
        $raw = (string) $response->getBody();

        if ($status === 401 || $status === 403) {
            throw new MessagingException(MessagingException::KIND_AUTH, "WhatsApp auth failed (HTTP {$status}).");
        }
        if ($status === 429) {
            throw new MessagingException(MessagingException::KIND_RATE_LIMITED, 'WhatsApp rate limit reached.');
        }
        if ($status >= 400) {
            $this->logger->error('whatsapp.request_failed', [
                'path' => $path,
                'status' => $status,
                // Meta error bodies can echo the recipient wa_id; redact long
                // digit runs so a customer's number never lands in the logs.
                'body' => preg_replace('/\d{7,}/', '[redacted]', mb_substr($raw, 0, 500)),
            ]);
            throw new MessagingException(MessagingException::KIND_TRANSPORT, "WhatsApp {$path} returned HTTP {$status}.");
        }

        $decoded = json_decode($raw, true);
        if (!is_array($decoded)) {
            throw new MessagingException(
                MessagingException::KIND_MALFORMED,
                'WhatsApp response was not a JSON object: ' . mb_substr($raw, 0, 200),
            );
        }
        /** @var array<string, mixed> $decoded */
        return $decoded;
    }
}
