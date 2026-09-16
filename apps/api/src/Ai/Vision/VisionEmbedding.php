<?php

declare(strict_types=1);

namespace Bayti\Api\Ai\Vision;

/**
 * The result of turning a query image into a searchable vector: the embedding
 * (in the SAME space as product_ai_attributes.embedding, so it can run kNN over
 * the existing product enrichment) plus the short description the vision model
 * produced ("what Ain sees"), for the query log and an optional UI caption.
 * An empty vector means vision was disabled or the call degraded.
 */
final class VisionEmbedding
{
    /**
     * @param list<float> $vector
     */
    public function __construct(
        public readonly array $vector,
        public readonly ?string $description,
    ) {
    }

    public function isEmpty(): bool
    {
        return $this->vector === [];
    }

    public static function empty(): self
    {
        return new self([], null);
    }
}
