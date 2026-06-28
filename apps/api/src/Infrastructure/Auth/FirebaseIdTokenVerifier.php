<?php

declare(strict_types=1);

namespace Bayti\Api\Infrastructure\Auth;

use Firebase\JWT\JWT;
use Firebase\JWT\Key;
use GuzzleHttp\ClientInterface;
use Psr\Cache\CacheItemPoolInterface;

/**
 * Verifies Firebase ID tokens (RS256) minted by Google Identity Platform
 * for Google + Apple federated sign-in.
 *
 * Trust model
 * -----------
 * The mobile/web client signs the user in with the Firebase SDK (Google
 * or Apple), then sends us the resulting Firebase ID token. We verify it
 * server-side before trusting ANY claim:
 *
 *   1. Signature — RS256 against Google's public x509 certs for the
 *      `securetoken@system.gserviceaccount.com` service account. Certs
 *      are fetched from Google's well-known endpoint and cached for the
 *      duration Google's Cache-Control allows (rotated ~daily).
 *   2. `iss` MUST equal https://securetoken.google.com/<projectId>.
 *   3. `aud` MUST equal <projectId>.
 *   4. `exp` MUST be in the future (enforced by JWT::decode).
 *
 * Any failure throws {@see SocialTokenVerificationException} with a
 * generic message — the caller turns that into one uniform 401, so the
 * token-state oracle never leaks.
 *
 * Claims returned
 * ---------------
 * On success we hand back a {@see VerifiedSocialIdentity}:
 *   - provider:     'google' | 'apple'  (mapped from
 *                   firebase.sign_in_provider google.com / apple.com)
 *   - providerUid:  the provider's subject id from firebase.identities
 *                   (falls back to the token `sub`)
 *   - email / email_verified / name as asserted by the token
 *
 * Compile-safety
 * --------------
 * Constructor takes only scalars + interfaces (no closures, no object
 * defaults), so the PHP-DI compiled container can build it via the
 * factory in config/di.php.
 */
final class FirebaseIdTokenVerifier
{
    /**
     * Google's well-known endpoint serving the current securetoken
     * signing certs as a JSON map of kid => PEM x509 certificate.
     */
    private const CERT_URL =
        'https://www.googleapis.com/robot/v1/metadata/x509/securetoken@system.gserviceaccount.com';

    private const ISSUER_PREFIX = 'https://securetoken.google.com/';

    /** Fallback TTL (seconds) when the cert response carries no usable max-age. */
    private const DEFAULT_CERT_TTL = 3600;

    private const CACHE_KEY = 'firebase_securetoken_x509_certs';

    public function __construct(
        private readonly CacheItemPoolInterface $cache,
        private readonly ClientInterface $httpClient,
        private readonly string $projectId,
    ) {
    }

    /**
     * Verify a Firebase ID token. Returns the trusted identity, or
     * throws {@see SocialTokenVerificationException} on any failure.
     */
    public function verify(string $idToken): VerifiedSocialIdentity
    {
        if ($this->projectId === '') {
            // Misconfiguration — refuse to "verify" against an empty
            // audience/issuer (which would otherwise accept tokens for
            // any project). Generic exception; the real cause is logged
            // upstream via the chained throwable on the caller side.
            throw SocialTokenVerificationException::invalid(
                new \RuntimeException('Firebase project id is not configured.'),
            );
        }

        try {
            $keys = $this->buildKeySet();
            $payload = JWT::decode($idToken, $keys);
        } catch (\Throwable $e) {
            // Bad signature / expired / malformed / unfetchable certs —
            // all collapse to one opaque failure.
            throw SocialTokenVerificationException::invalid($e);
        }

        $this->assertClaims($payload);

        return $this->extractIdentity($payload);
    }

    /**
     * Build the kid => Key map from Google's certs (cached).
     *
     * @return array<string, Key>
     */
    private function buildKeySet(): array
    {
        $certs = $this->fetchCerts();

        $keys = [];
        foreach ($certs as $kid => $pem) {
            if (is_string($kid) && is_string($pem) && $pem !== '') {
                $keys[$kid] = new Key($pem, 'RS256');
            }
        }

        if ($keys === []) {
            throw new \RuntimeException('No usable Firebase signing certificates.');
        }

        return $keys;
    }

    /**
     * Fetch the kid => PEM cert map, honouring HTTP caching.
     *
     * @return array<string, string>
     */
    private function fetchCerts(): array
    {
        $item = $this->cache->getItem(self::CACHE_KEY);
        if ($item->isHit()) {
            /** @var array<string, string> $cached */
            $cached = $item->get();
            if (is_array($cached) && $cached !== []) {
                return $cached;
            }
        }

        $response = $this->httpClient->request('GET', self::CERT_URL);
        $body = (string) $response->getBody();

        /** @var mixed $decoded */
        $decoded = json_decode($body, true);
        if (!is_array($decoded) || $decoded === []) {
            throw new \RuntimeException('Firebase cert endpoint returned no certificates.');
        }

        /** @var array<string, string> $certs */
        $certs = [];
        foreach ($decoded as $kid => $pem) {
            if (is_string($kid) && is_string($pem)) {
                $certs[$kid] = $pem;
            }
        }

        $ttl = $this->maxAgeFromCacheControl($response->getHeaderLine('Cache-Control'));

        $item->set($certs);
        $item->expiresAfter($ttl);
        $this->cache->save($item);

        return $certs;
    }

    /**
     * Parse `max-age` (seconds) out of a Cache-Control header. Falls
     * back to {@see DEFAULT_CERT_TTL} when absent or unparseable.
     */
    private function maxAgeFromCacheControl(string $cacheControl): int
    {
        if (preg_match('/max-age=(\d+)/i', $cacheControl, $m) === 1) {
            $maxAge = (int) $m[1];
            if ($maxAge > 0) {
                return $maxAge;
            }
        }

        return self::DEFAULT_CERT_TTL;
    }

    /**
     * Enforce iss + aud. `exp` is already enforced by JWT::decode.
     */
    private function assertClaims(\stdClass $payload): void
    {
        $expectedIss = self::ISSUER_PREFIX . $this->projectId;

        $iss = isset($payload->iss) && is_string($payload->iss) ? $payload->iss : '';
        $aud = isset($payload->aud) && is_string($payload->aud) ? $payload->aud : '';

        if (!hash_equals($expectedIss, $iss)) {
            throw SocialTokenVerificationException::invalid(
                new \RuntimeException('Token issuer mismatch.'),
            );
        }

        if (!hash_equals($this->projectId, $aud)) {
            throw SocialTokenVerificationException::invalid(
                new \RuntimeException('Token audience mismatch.'),
            );
        }

        // `sub` is the Firebase user id — required to identify the user.
        if (!isset($payload->sub) || !is_string($payload->sub) || $payload->sub === '') {
            throw SocialTokenVerificationException::invalid(
                new \RuntimeException('Token is missing subject.'),
            );
        }
    }

    /**
     * Map verified claims to our normalised identity DTO.
     */
    private function extractIdentity(\stdClass $payload): VerifiedSocialIdentity
    {
        $firebase = $payload->firebase ?? null;

        $signInProvider = '';
        if (is_object($firebase) && isset($firebase->sign_in_provider) && is_string($firebase->sign_in_provider)) {
            $signInProvider = $firebase->sign_in_provider;
        }

        $provider = match ($signInProvider) {
            'google.com' => \Bayti\Api\Domain\User\SocialIdentity::PROVIDER_GOOGLE,
            'apple.com'  => \Bayti\Api\Domain\User\SocialIdentity::PROVIDER_APPLE,
            default      => null,
        };

        if ($provider === null) {
            // Some other Firebase provider (password, phone, facebook…)
            // — not a social provider we support here.
            throw SocialTokenVerificationException::invalid(
                new \RuntimeException("Unsupported sign-in provider: {$signInProvider}"),
            );
        }

        // provider_uid: prefer the provider's own subject id from
        // firebase.identities[provider.com][0]; fall back to the Firebase
        // token sub (always present, asserted above).
        $providerUid = $this->providerUidFromIdentities($firebase, $signInProvider)
            ?? (string) $payload->sub;

        $email = isset($payload->email) && is_string($payload->email) && $payload->email !== ''
            ? $payload->email
            : null;

        $emailVerified = isset($payload->email_verified) && $payload->email_verified === true;

        $name = isset($payload->name) && is_string($payload->name) && $payload->name !== ''
            ? $payload->name
            : null;

        return new VerifiedSocialIdentity(
            provider: $provider,
            providerUid: $providerUid,
            email: $email,
            emailVerified: $emailVerified,
            name: $name,
        );
    }

    /**
     * Pull the provider-native uid out of firebase.identities, e.g.
     *   firebase.identities['google.com'][0].
     */
    private function providerUidFromIdentities(mixed $firebase, string $signInProvider): ?string
    {
        if (!is_object($firebase) || !isset($firebase->identities) || !is_object($firebase->identities)) {
            return null;
        }

        $identities = $firebase->identities;
        if (!isset($identities->{$signInProvider})) {
            return null;
        }

        $list = $identities->{$signInProvider};
        if (is_array($list) && isset($list[0]) && is_string($list[0]) && $list[0] !== '') {
            return $list[0];
        }

        return null;
    }
}
