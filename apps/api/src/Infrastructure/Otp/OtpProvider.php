<?php

declare(strict_types=1);

namespace Bayti\Api\Infrastructure\Otp;

/**
 * OTP delivery + verification provider, abstracts the CPaaS.
 *
 * Implementations:
 *   - InMemoryOtpProvider, used in dev + tests; no real network
 *     calls; tracks verifications in-memory and accepts a known
 *     test code (default '000000') for predictable test flows.
 *   - MessageCentralOtpProvider, production adapter; HTTP calls
 *     to MessageCentral CPaaS for both send and verify.
 *
 * The provider does NOT touch the database. It only handles the
 * CPaaS-side interactions. The OtpService (one layer up) wraps
 * this with persistence + rate limiting + audit logging.
 *
 * Why an interface and not just a class
 * -------------------------------------
 * Three reasons:
 *   1. Tests can swap in InMemoryOtpProvider without mocking HTTP.
 *   2. Local dev can use InMemoryOtpProvider so developers don't
 *      need MessageCentral creds to test the full register flow.
 *   3. If we ever change CPaaS providers (Twilio, Vonage, etc.)
 *      it's a matter of writing one more class.
 */
interface OtpProvider
{
    /**
     * Ask the CPaaS to send an OTP to a phone number.
     *
     * @param string $toPhone E.164 format with leading '+', e.g.
     *                        '+971501234567'. Adapters may strip
     *                        the '+' or split country code as their
     *                        provider expects.
     *
     * @return string The verificationId returned by the CPaaS.
     *                Opaque token tied to the SMS just sent. Caller
     *                must persist this and present it to verify().
     *
     * @throws OtpProviderException When the CPaaS rejects the send
     *                              (network failure, invalid number,
     *                              account out of credits, etc.).
     */
    public function send(string $toPhone): string;

    /**
     * Ask the CPaaS to verify a user-supplied code against a
     * previously-sent OTP.
     *
     * @param string $verificationId The id returned by send()
     * @param string $code           The 6-digit code the user entered
     *
     * @return bool true if the code matches; false otherwise.
     *              The CPaaS handles its own attempt counter
     *              (typically 5 retries) and returns false once
     *              exhausted; we propagate that as false here.
     *              Callers don't need to distinguish "wrong code"
     *              from "out of attempts", both are "user can't
     *              proceed; ask them to request a new OTP".
     *
     * @throws OtpProviderException When the CPaaS itself fails
     *                              (network, etc.). Distinct from
     *                              "code didn't match" which is just
     *                              a false return.
     */
    public function verify(string $verificationId, string $code): bool;
}
