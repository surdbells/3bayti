<?php

declare(strict_types=1);

namespace Bayti\Api\Http\Controllers\Auth\Dto;

use Symfony\Component\Validator\Constraints as Assert;

/**
 * Input for POST /v3/auth/logout.
 *
 * Single field, the refresh token whose session to end. It identifies
 * WHICH device's session family to revoke (a user signed in on 5
 * devices ends only the current one) and is itself the credential; the
 * Authorization header is optional (see LogoutController).
 *
 * Why we don't infer the refresh token from server state
 * ------------------------------------------------------
 * The access token's jti is NOT stored server-side (deliberate -
 * keeps access tokens stateless). So we can't go from access-token-
 * jti to refresh-token-jti without an extra mapping table. The
 * client knows both tokens (it stores them); having the client
 * present the refresh token in body is simpler than maintaining
 * server-side correlation.
 *
 * Idempotency
 * -----------
 * Calling /logout with an already-revoked or unknown refresh token
 * still returns 204. A logged-out user shouldn't see "you were
 * already logged out" errors, that's confusing UX and exposes
 * server-side state.
 */
final class LogoutInput
{
    #[Assert\NotBlank(message: 'Refresh token is required.')]
    public readonly string $refresh_token;

    public function __construct(string $refresh_token = '')
    {
        $this->refresh_token = trim($refresh_token);
    }
}
