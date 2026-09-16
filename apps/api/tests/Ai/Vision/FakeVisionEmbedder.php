<?php

declare(strict_types=1);

namespace Bayti\Api\Tests\Ai\Vision;

use Bayti\Api\Ai\Vision\VisionEmbedderInterface;
use Bayti\Api\Ai\Vision\VisionEmbedding;

/** Vision embedder stub returning a fixed query vector + description. */
final class FakeVisionEmbedder implements VisionEmbedderInterface
{
    public function __construct(
        private readonly bool $enabled,
        private readonly VisionEmbedding $embedding,
    ) {
    }

    public function isEnabled(): bool
    {
        return $this->enabled;
    }

    public function embedImage(string $bytes, string $mime): VisionEmbedding
    {
        return $this->embedding;
    }
}
