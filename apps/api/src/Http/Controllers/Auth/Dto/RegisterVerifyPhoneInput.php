<?php

declare(strict_types=1);

namespace Bayti\Api\Http\Controllers\Auth\Dto;

use Symfony\Component\Validator\Constraints as Assert;

/**
 * Input for POST /v3/auth/register/verify-phone.
 *
 * Phone-first registration step 2: the user submits the SMS-OTP code
 * they received against the verification_id from /register/initiate.
 * On success the endpoint returns a short-lived registration token.
 *
 * Code format mirrors ConfirmInput (4–6 digits — MessageCentral issues
 * 4-digit codes in this account; ceiling stays 6).
 */
final class RegisterVerifyPhoneInput
{
    #[Assert\NotBlank(message: 'Verification id is required.')]
    #[Assert\Length(min: 1, max: 100)]
    public readonly string $verification_id;

    #[Assert\NotBlank(message: 'Code is required.')]
    #[Assert\Regex(
        pattern: '/^\d{4,6}$/',
        message: 'Code must be 4 to 6 digits.',
    )]
    public readonly string $code;

    public function __construct(string $verification_id = '', string $code = '')
    {
        $this->verification_id = trim($verification_id);
        $this->code = trim($code);
    }
}
