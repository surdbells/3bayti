<?php

declare(strict_types=1);

namespace Bayti\Api\Infrastructure\Auth;

/**
 * Thrown by {@see FirebaseIdTokenVerifier} when a presented Firebase ID
 * token cannot be trusted — for ANY reason (bad signature, expired,
 * wrong audience/issuer, malformed, unfetchable certs, unsupported
 * provider, …).
 *
 * Deliberately a single opaque type with a generic message. Controllers
 * map it to one uniform 401 so the response never reveals WHICH check
 * failed — no oracle that would help an attacker forge a token. The
 * underlying cause is preserved as the chained $previous for server-side
 * logging only.
 */
final class SocialTokenVerificationException extends \RuntimeException
{
    public static function invalid(?\Throwable $previous = null): self
    {
        return new self('Social sign-in token could not be verified.', 0, $previous);
    }
}
