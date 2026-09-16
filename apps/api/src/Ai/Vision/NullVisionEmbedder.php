<?php

declare(strict_types=1);

namespace Bayti\Api\Ai\Vision;

/**
 * Disabled default. Guarantees the container boots without any vision config and
 * that Visual Search degrades to "no results" when VISION_ENABLED is off.
 */
final class NullVisionEmbedder implements VisionEmbedderInterface
{
    public function isEnabled(): bool
    {
        return false;
    }

    public function embedImage(string $bytes, string $mime): VisionEmbedding
    {
        return VisionEmbedding::empty();
    }
}
