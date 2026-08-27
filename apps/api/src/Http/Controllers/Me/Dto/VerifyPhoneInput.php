<?php

declare(strict_types=1);

namespace Bayti\Api\Http\Controllers\Me\Dto;

use Symfony\Component\Validator\Constraints as Assert;

/**
 * Input for POST /v3/me/phone/verify.
 *
 * Confirm the OTP sent by POST /v3/me/phone. On success the pending
 * phone is committed and is_phone_verified is set true. Mirrors
 * ConfirmInput (registration OTP), same verification_id + 4–6 digit
 * code contract.
 */
final class VerifyPhoneInput
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
