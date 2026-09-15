<?php

declare(strict_types=1);

namespace Bayti\Api\Ai\Concierge\Style;

use Bayti\Api\Ai\Concierge\ConciergeResult;

/**
 * The outcome of a restyle: the inner {@see ConciergeResult} (merged intent +
 * ranked, validated products) plus the instruction + Ain's rationale. This is a
 * PREVIEW — persistence reuses POST /v3/me/styles with the provenance fields.
 */
final class RestyleResult
{
    public function __construct(
        public readonly ConciergeResult $concierge,
        public readonly string $instruction,
        public readonly string $rationale,
    ) {
    }

    /** @return list<int> */
    public function productIds(): array
    {
        return $this->concierge->productIds();
    }
}
