<?php

declare(strict_types=1);

namespace Bayti\Api\Domain\User;

use Bayti\Api\Infrastructure\Cache\KeyValueStore;
use Bayti\Api\Infrastructure\Cache\KeyValueStoreException;
use Bayti\Api\Infrastructure\Otp\OtpProvider;
use Bayti\Api\Infrastructure\Otp\OtpProviderException;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * OTP orchestrator — the seam between auth endpoints and the CPaaS.
 *
 * Responsibilities:
 *   1. Rate-limit OTP sends per phone (Redis-backed atomic INCR; M1.6.1.A)
 *   2. Call the CPaaS provider to send / verify
 *   3. Persist OtpAttempt rows for audit + later lookup
 *   4. Translate provider-level errors into domain-meaningful errors
 *
 * What this class does NOT do:
 *   - Generate OTP codes (CPaaS does that)
 *   - Verify codes locally (CPaaS does that)
 *   - Send the SMS itself (CPaaS does that)
 *   - Manage user lifecycle (caller's job — register/login/reset
 *     handlers know what user state to update on successful verify)
 *
 * Usage from auth endpoints (M1.4):
 *
 *   // /v3/auth/send-otp
 *   $vid = $otp->send('+971501234567', OtpAttempt::PURPOSE_REGISTRATION);
 *   return ['verification_id' => $vid];
 *
 *   // /v3/auth/confirm
 *   $ok = $otp->verify($verificationId, $code);
 *   if ($ok) { ... activate user ... }
 *
 * Rate limiting evolution
 * -----------------------
 * M1.3 (original): SELECT COUNT FROM user_otp_attempts then INSERT.
 *                  Worked across workers (DB is shared) but had a
 *                  race window — two concurrent calls could both
 *                  pass the threshold check before either inserted.
 *                  Bounded race; not exploitable in practice.
 *
 * M1.6.1.A (now):  Redis INCR (atomic) for the rate-limit counter,
 *                  Postgres INSERT for the audit row. The race
 *                  window is gone — INCR returns sequential values
 *                  to concurrent callers, so only the right number
 *                  of requests can pass the threshold.
 *
 * Why keep the DB row insert
 * --------------------------
 * The Redis counter is for rate-limit decisions. The DB row is for
 * audit / forensics ("who tried to register with this phone in the
 * last week, in what order?"). Each layer optimizes for its own
 * concern.
 *
 * Fail-open on Redis failure
 * --------------------------
 * If Redis throws (down, network error, auth failed), we log the
 * error and proceed without the rate limit. Reasoning:
 *
 *   - A rate limit is defense in depth, not the only defense
 *   - MessageCentral itself rate-limits per phone on their side
 *   - Refusing all OTP sends because Redis is restarting is worse
 *     for users than briefly relaxed limits
 *   - Outages get logged + Sentry-alerted (when M1.6.2 ships) so
 *     ops can fix Redis fast
 *
 * Bounded blast radius: rate-limit bypass during a Redis outage is
 * limited by the outage duration AND by MessageCentral's per-phone
 * caps on their end.
 *
 * Counter semantics
 * -----------------
 * Redis key format: "otp:rl:<phone>"
 * Value: count of sends in the rolling window
 * TTL: 3600s, set on first INCR (count == 1)
 *
 * The TTL refresh on first hit gives us a sliding window of sorts —
 * an attacker hitting at t=0, t=600, t=1200 will see the counter
 * grow 1, 2, 3 with each call (TTL doesn't reset on subsequent hits).
 * After t=3600 the key auto-expires and the next call resets to 1.
 *
 * Note: this is NOT a true sliding window. A true sliding window
 * would use a Redis sorted set with timestamps and ZRANGEBYSCORE.
 * The bucket-with-TTL approach has at most a 2x window edge case
 * (3 sends at t=3599, then 3 more at t=3601 — 6 sends in 2 sec).
 * Acceptable for a soft cap on SMS cost; if we ever need true
 * sliding window for a security-critical limit, swap implementations.
 */
final class OtpService
{
    /**
     * How long an OTP stays valid. Mirrors MessageCentral's TTL
     * (configurable on their side per account; typically 5 min).
     * If they extend it, we should bump this too so our findLatestUsable
     * doesn't return rows MessageCentral would still accept.
     */
    private const DEFAULT_TTL_SECONDS = 300;

    /**
     * Max sends per phone per rolling hour. Enforced via Redis INCR
     * with a 3600s TTL. Soft cap against:
     *   - Accidental UI loops (user clicks "resend OTP" 50 times)
     *   - Bots scraping the registration endpoint
     *   - Cost protection for our SMS bill
     */
    private const SENDS_PER_HOUR = 3;

    /** Rolling window length for the rate limit (seconds). */
    private const RATE_LIMIT_WINDOW_SECONDS = 3600;

    /** Redis key prefix for OTP rate-limit counters. */
    private const RATE_LIMIT_KEY_PREFIX = 'otp:rl:';

    private readonly LoggerInterface $logger;

    public function __construct(
        private readonly OtpProvider $provider,
        private readonly EntityManagerInterface $em,
        private readonly KeyValueStore $cache,
        ?LoggerInterface $logger = null,
    ) {
        // Logger defaults to NullLogger so callers (including tests)
        // don't have to wire one explicitly. M1.6.2 introduces a
        // proper Monolog binding via DI; until then, this lets us
        // emit warnings without forcing the dependency.
        $this->logger = $logger ?? new NullLogger();
    }

    /**
     * Issue an OTP — ask the CPaaS to send, persist a tracking row.
     *
     * @return string the verificationId returned by the CPaaS
     *
     * @throws OtpRateLimitException When the per-phone rate limit
     *                                is exceeded.
     * @throws OtpProviderException  When the CPaaS rejects the send.
     */
    public function send(
        string $phone,
        string $purpose,
        ?User $user = null,
        ?string $requestedIp = null,
    ): string {
        if (!in_array($purpose, OtpAttempt::ALL_PURPOSES, true)) {
            throw new \InvalidArgumentException("Unknown OTP purpose: {$purpose}");
        }

        // Rate limit check via Redis. Atomic INCR returns the new
        // count after this hit. If it exceeds the cap, refuse.
        // On Redis failure, we log and fail open — see class
        // docblock for the rationale.
        $this->enforceRateLimit($phone);

        // Ask CPaaS to send. If this throws, we don't insert any
        // local record — provider failures don't pollute our audit
        // trail with sends that didn't happen.
        //
        // Note: the Redis counter has already been incremented at this
        // point. A failed CPaaS send still consumes a "send slot" from
        // the rate limit. That's a deliberate trade-off: it makes the
        // limit a "send attempts" cap rather than "successful sends",
        // which is the right semantic for cost / abuse protection
        // (each attempt costs us a CPaaS call regardless of outcome).
        $verificationId = $this->provider->send($phone);

        // Persist the local audit row. Single transaction — flush
        // after both the row and the provider call have succeeded.
        $expiresAt = (new DateTimeImmutable())->modify('+' . self::DEFAULT_TTL_SECONDS . ' seconds');
        /** @var OtpAttemptRepository $repo */
        $repo = $this->em->getRepository(OtpAttempt::class);
        $attempt = new OtpAttempt(
            verificationId: $verificationId,
            phone: $phone,
            purpose: $purpose,
            expiresAt: $expiresAt,
            user: $user,
            requestedIp: $requestedIp,
        );
        $repo->save($attempt);

        return $verificationId;
    }

    /**
     * Atomically increment the rate-limit counter for $phone and
     * throw OtpRateLimitException if the cap is exceeded.
     *
     * On any Redis failure, log a warning and return without
     * throwing — fail-open behaviour. See class docblock.
     */
    private function enforceRateLimit(string $phone): void
    {
        $key = self::RATE_LIMIT_KEY_PREFIX . $phone;

        try {
            $count = $this->cache->incr($key);

            // First hit in this window — set the TTL. Subsequent
            // INCRs in the same window don't refresh the TTL, which
            // is what we want (rolling window, not session reset).
            if ($count === 1) {
                $this->cache->expire($key, self::RATE_LIMIT_WINDOW_SECONDS);
            }

            if ($count > self::SENDS_PER_HOUR) {
                throw new OtpRateLimitException(
                    sprintf(
                        'Too many OTP requests for %s in the last hour (%d of %d allowed).',
                        $phone,
                        $count,
                        self::SENDS_PER_HOUR,
                    ),
                );
            }
        } catch (KeyValueStoreException $e) {
            // Redis is down or unreachable. Fail open — log and
            // allow the request. The OtpRateLimitException is
            // re-raised above; only Redis-infrastructure errors
            // hit this branch.
            $this->logger->warning(
                'OTP rate-limit cache failure — failing open',
                [
                    'phone' => $phone,
                    'error' => $e->getMessage(),
                ],
            );
            // Fall through to the CPaaS send.
        }
    }

    /**
     * Verify a user-supplied code against a previously-sent OTP.
     *
     * Returns:
     *   - VerifyResult::Success if MessageCentral confirms the code
     *   - VerifyResult::WrongCode if MessageCentral rejects it
     *   - VerifyResult::Expired if our local row is past its TTL
     *   - VerifyResult::Consumed if our local row is already used
     *   - VerifyResult::NotFound if the verificationId isn't ours
     *
     * Caller should treat all non-Success results as 401 Unauthorized
     * with a uniform "code didn't match" user message — distinguishing
     * "wrong" from "expired" is a UX nicety, not security.
     *
     * @throws OtpProviderException When CPaaS itself fails
     */
    public function verify(string $verificationId, string $code): VerifyResult
    {
        /** @var OtpAttemptRepository $repo */
        $repo = $this->em->getRepository(OtpAttempt::class);
        $attempt = $repo->findByVerificationId($verificationId);

        if ($attempt === null) {
            return VerifyResult::NotFound;
        }
        if ($attempt->isConsumed()) {
            return VerifyResult::Consumed;
        }
        if ($attempt->isExpired()) {
            return VerifyResult::Expired;
        }

        // Delegate the actual code comparison to the CPaaS.
        $ok = $this->provider->verify($verificationId, $code);
        if (!$ok) {
            return VerifyResult::WrongCode;
        }

        // Mark consumed and persist. Caller can do further user-state
        // updates (mark phone verified, etc.) in their own transaction.
        $attempt->markConsumed();
        $this->em->flush();

        return VerifyResult::Success;
    }

    /**
     * Find the OtpAttempt row for a verificationId — useful for
     * controllers that need to bind a user to an OTP after
     * successful verification (e.g. linking the OTP to the User
     * row that was just created during /v3/auth/confirm).
     */
    public function findAttempt(string $verificationId): ?OtpAttempt
    {
        /** @var OtpAttemptRepository $repo */
        $repo = $this->em->getRepository(OtpAttempt::class);
        return $repo->findByVerificationId($verificationId);
    }
}
