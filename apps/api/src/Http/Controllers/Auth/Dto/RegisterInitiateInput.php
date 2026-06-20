<?php

declare(strict_types=1);

namespace Bayti\Api\Http\Controllers\Auth\Dto;

use Symfony\Component\Validator\Constraints as Assert;

/**
 * Input for POST /v3/auth/register/initiate.
 *
 * Phone-first registration step 1: the user supplies a phone number
 * (and optional ISO country code). We check availability and, if free,
 * send an SMS-OTP to that phone.
 *
 * Phone format normalisation mirrors RegisterInput (strip spaces /
 * hyphens / parens before the E.164 regex check). country_code is
 * optional here; it defaults to 'AE' and is carried through the
 * registration token to /register/submit.
 */
final class RegisterInitiateInput
{
    #[Assert\NotBlank(message: 'Phone is required.')]
    #[Assert\Length(min: 8, max: 20)]
    #[Assert\Regex(
        pattern: '/^\+[1-9]\d{6,18}$/',
        message: 'Phone must be in E.164 format with leading "+" and country code.',
    )]
    public readonly string $phone;

    #[Assert\Length(max: 2)]
    #[Assert\Regex(
        pattern: '/^[A-Z]{2}$/',
        message: 'Country code must be 2 uppercase letters (ISO 3166-1 alpha-2).',
    )]
    public readonly string $country_code;

    public function __construct(string $phone = '', string $country_code = 'AE')
    {
        $this->phone = preg_replace('/[\s\-()]/', '', $phone) ?? '';
        $this->country_code = strtoupper(trim($country_code));
    }
}
