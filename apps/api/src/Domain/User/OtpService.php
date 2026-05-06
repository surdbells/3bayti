<?php

declare(strict_types=1);

namespace Bayti\Api\Domain\User;

use Bayti\Api\Infrastructure\Otp\OtpProvider;
use Bayti\Api\Infrastructure\Otp\OtpProviderException;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;

/**
 * OTP orchestrator — the seam between auth endpoints and the CPaaS.
 *
 * Responsibilities:
 *   1. Rate-limit OTP sends per phone (DB-counted in M1; Redis in M1.5)
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
 * Rate limiting in M1.3
 * ---------------------
 * We count rows in user_otp_attempts where phone = :phone AND
 * created_at > now() - 1 hour. If >=3, refuse with OtpRateLimitException.
 *
 * This is NOT atomic — two concurrent send-otp calls could both pass
 * the check and both insert rows, ending at 4 in the hour. Acceptable
 * for M1.3 because:
 *   1. We don't have production traffic yet
 *   2. The cap is approximate, not a security boundary
 *   3. M1.5 will replace with Redis sliding-window (atomic)
 *
 * The 3-per-hour cap is a soft guard against:
 *   - Accidental UI loops (user clicks "resend OTP" 50 times)
 *   - Bots scraping the registration endpoint
 *   - Cost protection for our SMS bill
 *
 * It is NOT a defense against a determined attacker — that's M1.5's
 * Redis-backed limiter which can also rate-limit by IP, by user
 * agent fingerprint, etc.
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
     * Max sends per phone per rolling hour. Soft cap; see class docblock.
     */
    private const SENDS_PER_HOUR = 3;

    public function __construct(
        private readonly OtpProvider $provider,
        private readonly EntityManagerInterface $em,
    ) {
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

        // Rate limit — count recent sends to this phone.
        /** @var OtpAttemptRepository $repo */
        $repo = $this->em->getRepository(OtpAttempt::class);
        $recent = $repo->countRecentSendsForPhone($phone, withinSeconds: 3600);
        if ($recent >= self::SENDS_PER_HOUR) {
            throw new OtpRateLimitException(
                "Too many OTP requests for {$phone} in the last hour ({$recent} of " . self::SENDS_PER_HOUR . ' allowed).'
            );
        }

        // Ask CPaaS to send. If this throws, we don't insert any
        // local record — provider failures don't pollute our audit
        // trail with sends that didn't happen.
        $verificationId = $this->provider->send($phone);

        // Persist the local record. Single transaction — flush after
        // both the row and the provider call have succeeded.
        $expiresAt = (new DateTimeImmutable())->modify('+' . self::DEFAULT_TTL_SECONDS . ' seconds');
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
