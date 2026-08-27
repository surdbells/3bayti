<?php

declare(strict_types=1);

namespace Bayti\Api\Http\Controllers\Auth\Dto;

use Symfony\Component\Validator\Constraints as Assert;

/**
 * Input for POST /v3/auth/validate-email.
 *
 * Used by signup forms to check email availability without
 * actually creating an account. Returns 200 with {available: bool}.
 *
 * Whitespace handling
 * -------------------
 * The constructor trims the incoming email BEFORE the validator sees
 * it. Real form submissions routinely include accidental leading/
 * trailing spaces from copy-paste, failing those with a 422 would
 * be hostile UX. The pattern below (separate property + custom
 * constructor) is uglier than constructor-promoted properties but
 * it's the cleanest way to normalise input before validation.
 */
final class ValidateEmailInput
{
    #[Assert\NotBlank(message: 'Email is required.')]
    #[Assert\Email(message: 'Please provide a valid email address.')]
    #[Assert\Length(max: 255)]
    public readonly string $email;

    public function __construct(string $email = '')
    {
        $this->email = trim($email);
    }
}
