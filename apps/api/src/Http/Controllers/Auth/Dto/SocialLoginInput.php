<?php

declare(strict_types=1);

namespace Bayti\Api\Http\Controllers\Auth\Dto;

use Symfony\Component\Validator\Constraints as Assert;

/**
 * Input for POST /v3/auth/social.
 *
 * The client signs in with Google/Apple via the Firebase SDK and sends
 * us the resulting Firebase ID token. first_name / last_name are
 * optional client-supplied hints used ONLY when we have to CREATE a new
 * account and the token carries no usable name — they never override an
 * existing user's profile.
 */
final class SocialLoginInput
{
    #[Assert\NotBlank(message: 'A sign-in token is required.')]
    #[Assert\Length(max: 4096, maxMessage: 'Sign-in token is too long.')]
    public readonly string $id_token;

    #[Assert\Length(max: 100)]
    public readonly ?string $first_name;

    #[Assert\Length(max: 100)]
    public readonly ?string $last_name;

    public function __construct(
        string $id_token = '',
        ?string $first_name = null,
        ?string $last_name = null,
    ) {
        $this->id_token = trim($id_token);
        $this->first_name = $first_name !== null ? trim($first_name) : null;
        $this->last_name = $last_name !== null ? trim($last_name) : null;
    }
}
