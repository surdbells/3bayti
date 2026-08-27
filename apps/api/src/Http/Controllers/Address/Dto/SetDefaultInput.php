<?php

declare(strict_types=1);

namespace Bayti\Api\Http\Controllers\Address\Dto;

use Symfony\Component\Validator\Constraints as Assert;
use Symfony\Component\Validator\Context\ExecutionContextInterface;

/**
 * Input for PATCH /v3/me/addresses/{id}/default.
 *
 * Body shape: { "shipping": bool, "billing": bool }
 *
 * Either field optional individually but at LEAST ONE must be present
 * (sending an empty body has no operation to perform, different from
 * PATCH /me/profile where 'no change' is meaningful).
 *
 * Tristate constructor caveat
 * ---------------------------
 * RequestValidator hydrates DTOs via constructor parameters and we
 * can't distinguish 'field absent' from 'field provided as null'
 * (same limitation as UpdateProfileInput).
 *
 * Workaround: use ?bool, default null.
 *   - null   = field not provided, leave that role-flag alone
 *   - true   = set this address as default for that role
 *   - false  = clear this address from being default for that role
 *
 * If both fields are null after validation, that means an empty
 * body or an all-null payload, Callback rejects with a 422.
 */
final class SetDefaultInput
{
    /**
     * Set/unset this address as the default SHIPPING address.
     *
     *   true   → this address becomes the default shipping address;
     *            the previous default (if any) is unset.
     *   false  → this address loses its default-shipping status;
     *            no other address is auto-promoted.
     *   null   → no change to the shipping default.
     */
    #[Assert\Type(type: 'bool', message: 'shipping must be a boolean.')]
    public readonly ?bool $shipping;

    /**
     * Set/unset this address as the default BILLING address.
     * Same semantics as $shipping but for the billing role.
     */
    #[Assert\Type(type: 'bool', message: 'billing must be a boolean.')]
    public readonly ?bool $billing;

    public function __construct(
        ?bool $shipping = null,
        ?bool $billing = null,
    ) {
        $this->shipping = $shipping;
        $this->billing = $billing;
    }

    /**
     * Reject the request if BOTH fields are null. The endpoint has
     * no operation to perform with an empty body; 422 makes that
     * explicit rather than silently doing nothing.
     */
    #[Assert\Callback]
    public function validate(ExecutionContextInterface $context): void
    {
        if ($this->shipping === null && $this->billing === null) {
            $context->buildViolation(
                'Provide at least one of "shipping" or "billing" as a boolean.',
            )
                ->atPath('_root')
                ->addViolation();
        }
    }
}
