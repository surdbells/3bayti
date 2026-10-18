<?php

declare(strict_types=1);

namespace Bayti\Api\Infrastructure\Otp;

/**
 * Told about every OTP send outcome by the RoutingOtpProvider, so provider
 * ACCOUNT problems (out of credit, pricing not set up, credentials rejected,
 * no provider at all) can be alerted on — see OtpProviderHealthMonitor.
 *
 * Contract: implementations MUST NOT throw and MUST stay cheap on the success
 * path — they run inside the customer's OTP request. (The router still guards
 * every call, as a second line of defence.)
 *
 * Privacy: implementations are never given the phone number — only the
 * destination's dial code (OtpDestination::dialCode), e.g. "973".
 */
interface OtpProviderHealthObserver
{
    /** Provider key used when the router had no provider it could even try. */
    public const NO_PROVIDER_KEY = 'none';

    /**
     * A provider failed to send an OTP.
     *
     * @param string      $provider    router key of the provider that failed
     *                                 ('messagecentral', 'cequens', or NO_PROVIDER_KEY)
     * @param string|null $handledBy   router key of the BACKUP provider that then
     *                                 delivered the code for this same request, or
     *                                 null when the customer's request failed
     * @param string|null $destination dial code of the destination country
     *                                 ("973"), null when unknown
     */
    public function recordFailure(
        string $provider,
        OtpProviderException $e,
        ?string $handledBy = null,
        ?string $destination = null,
    ): void;

    /**
     * A provider delivered an OTP successfully. Called on every successful send.
     *
     * @param string|null $destination dial code of the destination country, null when unknown
     */
    public function recordSuccess(string $provider, ?string $destination = null): void;
}
