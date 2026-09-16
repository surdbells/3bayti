<?php

declare(strict_types=1);

namespace Bayti\Api\Ai\TryOn;

/**
 * Generates an AI "virtual try-on": composites a photo of the customer with a
 * garment product image into a realistic image of the customer wearing it.
 *
 * Env-gated behind TRYON_ENABLED and provider-abstracted (mirrors
 * {@see \Bayti\Api\Ai\Vision\VisionEmbedderInterface}). The default
 * {@see NullVirtualTryOnProvider} keeps the container booting and the feature
 * dormant until an operator enables it, so Virtual Try-On ships with no cost or
 * infra commitment. The v1 real implementation
 * ({@see OpenAiVirtualTryOnProvider}) reuses the existing OpenAI key/client; a
 * dedicated try-on vendor can be added later as a sibling implementation
 * without touching callers.
 */
interface VirtualTryOnProviderInterface
{
    public function isEnabled(): bool;

    /**
     * Composite the person photo (raw bytes + mime) with the garment image
     * (raw bytes + mime), guided by a short garment description. Never throws —
     * a disabled provider or a failed call returns an empty
     * {@see VirtualTryOnResult} so the async job finalises as 'failed'.
     */
    public function generate(
        string $personBytes,
        string $personMime,
        string $garmentBytes,
        string $garmentMime,
        string $garmentPrompt,
    ): VirtualTryOnResult;
}
