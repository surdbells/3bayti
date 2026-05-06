<?php

declare(strict_types=1);

namespace Bayti\Api\Http\Controllers\Auth\Dto;

use Symfony\Component\Validator\Constraints as Assert;

/**
 * Input for POST /v3/auth/reset.
 *
 * Initiates password reset. The user supplies their EMAIL (per
 * Decision A.6.5 — email-first identifier across the auth surface).
 * We look up the User by email, find their stored phone, and send
 * an OTP to that phone.
 *
 * The user does NOT supply a phone number. Two reasons:
 *   1. Consistent with /login and /send-otp.
 *   2. Security: if a user could supply both email AND phone, an
 *      attacker who learned one user's email could try various
 *      phones until something accepts. We bind the reset target
 *      to the phone we already have on record.
 *
 * Anti-enumeration
 * ----------------
 * Same trade-off as /send-otp: the response is identical whether
 * the email is registered or not. See SendOtpController docblock
 * for the full rationale.
 */
final class ResetInput
{
    #[Assert\NotBlank(message: 'Email is required.')]
    #[Assert\Email(message: 'Please provide a valid email address.')]
    public readonly string $email;

    public function __construct(string $email = '')
    {
        $this->email = trim($email);
    }
}
