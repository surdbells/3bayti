<?php

declare(strict_types=1);

namespace Bayti\Api\Http\Controllers\Auth\Dto;

use Symfony\Component\Validator\Constraints as Assert;

/**
 * Input for POST /v3/auth/send-otp.
 *
 * Re-send an OTP to an existing unverified user. Used when:
 *   - User registered but didn't receive the OTP SMS (carrier issue, typo
 *     in phone number that they've since corrected, actually we don't
 *     support phone-correction at registration time yet, but the resend
 *     covers transient SMS delivery failures)
 *   - User registered and the OTP expired before they entered it
 *
 * Scope deliberately narrow: this endpoint resends REGISTRATION OTPs
 * only. Password-reset OTPs go through /v3/auth/reset (M1.4.4) and
 * carry their own anti-enumeration logic. Phone-change OTPs are
 * post-M1.
 *
 * Identifier
 * ----------
 * Email-based per Decision A.6.4. We look up the User by email,
 * find their phone, and re-send to that phone. The user doesn't
 * supply a phone number, that's recorded at registration time
 * and can't be changed without first verifying it.
 *
 * Privacy
 * -------
 * Like /reset (which we'll do in M1.4.4), this endpoint can be used
 * to confirm whether an email is registered. Per the Decision in
 * M1.4.2's validate-email comments, we accept this trade-off for UX.
 * M5 hardening adds rate limiting + uniform response timing.
 */
final class SendOtpInput
{
    #[Assert\NotBlank(message: 'Email is required.')]
    #[Assert\Email(message: 'Please provide a valid email address.')]
    public readonly string $email;

    public function __construct(string $email = '')
    {
        $this->email = trim($email);
    }
}
