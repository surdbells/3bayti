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
 */
final class ValidatePhoneInput
{
    public function __construct(
        #[Assert\NotBlank(message: 'Phone is required.')]
        #[Assert\Length(min: 8, max: 20, minMessage: 'Phone is too short.', maxMessage: 'Phone is too long.')]
        #[Assert\Regex(
            pattern: '/^\+[1-9]\d{6,18}$/',
            message: 'Phone must be in E.164 format with leading "+" and country code.',
        )]
        public readonly string $phone = '',
    ) {
    }
}
