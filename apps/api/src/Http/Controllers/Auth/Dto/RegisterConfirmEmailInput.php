<?php

declare(strict_types=1);

namespace Bayti\Api\Http\Controllers\Auth\Dto;

use Symfony\Component\Validator\Constraints as Assert;

/**
 * Input for POST /v3/auth/register/confirm-email.
 *
 * Phone-first registration step 4 (final): the user submits the
 * email-OTP code against the verification_id returned by
 * /register/submit. On success the account's email is marked verified
 * and a full session (token pair + user) is issued — same response
 * shape as /v3/auth/confirm.
 *
 * The email-OTP code is a locally-generated 6-digit value; the DTO
 * accepts 4–6 digits for parity with the SMS confirm DTOs.
 */
final class RegisterConfirmEmailInput
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
