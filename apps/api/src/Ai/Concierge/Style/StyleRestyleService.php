<?php

declare(strict_types=1);

namespace Bayti\Api\Ai\Concierge\Style;

use Bayti\Api\Ai\Concierge\ConciergeRanker;
use Bayti\Api\Ai\Concierge\ConciergeResult;
use Bayti\Api\Ai\Concierge\IntentParser;
use Bayti\Api\Ai\Concierge\ProductRetrievalService;
use Bayti\Api\Domain\Catalog\Product;

/**
 * Orchestrates an Ain "restyle": parse the instruction → merge with the seed
 * look into one intent → rebuild from real, in-stock products via the reused
 * Phase-1 retrieval + ranking pipeline. Returns a PREVIEW (the client persists
 * separately via POST /v3/me/styles with provenance).
 */
final class StyleRestyleService
{
    /** A look is a small set; keep the rebuild within the 4-product style cap. */
    public const RESTYLE_LIMIT = 4;

    public function __construct(
        private readonly IntentParser $intentParser,
        private readonly ProductRetrievalService $retrieval,
        private readonly ConciergeRanker $ranker,
    ) {
    }

    /**
     * @param list<Product> $seedProducts already-validated seed look
     */
    public function restyle(array $seedProducts, string $instruction, string $locale = 'en', int $limit = self::RESTYLE_LIMIT): RestyleResult
    {
        $instructionIntent = $this->intentParser->parse($instruction, $locale);
        $brief = new RestyleBrief($seedProducts, $instruction, $instructionIntent);

        $intent = $brief->toIntent();
        $shortlist = $this->retrieval->retrieve($intent, $limit);
        $items = $this->ranker->rank($intent, $shortlist, $brief->toRankerQuery(), $limit);

        return new RestyleResult(
            new ConciergeResult($intent, $items),
            $brief->instruction(),
            $brief->rationale($locale),
        );
    }
}
