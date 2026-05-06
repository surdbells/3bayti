<?php

declare(strict_types=1);

namespace Bayti\Api\Http\Controllers\Auth\Dto;

use Symfony\Component\Validator\Constraints as Assert;

/**
 * Input for POST /v3/auth/confirm.
 *
 * Verifies a registration OTP. The client supplies:
 *   - verification_id: the opaque token from /register or /send-otp
 *   - code: the 6-digit code the user typed from their SMS
 *
 * Server flow on success:
 *   - OtpService::verify against MessageCentral
 *   - Mark User.is_phone_verified = true
 *   - Bind the OtpAttempt to the User (which it wasn't at /register
 *     time, since user_id is set on registration but the OtpAttempt
 *     row was already inserted with that user_id; this step is a
 *     no-op in practice but defensive)
 *   - Issue JWT token pair
 *   - Persist refresh-token row
 *
 * Code format
 * -----------
 * MessageCentral generates 6-digit numeric codes. We validate as
 * "exactly 6 digits". Refusing 4-digit, 8-digit, or alphanumeric
 * codes here is fine — if MessageCentral changes their format we
 * loosen the check then.
 */
final class ConfirmInput
{
    #[Assert\NotBlank(message: 'Verification id is required.')]
    #[Assert\Length(min: 1, max: 100)]
    public readonly string $verification_id;

    #[Assert\NotBlank(message: 'Code is required.')]
    #[Assert\Regex(
        pattern: '/^\d{6}$/',
        message: 'Code must be 6 digits.',
    )]
    public readonly string $code;

    public function __construct(string $verification_id = '', string $code = '')
    {
        $this->verification_id = trim($verification_id);
        $this->code = trim($code);
    }
}
