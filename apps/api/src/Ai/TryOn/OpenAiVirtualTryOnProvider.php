<?php

declare(strict_types=1);

namespace Bayti\Api\Ai\TryOn;

use Bayti\Api\Ai\AiException;
use Bayti\Api\Ai\OpenAi\OpenAiClient;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * v1 virtual-try-on provider: composes the customer photo and the garment
 * product image with an image-editing model (default gpt-image-1) into a
 * photorealistic image of the customer wearing the garment. Reuses the existing
 * OpenAI key/client; gated by TRYON_ENABLED in DI.
 *
 * Never throws: any AI error degrades to an empty {@see VirtualTryOnResult}
 * (the async job then finalises as 'failed'), matching the null-object posture
 * of {@see \Bayti\Api\Ai\Vision\OpenAiVisionEmbedder}. A dedicated try-on vendor
 * can be added later as a sibling implementation without touching callers.
 */
final class OpenAiVirtualTryOnProvider implements VirtualTryOnProviderInterface
{
    /** OpenAI images/edits returns b64 PNG. */
    private const OUTPUT_MIME = 'image/png';

    private LoggerInterface $logger;

    public function __construct(
        private readonly OpenAiClient $client,
        private readonly string $tryOnModel,
        private readonly string $imageSize,
        ?LoggerInterface $logger = null,
    ) {
        $this->logger = $logger ?? new NullLogger();
    }

    public function isEnabled(): bool
    {
        return true;
    }

    public function generate(
        string $personBytes,
        string $personMime,
        string $garmentBytes,
        string $garmentMime,
        string $garmentPrompt,
    ): VirtualTryOnResult {
        if ($personBytes === '' || $garmentBytes === '') {
            return VirtualTryOnResult::empty();
        }

        try {
            $response = $this->client->imageEdit([
                ['name' => 'model', 'contents' => $this->tryOnModel],
                [
                    'name' => 'image[]',
                    'contents' => $personBytes,
                    'filename' => 'person.' . $this->extension($personMime),
                    'headers' => ['Content-Type' => $personMime !== '' ? $personMime : 'image/jpeg'],
                ],
                [
                    'name' => 'image[]',
                    'contents' => $garmentBytes,
                    'filename' => 'garment.' . $this->extension($garmentMime),
                    'headers' => ['Content-Type' => $garmentMime !== '' ? $garmentMime : 'image/jpeg'],
                ],
                ['name' => 'prompt', 'contents' => $this->buildPrompt($garmentPrompt)],
                ['name' => 'size', 'contents' => $this->imageSize],
                ['name' => 'n', 'contents' => '1'],
            ]);

            $bytes = $this->extractImageBytes($response);
            if ($bytes === '') {
                return VirtualTryOnResult::empty();
            }

            return new VirtualTryOnResult($bytes, self::OUTPUT_MIME);
        } catch (AiException $e) {
            $this->logger->warning('ai.tryon_generate_failed', [
                'kind' => $e->kind,
                'error' => $e->getMessage(),
            ]);
            return VirtualTryOnResult::empty();
        }
    }

    private function buildPrompt(string $garmentPrompt): string
    {
        $garment = trim($garmentPrompt);
        $garmentLine = $garment !== ''
            ? "The garment (second image) is: {$garment}."
            : 'The garment is shown in the second image.';

        return <<<PROMPT
            Create a single photorealistic image of the PERSON in the first image
            wearing the GARMENT from the second image. {$garmentLine}
            Preserve the person's face, hair, skin tone, body shape and pose
            exactly — change only their clothing so they are wearing the garment,
            fitted naturally with realistic drape, folds and lighting. Keep a
            clean, flattering studio-style background. Modest-fashion context: the
            result must be tasteful and fully covered. Do not add text, logos or
            watermarks.
            PROMPT;
    }

    /**
     * Pull the first image's raw bytes out of the images/edits response
     * ({ data: [{ b64_json }] }). Returns '' when absent/undecodable.
     *
     * @param array<string, mixed> $response
     */
    private function extractImageBytes(array $response): string
    {
        $data = $response['data'] ?? null;
        if (!is_array($data) || !isset($data[0]) || !is_array($data[0])) {
            return '';
        }
        $b64 = $data[0]['b64_json'] ?? null;
        if (!is_string($b64) || $b64 === '') {
            return '';
        }
        $bytes = base64_decode($b64, true);
        return ($bytes === false || $bytes === '') ? '' : $bytes;
    }

    private function extension(string $mime): string
    {
        return match (strtolower($mime)) {
            'image/png' => 'png',
            'image/webp' => 'webp',
            default => 'jpg',
        };
    }
}
