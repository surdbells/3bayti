<?php

declare(strict_types=1);

namespace Bayti\Api\Http\Controllers\Auth\Dto;

use Symfony\Component\Validator\Constraints as Assert;

/**
 * Input for POST /v3/auth/register/submit.
 *
 * Phone-first registration step 3: with a verified phone (proven by
 * the registration_token), the user supplies their email + password
 * (+ optional names). The phone + country_code come from the TOKEN,
 * not the body, the client cannot register an account against a phone
 * it didn't verify.
 *
 * Password rules mirror RegisterInput (min 8 chars, NIST SP 800-63B).
 */
final class RegisterSubmitInput
{
    #[Assert\NotBlank(message: 'Registration token is required.')]
    #[Assert\Length(min: 1, max: 4096)]
    public readonly string $registration_token;

    #[Assert\NotBlank(message: 'Email is required.')]
    #[Assert\Email(message: 'Please provide a valid email address.')]
    #[Assert\Length(max: 255)]
    public readonly string $email;

    #[Assert\NotBlank(message: 'Password is required.')]
    #[Assert\Length(
        min: 8,
        minMessage: 'Password must be at least {{ limit }} characters.',
    )]
    public readonly string $password;

    #[Assert\Length(max: 100)]
    public readonly ?string $first_name;

    #[Assert\Length(max: 100)]
    public readonly ?string $last_name;

    public function __construct(
        string $registration_token = '',
        string $email = '',
        string $password = '',
        ?string $first_name = null,
        ?string $last_name = null,
    ) {
        $this->registration_token = trim($registration_token);
        // Canonicalise the email ONCE (lowercase + trim). User lookup is
        // case-insensitive but the OTP dedup/cooldown/rate-limit keys are
        // case-sensitive, normalising here keeps a case-variant from
        // bypassing dedup and double-emailing the same registration code.
        $this->email = strtolower(trim($email));
        $this->password = $password; // Do NOT trim, passwords with whitespace are valid.
        $this->first_name = $first_name !== null ? trim($first_name) : null;
        $this->last_name = $last_name !== null ? trim($last_name) : null;
    }
}
