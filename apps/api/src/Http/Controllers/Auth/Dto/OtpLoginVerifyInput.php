<?php

declare(strict_types=1);

namespace Bayti\Api\Http\Controllers\Auth\Dto;

use Symfony\Component\Validator\Constraints as Assert;

/**
 * Input for POST /v3/auth/otp-login/verify.
 *
 * Completes passwordless login. The client supplies the verification_id
 * from /otp-login/send plus the numeric code the user received (over SMS
 * or email). On success the controller issues the standard login
 * envelope (access + refresh tokens + user).
 *
 * Code format mirrors ConfirmInput: 4–6 digits (MessageCentral issues
 * 4-digit codes for this account; the local email provider issues 6).
 */
final class OtpLoginVerifyInput
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
