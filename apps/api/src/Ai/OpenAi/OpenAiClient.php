<?php

declare(strict_types=1);

namespace Bayti\Api\Ai\OpenAi;

use Bayti\Api\Ai\AiException;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Exception\GuzzleException;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * Thin HTTP client for the OpenAI REST API (chat completions + embeddings +
 * image edits).
 *
 * Auth is a static bearer key (`OPENAI_API_KEY`), sent on every call and never
 * logged. Errors mirror the OtoClient posture: connect failures → AiException
 * (network); 401/403 → auth; 429 → rate_limited; other non-2xx → transport;
 * unparsable JSON → malformed. The Guzzle client is injected so tests drive it
 * with a MockHandler.
 *
 * Base URL is env-overridable (`OPENAI_BASE_URL`, default https://api.openai.com)
 * so an Azure/OpenAI-compatible gateway can be pointed at without code changes.
 */
final class OpenAiClient
{
    private const CHAT_PATH       = '/v1/chat/completions';
    private const EMBED_PATH      = '/v1/embeddings';
    private const IMAGE_EDIT_PATH = '/v1/images/edits';

    private LoggerInterface $logger;

    public function __construct(
        private readonly Client $http,
        private readonly string $baseUrl,
        private readonly string $apiKey,
        private readonly string $chatModel,
        private readonly string $embedModel,
        ?LoggerInterface $logger = null,
    ) {
        $this->logger = $logger ?? new NullLogger();
    }

    public function chatModel(): string
    {
        return $this->chatModel;
    }

    public function embedModel(): string
    {
        return $this->embedModel;
    }

    /**
     * @param array<string, mixed> $body
     * @return array<string, mixed>
     */
    public function chat(array $body): array
    {
        return $this->send(self::CHAT_PATH, ['json' => $body]);
    }

    /**
     * @param array<string, mixed> $body
     * @return array<string, mixed>
     */
    public function embeddings(array $body): array
    {
        return $this->send(self::EMBED_PATH, ['json' => $body]);
    }

    /**
     * Edit/compose images (multipart form-data), e.g. gpt-image-1 virtual
     * try-on. Each part is a Guzzle multipart entry
     * (['name' => ..., 'contents' => ..., 'filename' => ..., 'headers' => ...]).
     *
     * @param list<array<string, mixed>> $multipart
     * @return array<string, mixed>
     */
    public function imageEdit(array $multipart): array
    {
        return $this->send(self::IMAGE_EDIT_PATH, ['multipart' => $multipart]);
    }

    /**
     * Send a POST and map the response to a decoded array or a typed
     * AiException. $options carries exactly one body option ('json' or
     * 'multipart'); the auth header + http_errors handling are shared.
     *
     * @param array<string, mixed> $options
     * @return array<string, mixed>
     */
    private function send(string $path, array $options): array
    {
        try {
            $response = $this->http->post($this->baseUrl . $path, array_merge([
                'headers' => [
                    'Authorization' => 'Bearer ' . $this->apiKey,
                    'Accept' => 'application/json',
                ],
                'http_errors' => false,
            ], $options));
        } catch (ConnectException $e) {
            throw new AiException(AiException::KIND_NETWORK, $e->getMessage(), $e);
        } catch (GuzzleException $e) {
            throw new AiException(AiException::KIND_TRANSPORT, $e->getMessage(), $e);
        }

        $status = $response->getStatusCode();
        $raw = (string) $response->getBody();

        if ($status === 401 || $status === 403) {
            throw new AiException(AiException::KIND_AUTH, "OpenAI auth failed (HTTP {$status}).");
        }
        if ($status === 429) {
            throw new AiException(AiException::KIND_RATE_LIMITED, 'OpenAI rate limit reached.');
        }
        if ($status >= 400) {
            $this->logger->error('openai.request_failed', [
                'path' => $path,
                'status' => $status,
                'body' => mb_substr($raw, 0, 500),
            ]);
            throw new AiException(AiException::KIND_TRANSPORT, "OpenAI {$path} returned HTTP {$status}.");
        }

        return $this->decode($raw);
    }

    /**
     * @return array<string, mixed>
     */
    private function decode(string $raw): array
    {
        $decoded = json_decode($raw, true);
        if (!is_array($decoded)) {
            throw new AiException(
                AiException::KIND_MALFORMED,
                'OpenAI response was not a JSON object: ' . mb_substr($raw, 0, 200),
            );
        }
        /** @var array<string, mixed> $decoded */
        return $decoded;
    }
}
