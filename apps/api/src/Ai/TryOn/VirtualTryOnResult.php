<?php

declare(strict_types=1);

namespace Bayti\Api\Ai\TryOn;

/**
 * The result of an AI virtual try-on generation: the composited image bytes
 * (the customer wearing the garment) plus its mime type. An empty result means
 * try-on was disabled or the provider call degraded — the async job then
 * finalises as 'failed' rather than surfacing a broken image to the shopper.
 *
 * Mirrors {@see \Bayti\Api\Ai\Vision\VisionEmbedding}'s value-object posture.
 */
final class VirtualTryOnResult
{
    public function __construct(
        public readonly string $imageBytes,
        public readonly string $mimeType,
    ) {
    }

    public function isEmpty(): bool
    {
        return $this->imageBytes === '';
    }

    public static function empty(): self
    {
        return new self('', '');
    }
}
