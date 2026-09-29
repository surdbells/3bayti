<?php

declare(strict_types=1);

namespace Bayti\Api\Http\Controllers\Admin\Vendor\Dto;

use Symfony\Component\Validator\Constraints as Assert;
use Symfony\Component\Validator\Context\ExecutionContextInterface;

/**
 * Body for POST /v3/admin/vendors/{id}/reset-password.
 *
 *   { "mode": "generate" | "manual", "password"?: string, "reason"?: string }
 *
 * - generate: the server mints a temporary password and emails it to the
 *   vendor's owner account. `password` must be omitted.
 * - manual:   the admin supplies `password` (policy mirrors registration +
 *   self-service change: 8-char NIST minimum, 200 max, NOT trimmed) and hands
 *   it to the vendor out-of-band; the email only notifies them of the reset.
 *
 * In both modes the vendor is forced to choose their own password on next
 * sign-in. `reason` is an optional operator note captured in the audit log.
 */
final class ResetVendorPasswordInput
{
    public const MODE_GENERATE = 'generate';
    public const MODE_MANUAL = 'manual';

    #[Assert\NotBlank(message: 'Reset mode is required.')]
    #[Assert\Choice(
        choices: [self::MODE_GENERATE, self::MODE_MANUAL],
        message: 'Reset mode must be "generate" or "manual".',
    )]
    public readonly string $mode;

    #[Assert\Length(
        min: 8,
        max: 200,
        minMessage: 'Password must be at least {{ limit }} characters.',
        maxMessage: 'Password must be at most {{ limit }} characters.',
    )]
    public readonly ?string $password;

    #[Assert\Length(max: 1000)]
    public readonly ?string $reason;

    public function __construct(string $mode = '', ?string $password = null, ?string $reason = null)
    {
        $this->mode = $mode;
        $this->password = $password; // Do NOT trim.
        $reason = $reason !== null ? trim($reason) : null;
        $this->reason = $reason === '' ? null : $reason;
    }

    public function isGenerate(): bool
    {
        return $this->mode === self::MODE_GENERATE;
    }

    #[Assert\Callback]
    public function validatePasswordForMode(ExecutionContextInterface $context): void
    {
        $hasPassword = $this->password !== null && $this->password !== '';

        if ($this->mode === self::MODE_MANUAL && !$hasPassword) {
            $context->buildViolation('New password is required when setting it manually.')
                ->atPath('password')
                ->addViolation();
        }
        if ($this->mode === self::MODE_GENERATE && $hasPassword) {
            $context->buildViolation('Omit the password when generating one.')
                ->atPath('password')
                ->addViolation();
        }
    }
}
