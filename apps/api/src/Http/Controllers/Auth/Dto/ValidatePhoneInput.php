<?php

declare(strict_types=1);

namespace Bayti\Api\Http\Controllers\Auth\Dto;

use Symfony\Component\Validator\Constraints as Assert;

/**
 * Input for POST /v3/auth/validate-phone.
 *
 * Used by signup forms to check phone-number availability before
 * issuing an OTP. Phone is checked in E.164 format (with leading
 * '+'). The Length cap of 20 covers the longest standard E.164
 * numbers; the Regex ensures it starts with '+' and is digits only.
 *
 * Whitespace and formatting characters
 * ------------------------------------
 * The constructor strips spaces, hyphens, and parentheses BEFORE
 * the validator runs. This means '+971 50 123 4567' or '+971-50-1234567'
 * normalise to '+971501234567' and pass validation. Real users type
 * phone numbers in many formats; we accept any of them.
 */
final class ValidatePhoneInput
{
    #[Assert\NotBlank(message: 'Phone is required.')]
    #[Assert\Length(min: 8, max: 20, minMessage: 'Phone is too short.', maxMessage: 'Phone is too long.')]
    #[Assert\Regex(
        pattern: '/^\+[1-9]\d{6,18}$/',
        message: 'Phone must be in E.164 format with leading "+" and country code.',
    )]
    public readonly string $phone;

    public function __construct(string $phone = '')
    {
        // Strip spaces, hyphens, parens — common in user-typed phone
        // numbers. After this, the Regex either matches a clean
        // E.164 string or fails clearly.
        $this->phone = preg_replace('/[\s\-()]/', '', $phone) ?? '';
    }
}
