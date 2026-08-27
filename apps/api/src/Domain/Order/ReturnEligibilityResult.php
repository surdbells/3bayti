<?php

declare(strict_types=1);

namespace Bayti\Api\Domain\Order;

/**
 * Result of ReturnRequestEligibilityService::evaluate()
 * (M3.2.X.18-C).
 *
 * Either:
 *   - ok with the resolved OrderItem list (success path; the
 *     controller passes these into the OrderReturnRequestItem
 *     constructor)
 *   - failure with a structured error code + human-readable
 *     message (controller surfaces as 422 with that code in the
 *     error envelope)
 *
 * Defensive checks return ok-without-items (rule 1, window check)
 * because the per-item resolution hasn't happened yet at that point;
 * the resolvedItems list only fills in once Rule 2 passes.
 */
final class ReturnEligibilityResult
{
    /**
     * @param list<OrderItem> $resolvedItems
     */
    private function __construct(
        public readonly bool $ok,
        public readonly array $resolvedItems,
        public readonly ?string $errorCode,
        public readonly ?string $errorMessage,
    ) {
    }

    /**
     * Success with the resolved order items.
     *
     * @param list<OrderItem> $items
     */
    public static function ok(array $items): self
    {
        return new self(
            ok: true,
            resolvedItems: $items,
            errorCode: null,
            errorMessage: null,
        );
    }

    /**
     * Success-so-far with no resolved items yet. Used by the
     * window check (Rule 1) which completes before per-item
     * resolution (Rule 2) runs.
     */
    public static function okWithoutItems(): self
    {
        return new self(
            ok: true,
            resolvedItems: [],
            errorCode: null,
            errorMessage: null,
        );
    }

    public static function failure(string $errorCode, string $errorMessage): self
    {
        return new self(
            ok: false,
            resolvedItems: [],
            errorCode: $errorCode,
            errorMessage: $errorMessage,
        );
    }
}
