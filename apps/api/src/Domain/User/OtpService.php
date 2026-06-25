<?php

declare(strict_types=1);

namespace Bayti\Api\Domain\User;

use Bayti\Api\Infrastructure\Cache\KeyValueStore;
use Bayti\Api\Infrastructure\Cache\KeyValueStoreException;
use Bayti\Api\Infrastructure\Otp\LocalEmailOtpProvider;
use Bayti\Api\Infrastructure\Otp\OtpProvider;
use Bayti\Api\Infrastructure\Otp\OtpProviderException;
use Bayti\Api\Notification\MailerException;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * OTP orchestrator — the seam between auth endpoints and the CPaaS.
 *
 * Responsibilities:
 *   1. Abuse-harden OTP sends + verifies at ONE chokepoint so every
 *      auth path (otp-login, register/initiate, register email-OTP,
 *      send-otp resend, password reset, validate-phone) inherits it.
 *   2. Call the CPaaS / email provider to send + verify.
 *   3. Persist OtpAttempt rows for audit + later lookup.
 *   4. Translate provider-level errors into domain-meaningful errors.
 *
 * Abuse-hardening model (the M-hardening rework)
 * ----------------------------------------------
 * send() enforces, IN ORDER:
 *
 *   (a) Per-destination cooldown + code reuse. If a send to this
 *       destination+purpose happened < cooldown ago, we DO NOT dispatch
 *       a new SMS/email — we re-use the latest usable (unexpired,
 *       unconsumed) OtpAttempt and return its verification_id (true
 *       resend-dedup). Last-send is tracked via a Redis cooldown key
 *       AND the OtpAttempt.created_at, so it works even without Redis.
 *
 *   (b) Per-destination hourly + daily caps (Redis INCR, two keys).
 *
 *   (c) Per-IP hourly + daily caps (Redis INCR). Skipped entirely when
 *       the client IP can't be resolved — never block a legit user just
 *       because we couldn't read their IP.
 *
 * Each cap is ENV-CONFIGURABLE via OtpRateLimitConfig; a threshold of 0
 * disables that specific check.
 *
 * Fail-CLOSED on Redis error
 * --------------------------
 * The ORIGINAL design failed OPEN on a Redis error (unlimited sends).
 * That is dangerous for the EMAIL channel, where ZeptoMail has no
 * provider-side cap. We now fail CLOSED: on a Redis error we fall back
 * to a DB COUNT of recent sends to this destination within the window,
 * so the per-destination cap still holds. The per-IP check is skipped
 * on Redis error (no DB column to fall back on, and it's the generous
 * outer guard), but the per-destination cap — the one that actually
 * bounds cost + abuse to a single target — is preserved.
 *
 * Verify hardening
 * ----------------
 * verify() now mirrors the email attempt-cap for SMS: every failed
 * provider verify increments OtpAttempt.attempts and BURNS the row at
 * maxVerifyAttempts, instead of relying solely on MessageCentral. A
 * per-verification-id Redis counter additionally caps total guesses per
 * code so a single code can't be sprayed across processes. Hitting
 * either cap raises OtpRateLimitException (429 + retry_after).
 *
 * 429 contract
 * ------------
 * Every limit raises OtpRateLimitException carrying a retry_after
 * (seconds). ApiErrorMiddleware maps it to:
 *   HTTP 429 { "error_code": "OTP_RATE_LIMITED", "retry_after": <int> }
 *
 * Redis key formats
 * -----------------
 *   otp:cooldown:<purpose>:<dest> short-TTL last-send marker (dedup),
 *                                 scoped per destination+purpose so a
 *                                 registration send doesn't block a
 *                                 password-reset send to the same target
 *   otp:rl:<dest>                 per-destination hourly counter
 *   otp:rl:day:<dest>             per-destination daily counter
 *   otp:rl:ip:<ip>                per-IP hourly counter
 *   otp:rl:ip:day:<ip>            per-IP daily counter
 *   otp:vrl:<verificationId>      per-code verify-attempt counter
 */
final class OtpService
{
    /** OTP validity window. Mirrors MessageCentral's TTL (~5 min). */
    private const DEFAULT_TTL_SECONDS = 300;

    private const HOUR_SECONDS = 3600;
    private const DAY_SECONDS = 86400;

    // Redis key prefixes.
    private const COOLDOWN_KEY_PREFIX = 'otp:cooldown:';
    private const DEST_HOUR_KEY_PREFIX = 'otp:rl:';
    private const DEST_DAY_KEY_PREFIX = 'otp:rl:day:';
    private const IP_HOUR_KEY_PREFIX = 'otp:rl:ip:';
    private const IP_DAY_KEY_PREFIX = 'otp:rl:ip:day:';
    private const VERIFY_KEY_PREFIX = 'otp:vrl:';

    private readonly LoggerInterface $logger;
    private readonly OtpRateLimitConfig $config;

    public function __construct(
        private readonly OtpProvider $provider,
        private readonly EntityManagerInterface $em,
        private readonly KeyValueStore $cache,
        private readonly LocalEmailOtpProvider $emailProvider,
        ?OtpRateLimitConfig $config = null,
        ?LoggerInterface $logger = null,
    ) {
        // Both default to safe no-arg objects so existing test call
        // sites (which pass neither) keep working; the DI factory wires
        // the env-driven config + the Monolog logger explicitly.
        $this->config = $config ?? new OtpRateLimitConfig();
        $this->logger = $logger ?? new NullLogger();
    }

    /**
     * Issue an OTP — enforce the abuse limits, ask the provider to
     * send (unless dedup reuses a still-valid code), persist a tracking
     * row, and return the verificationId.
     *
     * @throws OtpRateLimitException When any send-side limit is exceeded.
     * @throws OtpProviderException  When the provider rejects the send.
     */
    public function send(
        string $to,
        string $purpose,
        string $channel = OtpAttempt::CHANNEL_SMS,
        ?User $user = null,
        ?string $requestedIp = null,
    ): string {
        if (!in_array($purpose, OtpAttempt::ALL_PURPOSES, true)) {
            throw new \InvalidArgumentException("Unknown OTP purpose: {$purpose}");
        }
        if (!in_array($channel, OtpAttempt::ALL_CHANNELS, true)) {
            throw new \InvalidArgumentException("Unknown OTP channel: {$channel}");
        }

        // (a) Resend-dedup: inside the cooldown, re-use the latest usable
        //     code instead of dispatching again. Returns early when a
        //     usable row exists; otherwise throws if we're still inside
        //     the cooldown with nothing to reuse (so we never dispatch
        //     within the cooldown).
        $reused = $this->dedupWithinCooldown($to, $purpose, $channel);
        if ($reused !== null) {
            return $reused;
        }

        // Cooldown key scoped per destination+purpose to match the dedup
        // lookup — a registration send must not block a password-reset
        // send to the same destination.
        $cooldownKey = self::COOLDOWN_KEY_PREFIX . $purpose . ':' . $to;

        // (a.2) ATOMIC COOLDOWN CLAIM — close the dedup race.
        //
        // dedupWithinCooldown() above is a READ; two near-simultaneous
        // sends to the same destination can both pass it (neither sees
        // the other's not-yet-committed row / not-yet-stamped marker) and
        // BOTH dispatch. We collapse that window by ATOMICALLY claiming
        // the cooldown key (Redis SET NX EX) BEFORE dispatching. Exactly
        // one concurrent caller wins the claim; the loser behaves like the
        // dedup-reuse branch (reuse the latest committed code, or 429 if
        // none is committed yet).
        $claimed = $this->claimCooldown($cooldownKey);
        if ($claimed === false) {
            // A concurrent / very-recent send already owns this window.
            // Do NOT dispatch. Reuse the latest committed usable code if
            // one exists; otherwise the racing send hasn't committed yet
            // and the contract is "wait" — 429 with the cooldown window.
            return $this->reuseOrThrowOnClaimContention($to, $purpose, $channel);
        }

        // (b) Per-destination hourly + daily caps.
        // (c) Per-IP hourly + daily caps (skipped when IP unresolved).
        //
        // If a cap trips (or dispatch fails below) we must RELEASE the
        // claim so a legit retry — after the cap window or a transient
        // provider error — isn't blocked by an orphaned marker.
        try {
            $this->enforceDestinationCaps($to, $channel);
            $this->enforceIpCaps($requestedIp);

            $verificationId = $channel === OtpAttempt::CHANNEL_EMAIL
                ? $this->sendEmail($to, $purpose, $user, $requestedIp)
                : $this->sendSms($to, $purpose, $user, $requestedIp);
        } catch (\Throwable $e) {
            // Release only when WE actually claimed the marker (claim ===
            // true; a null claim means Redis was unavailable and nothing
            // was stamped, so there is nothing to release).
            if ($claimed === true) {
                $this->releaseCooldown($cooldownKey);
            }
            throw $e;
        }

        return $verificationId;
    }

    /**
     * SMS dispatch via MessageCentral + persist the audit row.
     */
    private function sendSms(
        string $to,
        string $purpose,
        ?User $user,
        ?string $requestedIp,
    ): string {
        // Ask CPaaS to send. If this throws, we don't insert any local
        // record — provider failures don't pollute the audit trail.
        $verificationId = $this->provider->send($to);

        $expiresAt = (new DateTimeImmutable())->modify('+' . self::DEFAULT_TTL_SECONDS . ' seconds');
        /** @var OtpAttemptRepository $repo */
        $repo = $this->em->getRepository(OtpAttempt::class);
        $attempt = new OtpAttempt(
            verificationId: $verificationId,
            phone: $to,
            purpose: $purpose,
            expiresAt: $expiresAt,
            user: $user,
            requestedIp: $requestedIp,
            channel: OtpAttempt::CHANNEL_SMS,
        );
        $repo->save($attempt);

        return $verificationId;
    }

    /**
     * Email-channel send — delegate to LocalEmailOtpProvider (which
     * generates the code, persists the row with code_hash, and emails
     * the plaintext). Translate a transport failure into the same
     * OtpProviderException the SMS path raises.
     */
    private function sendEmail(
        string $email,
        string $purpose,
        ?User $user,
        ?string $requestedIp,
    ): string {
        $phone = $user?->getPhone() ?? '';

        try {
            return $this->emailProvider->send(
                email: $email,
                purpose: $purpose,
                phone: $phone,
                user: $user,
                requestedIp: $requestedIp,
            );
        } catch (MailerException $e) {
            throw new OtpProviderException('email_transport', $e->getMessage(), $e);
        }
    }

    // -------------------------------------------------------------------
    // (a) Resend cooldown + code reuse
    // -------------------------------------------------------------------

    /**
     * If a dispatch to this destination+purpose happened within the
     * cooldown, return the verification_id of the latest usable row
     * (true resend-dedup) rather than sending again.
     *
     * Returns:
     *   - the reusable verification_id when inside the cooldown AND a
     *     usable row exists (no new dispatch),
     *   - null when past the cooldown (caller proceeds to dispatch).
     *
     * Throws OtpRateLimitException only if we're inside the cooldown but
     * there's NO usable row to reuse — the contract says "never dispatch
     * within the cooldown", so the right answer there is "wait", not
     * "send a fresh code".
     *
     * Last-send detection uses the Redis cooldown key first (cheap,
     * cross-worker). On a Redis miss/error we fall back to the latest
     * row's created_at, so dedup still works without Redis.
     */
    private function dedupWithinCooldown(string $to, string $purpose, string $channel): ?string
    {
        $cooldown = $this->config->resendCooldownSeconds;
        if ($cooldown <= 0) {
            return null; // cooldown disabled
        }

        /** @var OtpAttemptRepository $repo */
        $repo = $this->em->getRepository(OtpAttempt::class);
        $latest = $repo->findLatestUsableForDestination($to, $purpose, $channel);

        $cooldownKey = self::COOLDOWN_KEY_PREFIX . $purpose . ':' . $to;
        $secondsSinceLastSend = $this->secondsSinceLastSend($cooldownKey, $latest);
        if ($secondsSinceLastSend === null || $secondsSinceLastSend >= $cooldown) {
            return null; // past cooldown (or never sent) — allow dispatch
        }

        // Inside the cooldown. Re-use the existing usable code if we
        // have one; otherwise force the client to wait out the window.
        if ($latest !== null) {
            return $latest->getVerificationId();
        }

        throw new OtpRateLimitException(
            'OTP resend requested within the cooldown window.',
            $this->retryAfter($cooldown - $secondsSinceLastSend),
        );
    }

    /**
     * Seconds since the last dispatch for $cooldownKey, or null if
     * unknown. Prefers the Redis cooldown marker (records the dispatch
     * time as a unix timestamp); falls back to the latest usable row's
     * created_at, so dedup still works without Redis.
     */
    private function secondsSinceLastSend(string $cooldownKey, ?OtpAttempt $latest): ?int
    {
        try {
            $marker = $this->cache->get($cooldownKey);
            if ($marker !== null && ctype_digit($marker)) {
                $elapsed = time() - (int) $marker;
                return $elapsed < 0 ? 0 : $elapsed;
            }
        } catch (KeyValueStoreException $e) {
            $this->logger->warning('OTP cooldown cache read failed — falling back to DB created_at', [
                'error' => $e->getMessage(),
            ]);
        }

        if ($latest !== null) {
            $elapsed = time() - $latest->getCreatedAt()->getTimestamp();
            return $elapsed < 0 ? 0 : $elapsed;
        }

        return null;
    }

    /**
     * Atomically CLAIM the cooldown window before dispatching, closing
     * the dedup race. Stamps otp:cooldown:<purpose>:<dest> = now via
     * Redis SET NX EX so exactly one concurrent caller can proceed.
     *
     * Returns:
     *   - true  : WE won the claim — proceed to dispatch (and release
     *             the marker if dispatch / a cap subsequently fails).
     *   - false : the key already existed — a concurrent/recent send owns
     *             the window; the caller must NOT dispatch.
     *   - null  : the cooldown is disabled, OR Redis was unavailable. In
     *             both cases we fall back to the prior behaviour (allow
     *             the dispatch; the DB created_at check still gates
     *             dedup) and there is nothing to release on failure.
     */
    private function claimCooldown(string $cooldownKey): ?bool
    {
        $cooldown = $this->config->resendCooldownSeconds;
        if ($cooldown <= 0) {
            return null; // cooldown disabled — nothing to claim
        }

        try {
            return $this->cache->setIfAbsent($cooldownKey, (string) time(), $cooldown);
        } catch (KeyValueStoreException $e) {
            // Redis unavailable — do NOT hard-fail sends. Fall back to the
            // pre-existing behaviour: the DB created_at check in
            // dedupWithinCooldown() still provides best-effort dedup.
            $this->logger->warning('OTP cooldown claim failed — proceeding without atomic claim', [
                'error' => $e->getMessage(),
            ]);
            return null;
        }
    }

    /**
     * Release a cooldown claim we made but could not honour (a cap
     * tripped, or the provider/mailer threw). Best-effort: a Redis error
     * here just leaves the marker to expire on its own TTL.
     */
    private function releaseCooldown(string $cooldownKey): void
    {
        try {
            $this->cache->delete($cooldownKey);
        } catch (KeyValueStoreException $e) {
            $this->logger->warning('OTP cooldown release failed', ['error' => $e->getMessage()]);
        }
    }

    /**
     * Lost the atomic cooldown claim → a concurrent send owns the window.
     * Behave exactly like the dedup-reuse branch: return the latest
     * committed usable verification_id if one exists, else (the racing
     * send hasn't committed its row yet) raise the standard 429 so the
     * client retries after the cooldown.
     */
    private function reuseOrThrowOnClaimContention(string $to, string $purpose, string $channel): string
    {
        /** @var OtpAttemptRepository $repo */
        $repo = $this->em->getRepository(OtpAttempt::class);
        $latest = $repo->findLatestUsableForDestination($to, $purpose, $channel);

        if ($latest !== null) {
            return $latest->getVerificationId();
        }

        throw new OtpRateLimitException(
            'OTP resend requested within the cooldown window.',
            $this->retryAfter($this->config->resendCooldownSeconds),
        );
    }

    // -------------------------------------------------------------------
    // (b) Per-destination caps
    // -------------------------------------------------------------------

    /**
     * Enforce the per-destination hourly + daily send caps. On a Redis
     * error we fail CLOSED via a DB count within the window.
     */
    private function enforceDestinationCaps(string $to, string $channel): void
    {
        $this->enforceCap(
            key: self::DEST_HOUR_KEY_PREFIX . $to,
            limit: $this->config->sendsPerHour,
            windowSeconds: self::HOUR_SECONDS,
            destination: $to,
            channel: $channel,
            label: 'destination_hour',
        );
        $this->enforceCap(
            key: self::DEST_DAY_KEY_PREFIX . $to,
            limit: $this->config->sendsPerDay,
            windowSeconds: self::DAY_SECONDS,
            destination: $to,
            channel: $channel,
            label: 'destination_day',
        );
    }

    /**
     * Generic per-destination counter cap with fail-CLOSED DB fallback.
     *
     * Primary path: atomic Redis INCR with a window TTL set on first
     * hit. Over the limit → OtpRateLimitException with retry_after =
     * remaining window TTL.
     *
     * Fallback (Redis error): DB COUNT of recent sends to this
     * destination within the window. At/over the limit → throw. This is
     * the fail-CLOSED behaviour — the per-destination cap survives a
     * Redis outage, which matters most for the email channel.
     */
    private function enforceCap(
        string $key,
        int $limit,
        int $windowSeconds,
        string $destination,
        string $channel,
        string $label,
    ): void {
        if ($limit <= 0) {
            return; // this cap disabled
        }

        try {
            $count = $this->cache->incr($key);
            if ($count === 1) {
                $this->cache->expire($key, $windowSeconds);
            }
            if ($count > $limit) {
                $retryAfter = $this->windowRetryAfter($windowSeconds);
                throw new OtpRateLimitException(
                    sprintf('OTP send cap exceeded (%s).', $label),
                    $this->retryAfter($retryAfter),
                );
            }
        } catch (KeyValueStoreException $e) {
            // Fail CLOSED: count from the DB within the window.
            $this->logger->warning('OTP rate-limit cache failure — DB fallback (fail-closed)', [
                'cap' => $label,
                'error' => $e->getMessage(),
            ]);

            /** @var OtpAttemptRepository $repo */
            $repo = $this->em->getRepository(OtpAttempt::class);
            $recent = $repo->countRecentSendsForDestination($destination, $channel, $windowSeconds);

            // The current request is not yet persisted, so >= limit means
            // this send would be the (limit+1)-th — refuse it.
            if ($recent >= $limit) {
                throw new OtpRateLimitException(
                    sprintf('OTP send cap exceeded (%s, db-fallback).', $label),
                    $this->retryAfter($windowSeconds),
                );
            }
        }
    }

    // -------------------------------------------------------------------
    // (c) Per-IP caps
    // -------------------------------------------------------------------

    /**
     * Enforce the per-IP hourly + daily caps. Skipped entirely when the
     * IP can't be resolved (don't block legit users) or on a Redis error
     * (no DB column to fall back on; the per-destination cap is the
     * load-bearing guard and already fails closed).
     */
    private function enforceIpCaps(?string $ip): void
    {
        if ($ip === null || $ip === '') {
            return;
        }

        $this->enforceIpCap(
            key: self::IP_HOUR_KEY_PREFIX . $ip,
            limit: $this->config->sendsPerIpHour,
            windowSeconds: self::HOUR_SECONDS,
            label: 'ip_hour',
        );
        $this->enforceIpCap(
            key: self::IP_DAY_KEY_PREFIX . $ip,
            limit: $this->config->sendsPerIpDay,
            windowSeconds: self::DAY_SECONDS,
            label: 'ip_day',
        );
    }

    private function enforceIpCap(string $key, int $limit, int $windowSeconds, string $label): void
    {
        if ($limit <= 0) {
            return;
        }

        try {
            $count = $this->cache->incr($key);
            if ($count === 1) {
                $this->cache->expire($key, $windowSeconds);
            }
            if ($count > $limit) {
                throw new OtpRateLimitException(
                    sprintf('OTP send cap exceeded (%s).', $label),
                    $this->retryAfter($this->windowRetryAfter($windowSeconds)),
                );
            }
        } catch (KeyValueStoreException $e) {
            // Per-IP has no DB fallback column — skip on Redis error
            // rather than block. The per-destination cap (fail-closed)
            // still bounds abuse against any single target.
            $this->logger->warning('OTP per-IP rate-limit cache failure — skipping IP cap', [
                'cap' => $label,
                'error' => $e->getMessage(),
            ]);
        }
    }

    // -------------------------------------------------------------------
    // verify()
    // -------------------------------------------------------------------

    /**
     * Verify a user-supplied code.
     *
     * @throws OtpProviderException  When the CPaaS itself fails.
     * @throws OtpRateLimitException When the per-code verify cap is hit.
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

        // Per-verification-id verify cap (cross-worker, Redis). A single
        // code cannot be sprayed beyond maxVerifyAttempts total guesses.
        // Raised BEFORE we burn anything else so the client gets a clear
        // 429 + retry_after.
        $this->enforceVerifyAttemptCap($verificationId);

        if ($attempt->isEmailChannel()) {
            return $this->verifyEmail($attempt, $code);
        }

        return $this->verifySms($attempt, $code);
    }

    /**
     * SMS verify — delegate the code comparison to the CPaaS, but ALSO
     * track failed attempts locally and burn the row at the cap (mirrors
     * the email path). Previously we relied solely on MessageCentral's
     * own retry cap; now a wrong guess increments attempts and the row
     * becomes unusable once exhausted.
     */
    private function verifySms(OtpAttempt $attempt, string $code): VerifyResult
    {
        // Burn-on-exhaustion: refuse further guesses once the local cap
        // is reached, without calling the CPaaS again.
        if ($this->attemptsExhausted($attempt)) {
            return VerifyResult::WrongCode;
        }

        $ok = $this->provider->verify($attempt->getVerificationId(), $code);
        if (!$ok) {
            $attempt->incrementAttempts();
            $this->em->flush();
            return VerifyResult::WrongCode;
        }

        $attempt->markConsumed();
        $this->em->flush();

        return VerifyResult::Success;
    }

    /**
     * LOCAL verification for the email channel. Unchanged semantics:
     * attempt-cap burn, constant-time compare, increment-before-decide,
     * mark-consumed on success.
     */
    private function verifyEmail(OtpAttempt $attempt, string $code): VerifyResult
    {
        if ($this->attemptsExhausted($attempt)) {
            return VerifyResult::WrongCode;
        }

        $hash = $attempt->getCodeHash();
        if ($hash === null) {
            return VerifyResult::WrongCode;
        }

        if (!password_verify($code, $hash)) {
            $attempt->incrementAttempts();
            $this->em->flush();
            return VerifyResult::WrongCode;
        }

        $attempt->markConsumed();
        $this->em->flush();

        return VerifyResult::Success;
    }

    /**
     * True once the row's local attempt counter reaches the configured
     * cap. Honours OtpAttempt::MAX_EMAIL_ATTEMPTS as the floor for the
     * email channel (keeps the entity's published invariant) while the
     * env-tunable maxVerifyAttempts governs both channels.
     */
    private function attemptsExhausted(OtpAttempt $attempt): bool
    {
        $cap = $this->config->maxVerifyAttempts;
        if ($cap <= 0) {
            // Verify cap disabled — defer entirely to the provider /
            // expiry. Email rows still honour the entity-level cap so a
            // disabled env knob can't make email codes brute-forceable.
            return $attempt->isEmailChannel() && $attempt->hasExhaustedAttempts();
        }
        return $attempt->getAttempts() >= $cap;
    }

    /**
     * Per-verification-id verify-attempt counter (Redis). Increments on
     * every verify call for this code; over the cap raises a 429. On a
     * Redis error we skip this counter — the per-row attempts column
     * (incremented in verifySms/verifyEmail) still bounds guessing.
     */
    private function enforceVerifyAttemptCap(string $verificationId): void
    {
        $cap = $this->config->maxVerifyAttempts;
        if ($cap <= 0) {
            return;
        }

        $key = self::VERIFY_KEY_PREFIX . $verificationId;
        try {
            $count = $this->cache->incr($key);
            if ($count === 1) {
                // A verify counter only needs to outlive the OTP itself.
                $this->cache->expire($key, self::DEFAULT_TTL_SECONDS);
            }
            if ($count > $cap) {
                throw new OtpRateLimitException(
                    'Too many verification attempts for this code.',
                    $this->retryAfter($this->windowRetryAfter(self::DEFAULT_TTL_SECONDS)),
                );
            }
        } catch (KeyValueStoreException $e) {
            $this->logger->warning('OTP verify-cap cache failure — relying on per-row attempts', [
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Find the OtpAttempt row for a verificationId — used by controllers
     * that bind a user to an OTP after a successful verify.
     */
    public function findAttempt(string $verificationId): ?OtpAttempt
    {
        /** @var OtpAttemptRepository $repo */
        $repo = $this->em->getRepository(OtpAttempt::class);
        return $repo->findByVerificationId($verificationId);
    }

    // -------------------------------------------------------------------
    // helpers
    // -------------------------------------------------------------------

    /**
     * Retry-after to report when a counter cap trips. KeyValueStore
     * exposes no ttl() read, so we conservatively return the FULL window
     * length — this never under-reports (the client may wait slightly
     * longer than strictly necessary, which is the safe direction). A
     * single seam so a future KeyValueStore::ttl() can refine it without
     * touching every call site.
     */
    private function windowRetryAfter(int $windowSeconds): int
    {
        return $windowSeconds;
    }

    /** Clamp a retry-after to at least 1 second so clients never busy-loop. */
    private function retryAfter(int $seconds): int
    {
        return $seconds < 1 ? 1 : $seconds;
    }
}
