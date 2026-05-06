<?php

declare(strict_types=1);

namespace Bayti\Api\Http\Controllers\Auth\Dto;

use Symfony\Component\Validator\Constraints as Assert;

/**
 * Input for POST /v3/auth/refresh.
 *
 * Single field — the refresh token. The client presents this when
 * its access token has expired (or is about to). On success we
 * issue a fresh access + refresh pair and revoke the presented
 * refresh token (single-use rotation, Decision A.6.1).
 *
 * No size constraint
 * ------------------
 * JWTs vary in length depending on payload. A typical refresh
 * token is ~250 bytes; we don't enforce a max because legitimate
 * tokens have a known shape (three base64url segments) which
 * verifyRefreshToken() will reject anything malformed.
 */
final class RefreshInput
{
    #[Assert\NotBlank(message: 'Refresh token is required.')]
    public readonly string $refresh_token;

    public function __construct(string $refresh_token = '')
    {
        $this->refresh_token = trim($refresh_token);
    }
}
