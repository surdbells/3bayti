<?php

declare(strict_types=1);

namespace Bayti\Api\Infrastructure\Otp;

use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * In-memory OTP provider — used in dev + tests; no network calls.
 *
 * Behavior
 * --------
 * send() returns a fresh fake verificationId and tracks it
 *   internally as "pending". The phone is logged so developers can
 *   see in their dev console that a send happened.
 *
 * verify() accepts the user-supplied code if it matches:
 *   1. A code previously set via setExpectedCode() for this
 *      verificationId, OR
 *   2. The default test code (configurable; defaults to '000000')
 *
 * Default test code
 * -----------------
 * '000000' — chosen because it's:
 *   - Easy to remember during dev
 *   - Obviously a test value (no real OTP would be all zeros)
 *   - Non-conflicting with any pattern a real CPaaS might generate
 *
 * Production safety
 * -----------------
 * This provider is bound in DI ONLY when APP_ENV is 'dev' or
 * 'test'. The MessageCentral provider activates for 'prod'. There's
 * no path for InMemoryOtpProvider to leak into production — the DI
 * factory throws if APP_ENV=prod and the in-memory adapter is
 * specified.
 */
final class InMemoryOtpProvider implements OtpProvider
{
    /** @var array<string, string> verificationId → expected code */
    private array $expectedCodes = [];

    /** @var string[] verificationIds in order of issuance, for inspection */
    private array $issuedVerifications = [];

    /** @var array<string, string> verificationId → phone, for inspection */
    private array $phoneByVerificationId = [];

    public function __construct(
        private readonly string $defaultAcceptCode = '000000',
        private readonly LoggerInterface $logger = new NullLogger(),
    ) {
    }

    public function send(string $toPhone): string
    {
        $verificationId = 'inmem-' . bin2hex(random_bytes(12));
        $this->issuedVerifications[] = $verificationId;
        $this->phoneByVerificationId[$verificationId] = $toPhone;

        $this->logger->info('OTP captured (in-memory)', [
            'verification_id' => $verificationId,
            'phone' => $toPhone,
            'accept_code' => $this->expectedCodes[$verificationId] ?? $this->defaultAcceptCode,
        ]);

        return $verificationId;
    }

    public function verify(string $verificationId, string $code): bool
    {
        $expected = $this->expectedCodes[$verificationId] ?? $this->defaultAcceptCode;
        return hash_equals($expected, $code);
    }

    /**
     * Test helper: pre-set the code that this verificationId will
     * accept. Use when a test wants to drive a specific value
     * through the verify path.
     *
     * If not called, verify() accepts the defaultAcceptCode ('000000').
     */
    public function setExpectedCode(string $verificationId, string $code): void
    {
        $this->expectedCodes[$verificationId] = $code;
    }

    /**
     * Test introspection: was send() called for a phone, and what
     * verificationId did we hand back?
     */
    public function latestVerificationFor(string $phone): ?string
    {
        foreach (array_reverse($this->issuedVerifications) as $vid) {
            if (($this->phoneByVerificationId[$vid] ?? null) === $phone) {
                return $vid;
            }
        }
        return null;
    }

    /** @return string[] All verificationIds issued, oldest first. */
    public function allIssued(): array
    {
        return $this->issuedVerifications;
    }

    public function reset(): void
    {
        $this->expectedCodes = [];
        $this->issuedVerifications = [];
        $this->phoneByVerificationId = [];
    }
}
