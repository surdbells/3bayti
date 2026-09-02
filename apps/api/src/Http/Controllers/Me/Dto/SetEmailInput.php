<?php

declare(strict_types=1);

namespace Bayti\Api\Http\Controllers\Me\Dto;

use Symfony\Component\Validator\Constraints as Assert;

/**
 * Input for POST /v3/me/email.
 *
 * Set (or change) the CURRENT user's email, which then needs OTP
 * confirmation on the new address. Primary use: a customer whose email
 * can't receive our transactional mail (Apple private relay / social
 * placeholder) moving to a deliverable address. The controller additionally
 * rejects a new address that is itself non-deliverable.
 */
final class SetEmailInput
{
    #[Assert\NotBlank(message: 'Email is required.')]
    #[Assert\Email(message: 'Please provide a valid email address.')]
    #[Assert\Length(max: 255)]
    public readonly string $email;

    public function __construct(string $email = '')
    {
        $this->email = strtolower(trim($email));
    }
}
