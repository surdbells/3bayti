<?php

declare(strict_types=1);

namespace Bayti\Api\Http\Controllers\Me\Dto;

use Symfony\Component\Validator\Constraints as Assert;

/**
 * Input for POST /v3/me/social-identities.
 *
 * Attach a Google/Apple account to the CURRENT (already-authenticated)
 * user. The client signs in with the provider afresh and sends the
 * resulting Firebase ID token; we verify it server-side before linking.
 */
final class LinkSocialIdentityInput
{
    #[Assert\NotBlank(message: 'A sign-in token is required.')]
    #[Assert\Length(max: 4096, maxMessage: 'Sign-in token is too long.')]
    public readonly string $id_token;

    public function __construct(string $id_token = '')
    {
        $this->id_token = trim($id_token);
    }
}
