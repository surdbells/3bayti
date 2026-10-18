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
 *
 * Provider health
 * ---------------
 * An optional OtpProviderHealthObserver (OtpProviderHealthMonitor in prod) is
 * told the OUTCOME of each send once it is known: every provider failure — with
 * the backup provider that then delivered the code, or null if the request
 * failed — then recordSuccess() for the winner; a send with no provider to try
 * at all is a 'no_provider' failure under OtpProviderHealthObserver::NO_PROVIDER_KEY.
 * Each call carries the destination's dial code (OtpDestination), never the
 * phone number. That is what emails the admin team when an account runs out of
 * credit etc. Observer calls are guarded: they can never change what send()
 * returns or throws.
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
     * @param OtpProviderHealthObserver|null $healthObserver told every send's
     *        outcome (account-problem alerting); null = no monitoring
     */
    public function __construct(
        array $providers,
        callable $enabledOrderResolver,
        private readonly string $defaultProvider = 'messagecentral',
        private readonly LoggerInterface $logger = new NullLogger(),
        private readonly ?OtpProviderHealthObserver $healthObserver = null,
    ) {
        $this->providers = $providers;
        $this->enabledOrderResolver = \Closure::fromCallable($enabledOrderResolver);
    }

    public function send(string $toPhone): string
    {
        /** @var list<array{0: string, 1: OtpProviderException}> $failures */
        $failures = [];
        // Only the destination COUNTRY reaches the health observer, never the
        // number (pricing problems are per destination country).
        $destination = $this->healthObserver !== null ? OtpDestination::dialCode($toPhone) : null;
        foreach ($this->resolveOrder() as $key) {
            $provider = $this->providers[$key] ?? null;
            if ($provider === null) {
                // Configured but not wired (e.g. missing creds) — skip.
                continue;
            }
            try {
                $verificationId = $provider->send($toPhone);
            } catch (OtpProviderException $e) {
                $this->logger->warning('otp.provider.send_failed', [
                    'provider' => $key,
                    'error' => $e->getMessage(),
                ]);
                $failures[] = [$key, $e];
                continue;
            }

            // Delivered. Earlier failures (if any) were covered by this backup.
            $this->reportOutcome($failures, $key, $destination);
            return $key . ':' . $verificationId;
        }

        if ($failures === []) {
            // Nothing could even be tried.
            $noProvider = new OtpProviderException(
                'no_provider',
                'No OTP provider is available to send the code.',
            );
            $this->reportOutcome([[OtpProviderHealthObserver::NO_PROVIDER_KEY, $noProvider]], null, $destination);
            throw $noProvider;
        }

        // Every enabled+wired provider failed: rethrow the last error (unchanged).
        $this->reportOutcome($failures, null, $destination);
        throw $failures[count($failures) - 1][1];
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
     * Tell the health observer how this send went. Guarded per call: the
     * observer must never change the OTP result (it is designed not to throw;
     * this is the second line of defence).
     *
     * @param list<array{0: string, 1: OtpProviderException}> $failures
     * @param string|null $deliveredBy provider that delivered the code, or null
     * @param string|null $destination destination dial code ("973"), never the number
     */
    private function reportOutcome(array $failures, ?string $deliveredBy, ?string $destination): void
    {
        if ($this->healthObserver === null) {
            return;
        }
        foreach ($failures as [$key, $e]) {
            try {
                $this->healthObserver->recordFailure($key, $e, $deliveredBy, $destination);
            } catch (\Throwable $t) {
                $this->logger->warning('otp.provider.health_observer_failed', [
                    'provider' => $key,
                    'error' => $t->getMessage(),
                ]);
            }
        }
        if ($deliveredBy !== null) {
            try {
                $this->healthObserver->recordSuccess($deliveredBy, $destination);
            } catch (\Throwable $t) {
                $this->logger->warning('otp.provider.health_observer_failed', [
                    'provider' => $deliveredBy,
                    'error' => $t->getMessage(),
                ]);
            }
        }
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
