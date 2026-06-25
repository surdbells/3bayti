<?php

declare(strict_types=1);

namespace Bayti\Api\Http\Controllers\Auth\Dto;

use Symfony\Component\Validator\Constraints as Assert;
use Symfony\Component\Validator\Context\ExecutionContextInterface;

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
    /**
     * Delivery channel for the reset OTP. 'email' sends a local
     * email-OTP to the supplied email; 'phone' sends an SMS-OTP to the
     * user's REGISTERED phone (looked up by the supplied phone).
     *
     * Defaults to 'sms' when omitted so the existing email-only mobile
     * flow (which posts just { email }) keeps working unchanged: the
     * legacy flow looked up the user by email and sent an SMS to their
     * registered phone, which is exactly the channel='sms' branch.
     */
    #[Assert\Choice(
        choices: ['email', 'phone', 'sms'],
        message: 'Channel must be "email" or "phone".',
    )]
    public readonly string $channel;

    /**
     * Required for channel=email AND for the back-compat default
     * channel (used to look the user up). Optional for channel=phone.
     * Conditional "required" is enforced in validateIdentifier() below.
     */
    #[Assert\Email(message: 'Please provide a valid email address.')]
    #[Assert\Length(max: 255)]
    public readonly ?string $email;

    /**
     * Required for channel=phone. E.164 format. Optional for
     * channel=email / the default.
     */
    public readonly ?string $phone;

    public function __construct(
        string $email = '',
        ?string $phone = null,
        string $channel = 'sms',
    ) {
        // Canonicalise the email ONCE (lowercase + trim). User lookup is
        // case-insensitive but the OTP dedup/cooldown/rate-limit keys are
        // case-sensitive — normalising here keeps a case-variant from
        // bypassing dedup and double-emailing the same reset code.
        $email = strtolower(trim($email));
        $this->email = $email !== '' ? $email : null;
        $this->phone = $phone !== null && trim($phone) !== ''
            ? (preg_replace('/[\s\-()]/', '', $phone) ?? null)
            : null;
        $this->channel = strtolower(trim($channel));
    }

    /**
     * Cross-field requirement: the right identifier must be present for
     * the chosen channel. channel=phone needs `phone`; every other
     * channel (email + the back-compat default) needs `email`.
     *
     * Using a Callback rather than Assert\When keeps us off the
     * symfony/expression-language dependency while preserving the old
     * "email is required" 422 for the default flow.
     */
    #[Assert\Callback]
    public function validateIdentifier(ExecutionContextInterface $context): void
    {
        if ($this->channel === 'phone') {
            if ($this->phone === null) {
                $context->buildViolation('Phone is required for the phone channel.')
                    ->atPath('phone')
                    ->addViolation();
            }
            return;
        }

        if ($this->email === null) {
            $context->buildViolation('Email is required.')
                ->atPath('email')
                ->addViolation();
        }
    }
}
