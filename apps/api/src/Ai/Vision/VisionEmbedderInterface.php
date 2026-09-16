<?php

declare(strict_types=1);

namespace Bayti\Api\Ai\Vision;

/**
 * Turns a query image into a vector for visual product search. Env-gated behind
 * VISION_ENABLED and provider-abstracted (mirrors AiProviderInterface): the
 * default {@see NullVisionEmbedder} keeps the container booting and the feature
 * dormant until an operator enables it, so Visual Search ships with no cost or
 * infra commitment. The v1 real implementation ({@see OpenAiVisionEmbedder})
 * describes the image with a multimodal model and text-embeds that description
 * into the same space as the product enrichment — a true image-embedding (CLIP)
 * backend can be added later as a sibling implementation without touching callers.
 */
interface VisionEmbedderInterface
{
    public function isEnabled(): bool;

    /**
     * Embed a query image (raw bytes + mime type) into a product-space vector.
     * Never throws — a disabled embedder or a failed call returns an empty
     * {@see VisionEmbedding} so the search simply yields no results.
     */
    public function embedImage(string $bytes, string $mime): VisionEmbedding;
}
