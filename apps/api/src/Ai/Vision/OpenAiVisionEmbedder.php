<?php

declare(strict_types=1);

namespace Bayti\Api\Ai\Vision;

use Bayti\Api\Ai\AiException;
use Bayti\Api\Ai\OpenAi\OpenAiClient;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * v1 visual-search embedder: describe-then-embed. A multimodal chat model turns
 * the query image into a concise fashion description, which is then text-embedded
 * into the SAME space as product_ai_attributes.embedding — so kNN runs over the
 * products' existing text enrichment with no separate image-embedding column or
 * enrichment pass. Reuses the OpenAI key/client; gated by VISION_ENABLED in DI.
 *
 * Never throws: any AI error degrades to an empty embedding (no results), matching
 * the null-object posture.
 */
final class OpenAiVisionEmbedder implements VisionEmbedderInterface
{
    private const DESCRIBE_MAX_TOKENS = 120;

    private LoggerInterface $logger;

    public function __construct(
        private readonly OpenAiClient $client,
        private readonly string $visionModel,
        ?LoggerInterface $logger = null,
    ) {
        $this->logger = $logger ?? new NullLogger();
    }

    public function isEnabled(): bool
    {
        return true;
    }

    public function embedImage(string $bytes, string $mime): VisionEmbedding
    {
        if ($bytes === '') {
            return VisionEmbedding::empty();
        }
        try {
            $description = $this->describe($bytes, $mime);
            if ($description === '') {
                return VisionEmbedding::empty();
            }
            $vector = $this->embedText($description);
            if ($vector === []) {
                return new VisionEmbedding([], $description);
            }
            return new VisionEmbedding($vector, $description);
        } catch (AiException $e) {
            $this->logger->warning('ai.vision_embed_failed', ['kind' => $e->kind, 'error' => $e->getMessage()]);
            return VisionEmbedding::empty();
        }
    }

    private function describe(string $bytes, string $mime): string
    {
        $dataUri = 'data:' . ($mime !== '' ? $mime : 'image/jpeg') . ';base64,' . base64_encode($bytes);

        $response = $this->client->chat([
            'model' => $this->visionModel,
            'messages' => [
                ['role' => 'system', 'content' => self::describeSystemPrompt()],
                ['role' => 'user', 'content' => [
                    ['type' => 'text', 'text' => 'Describe this fashion item for catalogue search.'],
                    ['type' => 'image_url', 'image_url' => ['url' => $dataUri, 'detail' => 'low']],
                ]],
            ],
            'max_tokens' => self::DESCRIBE_MAX_TOKENS,
            'temperature' => 0.2,
        ]);

        $choices = $response['choices'] ?? null;
        if (!is_array($choices) || !isset($choices[0]) || !is_array($choices[0])) {
            return '';
        }
        $message = $choices[0]['message'] ?? null;
        $content = is_array($message) ? ($message['content'] ?? null) : null;

        return is_string($content) ? trim($content) : '';
    }

    /**
     * @return list<float>
     */
    private function embedText(string $text): array
    {
        $response = $this->client->embeddings([
            'model' => $this->client->embedModel(),
            'input' => [$text],
        ]);

        $data = $response['data'] ?? null;
        if (!is_array($data) || !isset($data[0]) || !is_array($data[0])) {
            return [];
        }
        $embedding = $data[0]['embedding'] ?? null;
        if (!is_array($embedding)) {
            return [];
        }
        return array_map(static fn ($v): float => (float) $v, array_values($embedding));
    }

    private static function describeSystemPrompt(): string
    {
        return <<<PROMPT
            You describe a modest-fashion product in ONE concise English phrase for
            catalogue search: the garment type (abaya, kaftan, dress, bag, scarf,
            shoes, accessory …), its main colour(s), the style/vibe (elegant,
            embellished, minimal, traditional …), and the occasion if evident. No
            preamble, no sentences — just the descriptive phrase (<= 20 words).
            PROMPT;
    }
}
