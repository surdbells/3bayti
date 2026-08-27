<?php

declare(strict_types=1);

namespace Bayti\Api\Infrastructure\Auth;

/**
 * Result of a successful Firebase ID-token verification, the trusted,
 * normalised claims the social-login flow needs.
 *
 * `provider` is normalised to our internal vocabulary ('google' /
 * 'apple'), mapped from Firebase's sign_in_provider ('google.com' /
 * 'apple.com'). `providerUid` is the provider's stable subject id (from
 * firebase.identities, falling back to the token sub).
 *
 * Immutable value object, never constructed from un-verified input.
 */
final class VerifiedSocialIdentity
{
    public function __construct(
        public readonly string $provider,
        public readonly string $providerUid,
        public readonly ?string $email,
        public readonly bool $emailVerified,
        public readonly ?string $name,
    ) {
    }
}
