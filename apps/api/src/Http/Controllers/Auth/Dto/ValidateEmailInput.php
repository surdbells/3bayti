<?php

declare(strict_types=1);

namespace Bayti\Api\Http\Controllers\Auth\Dto;

use Symfony\Component\Validator\Constraints as Assert;

/**
 * Input for POST /v3/auth/validate-email.
 *
 * Used by signup forms to check email availability without
 * actually creating an account. Returns 200 with {available: bool}.
 */
final class ValidateEmailInput
{
    public function __construct(
        #[Assert\NotBlank(message: 'Email is required.')]
        #[Assert\Email(message: 'Please provide a valid email address.')]
        #[Assert\Length(max: 255)]
        public readonly string $email = '',
    ) {
    }
}
