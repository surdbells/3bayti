<?php

declare(strict_types=1);

namespace Bayti\Api\Ai\OpenAi;

use Bayti\Api\Ai\AiException;
use Bayti\Api\Ai\AiProviderInterface;

/**
 * OpenAI implementation of {@see AiProviderInterface}. Selected by the DI
 * container only when AI_ENABLED=true + an API key is present; otherwise the
 * NullAiProvider is used.
 *
 * completeJson uses OpenAI structured outputs (response_format json_schema) so
 * the model returns a single object matching the caller's schema. embed batches
 * all inputs into one embeddings call and returns the vectors in input order.
 */
final class OpenAiProvider implements AiProviderInterface
{
    /** Low temperature: intent parsing + ranking want determinism, not creativity. */
    private const TEMPERATURE = 0.2;

    public function __construct(private readonly OpenAiClient $client)
    {
    }

    public function isEnabled(): bool
    {
        return true;
    }

    public function completeJson(string $system, string $user, array $schema, string $schemaName = 'result'): array
    {
        $response = $this->client->chat([
            'model' => $this->client->chatModel(),
            'temperature' => self::TEMPERATURE,
            'messages' => [
                ['role' => 'system', 'content' => $system],
                ['role' => 'user', 'content' => $user],
            ],
            'response_format' => [
                'type' => 'json_schema',
                'json_schema' => [
                    'name' => $schemaName,
                    'schema' => $schema,
                    'strict' => false,
                ],
            ],
        ]);

        $content = $response['choices'][0]['message']['content'] ?? null;
        if (!is_string($content) || $content === '') {
            throw new AiException(AiException::KIND_MALFORMED, 'OpenAI chat response had no content.');
        }

        $decoded = json_decode($content, true);
        if (!is_array($decoded)) {
            throw new AiException(AiException::KIND_MALFORMED, 'OpenAI content was not a JSON object.');
        }

        /** @var array<string, mixed> $decoded */
        return $decoded;
    }

    public function embed(array $texts): array
    {
        if ($texts === []) {
            return [];
        }

        $response = $this->client->embeddings([
            'model' => $this->client->embedModel(),
            'input' => $texts,
        ]);

        $rows = $response['data'] ?? null;
        if (!is_array($rows) || count($rows) !== count($texts)) {
            throw new AiException(AiException::KIND_MALFORMED, 'OpenAI embeddings response was incomplete.');
        }

        // Restore input order (the API returns each row's `index`) and extract vectors.
        $out = array_fill(0, count($texts), []);
        foreach ($rows as $row) {
            if (!is_array($row) || !isset($row['embedding']) || !is_array($row['embedding'])) {
                throw new AiException(AiException::KIND_MALFORMED, 'OpenAI embeddings row was malformed.');
            }
            $index = is_int($row['index'] ?? null) ? $row['index'] : null;
            $vector = array_map(static fn ($v): float => (float) $v, array_values($row['embedding']));
            if ($index !== null && $index >= 0 && $index < count($out)) {
                $out[$index] = $vector;
            }
        }

        /** @var list<list<float>> $out */
        return $out;
    }
}
