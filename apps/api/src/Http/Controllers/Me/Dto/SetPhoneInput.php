<?php

declare(strict_types=1);

namespace Bayti\Api\Http\Controllers\Me\Dto;

use Symfony\Component\Validator\Constraints as Assert;

/**
 * Input for POST /v3/me/phone.
 *
 * Set (or change) the phone number on the CURRENT user's account, which
 * then needs OTP confirmation. Primarily used right after social sign-in
 * (Google/Apple accounts arrive with no phone), but also valid for any
 * authenticated user changing their number.
 *
 * Phone format matches RegisterInput (E.164 with leading '+').
 */
final class SetPhoneInput
{
    #[Assert\NotBlank(message: 'Phone is required.')]
    #[Assert\Length(min: 8, max: 20)]
    #[Assert\Regex(
        pattern: '/^\+[1-9]\d{6,18}$/',
        message: 'Phone must be in E.164 format with leading "+" and country code.',
    )]
    public readonly string $phone;

    public function __construct(string $phone = '')
    {
        // Strip spaces / dashes / parens before validating, same as
        // RegisterInput, so '+971 50 123 4567' normalises cleanly.
        $this->phone = preg_replace('/[\s\-()]/', '', $phone) ?? '';
    }
}
