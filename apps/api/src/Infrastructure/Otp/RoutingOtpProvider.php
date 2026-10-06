<?php

declare(strict_types=1);

namespace Bayti\Api\Infrastructure\Otp;

use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * Multi-provider OTP router with priority failover.
 *
 * Holds a NAMED map of concrete providers (e.g. 'messagecentral', 'cequens')
 * and, on each send, tries the admin-configured ENABLED providers in PRIORITY
 * order, moving to the next when one raises an OtpProviderException. The first
 * success wins. This makes a single provider outage (e.g. MessageCentral's
 * "Pricing not found") non-fatal: delivery falls through to the next provider.
 *
 * Verify routing
 * --------------
 * send() returns a KEY-PREFIXED verification id, "<provider>:<id>", so verify()
 * knows which provider issued the code and routes the check back to it — the
 * verification id is opaque and provider-specific, so it MUST be validated by
 * the same provider. Ids with no recognised prefix (issued before this shipped,
 * or otherwise) fall back to the default provider for backward compatibility.
 * (verification_id is varchar(100) unique — the short prefix fits comfortably.)
 *
 * Transparency
 * ------------
 * Implements OtpProvider, so OtpService and every auth path use it unchanged;
 * all the rate-limiting, cooldown/dedup, OtpAttempt persistence and the 429
 * contract live one layer up and are untouched. The provider ORDER is read live
 * (per send) via the injected resolver, so an admin re-ordering takes effect on
 * the next send with no redeploy.
 */
final class RoutingOtpProvider implements OtpProvider
{
    /** @var array<string, OtpProvider> */
    private readonly array $providers;

    /** @var \Closure(): list<string> */
    private readonly \Closure $enabledOrderResolver;

    /**
     * @param array<string, OtpProvider> $providers keyed by provider name, in
     *        the DEFAULT priority order (used when the resolver yields nothing)
     * @param callable(): list<string> $enabledOrderResolver returns the ENABLED
     *        provider keys in priority order (admin-configured). Read per send.
     * @param string $defaultProvider provider that validates legacy/unprefixed ids
     */
    public function __construct(
        array $providers,
        callable $enabledOrderResolver,
        private readonly string $defaultProvider = 'messagecentral',
        private readonly LoggerInterface $logger = new NullLogger(),
    ) {
        $this->providers = $providers;
        $this->enabledOrderResolver = \Closure::fromCallable($enabledOrderResolver);
    }

    public function send(string $toPhone): string
    {
        $last = null;
        foreach ($this->resolveOrder() as $key) {
            $provider = $this->providers[$key] ?? null;
            if ($provider === null) {
                // Configured but not wired (e.g. missing creds) — skip.
                continue;
            }
            try {
                return $key . ':' . $provider->send($toPhone);
            } catch (OtpProviderException $e) {
                $this->logger->warning('otp.provider.send_failed', [
                    'provider' => $key,
                    'error' => $e->getMessage(),
                ]);
                $last = $e;
            }
        }

        // Every enabled+wired provider failed (or none is available).
        throw $last ?? new OtpProviderException(
            'no_provider',
            'No OTP provider is available to send the code.',
        );
    }

    public function verify(string $verificationId, string $code): bool
    {
        [$key, $realId] = $this->splitId($verificationId);
        $provider = $this->providers[$key] ?? $this->providers[$this->defaultProvider] ?? null;
        if ($provider === null) {
            // Can't route (unknown provider, and no default wired) — treat as a
            // failed verification rather than a 5xx; the user requests a new OTP.
            $this->logger->warning('otp.provider.verify_unroutable', [
                'provider' => $key,
            ]);
            return false;
        }

        return $provider->verify($realId, $code);
    }

    /**
     * The enabled providers in priority order, restricted to ones we actually
     * hold. Falls back to every wired provider (in default order) when the
     * resolver yields nothing usable, so a missing/blank config never disables
     * OTP entirely.
     *
     * @return list<string>
     */
    private function resolveOrder(): array
    {
        $resolved = [];
        foreach (($this->enabledOrderResolver)() as $key) {
            if (isset($this->providers[$key]) && !in_array($key, $resolved, true)) {
                $resolved[] = $key;
            }
        }

        return $resolved !== [] ? $resolved : array_keys($this->providers);
    }

    /**
     * Split "<provider>:<id>" into [providerKey, realId]. An id with no
     * recognised provider prefix is treated as the default provider's (legacy
     * ids issued before prefixing, in-flight across the deploy).
     *
     * @return array{0: string, 1: string}
     */
    private function splitId(string $verificationId): array
    {
        $pos = strpos($verificationId, ':');
        if ($pos !== false) {
            $key = substr($verificationId, 0, $pos);
            if (isset($this->providers[$key])) {
                return [$key, substr($verificationId, $pos + 1)];
            }
        }

        return [$this->defaultProvider, $verificationId];
    }
}
