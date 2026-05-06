<?php

declare(strict_types=1);

namespace Bayti\Api\Http\Controllers\Auth\Dto;

use Symfony\Component\Validator\Constraints as Assert;

/**
 * Input for POST /v3/auth/login.
 *
 * Email-first identifier per Decision A.6.4 — the frontend collects
 * the email; phone-as-username is a future enhancement (post-M1).
 *
 * Password constraints
 * --------------------
 * Validation here is intentionally MINIMAL — just NotBlank.
 *
 *   1. Strong password rules belong on REGISTRATION, not LOGIN.
 *      Putting `Length(min:8)` on login means existing users with
 *      6-char passwords (legacy migration) couldn't log in.
 *
 *   2. Login validation should reveal as little as possible about
 *      what makes a "valid" password to attackers.
 *
 *   3. The actual constant-time password_verify() handles the real
 *      comparison; format validation can't make that more secure.
 */
final class LoginInput
{
    public function __construct(
        #[Assert\NotBlank(message: 'Email is required.')]
        #[Assert\Email(message: 'Please provide a valid email address.')]
        public readonly string $email = '',

        #[Assert\NotBlank(message: 'Password is required.')]
        public readonly string $password = '',
    ) {
    }
}
