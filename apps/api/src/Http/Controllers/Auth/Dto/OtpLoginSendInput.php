<?php

declare(strict_types=1);

namespace Bayti\Api\Http\Controllers\Auth\Dto;

use Symfony\Component\Validator\Constraints as Assert;
use Symfony\Component\Validator\Context\ExecutionContextInterface;

/**
 * Input for POST /v3/auth/otp-login/send.
 *
 * Passwordless login — request an OTP to a phone or email the user
 * already owns. The user supplies:
 *   - channel: 'phone' | 'email'
 *   - phone + country_code  (channel=phone)
 *   - email                 (channel=email)
 *
 * channel=phone resolves the user by their registered phone and sends
 * an SMS-OTP via MessageCentral; channel=email resolves by email and
 * sends an email-OTP via the local provider (ZeptoMail transport).
 *
 * Anti-enumeration
 * ----------------
 * Exactly the same posture as /v3/auth/reset: the response is identical
 * (a verification_id) whether or not the identifier maps to a real,
 * active user. See OtpLoginSendController for the rationale.
 *
 * Phone normalisation mirrors RegisterInitiateInput / ResetInput —
 * strip spaces / hyphens / parens before validating E.164. country_code
 * is optional and defaults to 'AE' (carried for symmetry with the rest
 * of the auth surface; the lookup keys on the E.164 phone itself).
 */
final class OtpLoginSendInput
{
    /**
     * Delivery channel: 'phone' (SMS to the registered phone) or
     * 'email' (email-OTP to the registered email).
     */
    #[Assert\NotBlank(message: 'Channel is required.')]
    #[Assert\Choice(
        choices: ['phone', 'email'],
        message: 'Channel must be "phone" or "email".',
    )]
    public readonly string $channel;

    /**
     * Required for channel=email. Optional for channel=phone. Conditional
     * requirement enforced in validateIdentifier() below.
     */
    #[Assert\Email(message: 'Please provide a valid email address.')]
    #[Assert\Length(max: 255)]
    public readonly ?string $email;

    /**
     * Required for channel=phone. E.164 format. Optional for
     * channel=email.
     */
    public readonly ?string $phone;

    /**
     * ISO 3166-1 alpha-2 country code. Optional; defaults to 'AE'.
     */
    #[Assert\Length(max: 2)]
    #[Assert\Regex(
        pattern: '/^[A-Z]{2}$/',
        message: 'Country code must be 2 uppercase letters (ISO 3166-1 alpha-2).',
    )]
    public readonly string $country_code;

    public function __construct(
        string $channel = '',
        ?string $phone = null,
        string $email = '',
        string $country_code = 'AE',
    ) {
        $this->channel = strtolower(trim($channel));
        $this->phone = $phone !== null && trim($phone) !== ''
            ? (preg_replace('/[\s\-()]/', '', $phone) ?? null)
            : null;
        $email = trim($email);
        $this->email = $email !== '' ? $email : null;
        $this->country_code = strtoupper(trim($country_code)) ?: 'AE';
    }

    /**
     * Cross-field requirement: the identifier matching the chosen
     * channel must be present. channel=phone needs `phone`;
     * channel=email needs `email`.
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

        if ($this->channel === 'email' && $this->email === null) {
            $context->buildViolation('Email is required for the email channel.')
                ->atPath('email')
                ->addViolation();
        }
    }
}
