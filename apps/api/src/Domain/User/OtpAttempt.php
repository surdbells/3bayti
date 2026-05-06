<?php

declare(strict_types=1);

namespace Bayti\Api\Domain\User;

use DateTimeImmutable;
use Doctrine\ORM\Mapping as ORM;

/**
 * One-time password attempt record — issued when /v3/auth/send-otp
 * sends a code, consumed by /v3/auth/confirm or /v3/auth/reset/confirm.
 *
 * Why this exists
 * ---------------
 * An OTP is a 6-digit code we send via SMS to confirm the user has
 * possession of the phone number they registered with (or are
 * resetting). The OTP itself is short-lived (5-minute TTL per spec)
 * but the record persists so we can:
 *
 *   - Rate-limit send-otp requests (max 3 per phone per hour, max 5
 *     verify attempts per OTP) to prevent abuse and SMS-bill blowups
 *   - Log who-tried-what for security audits
 *   - Detect suspicious patterns (same phone receiving OTPs for
 *     different emails — possible account-takeover attempt)
 *
 * Why store it instead of using Redis-only
 * ----------------------------------------
 * Rate-limit counters live in Redis (M1.5) for performance. But the
 * audit trail and the OTP-record itself live in Postgres because:
 *   - Postgres is durable; Redis can lose state on restart and we
 *     don't want OTPs to survive a Redis flush either, but the
 *     audit row should
 *   - Pattern queries ("how many OTPs were sent to this phone in
 *     the last 24 hours from different IPs") are easier in SQL
 *
 * Storage of the OTP itself
 * -------------------------
 * We store SHA-256(otp || salt) — never the plain digits. If the
 * DB is leaked, attackers can't brute-force a 6-digit code in any
 * meaningful timeframe per row because we limit verify attempts
 * (5 per OTP, then the row is exhausted). Salt is a per-row
 * random nonce.
 *
 * Reuse of code
 * -------------
 * One OTP, one purpose. After verify success the row is marked
 * consumed and never reused. After verify failure 5x, the row is
 * marked exhausted and never reused. Either way, the user must
 * request a fresh OTP for the next attempt.
 */
#[ORM\Entity(repositoryClass: OtpAttemptRepository::class)]
#[ORM\Table(name: 'user_otp_attempts')]
#[ORM\Index(columns: ['phone'], name: 'idx_otp_attempts_phone')]
#[ORM\Index(columns: ['expires_at'], name: 'idx_otp_attempts_expiry')]
#[ORM\Index(columns: ['user_id'], name: 'idx_otp_attempts_user')]
class OtpAttempt
{
    /**
     * Reasons an OTP can be issued for. Stored as a string for
     * forward-compatibility (don't lock into an enum migration);
     * only these values should ever appear.
     */
    public const PURPOSE_REGISTRATION = 'registration';
    public const PURPOSE_PASSWORD_RESET = 'password_reset';
    public const PURPOSE_PHONE_CHANGE = 'phone_change';
    public const PURPOSE_LOGIN_2FA = 'login_2fa';

    public const ALL_PURPOSES = [
        self::PURPOSE_REGISTRATION,
        self::PURPOSE_PASSWORD_RESET,
        self::PURPOSE_PHONE_CHANGE,
        self::PURPOSE_LOGIN_2FA,
    ];

    #[ORM\Id]
    #[ORM\GeneratedValue(strategy: 'IDENTITY')]
    #[ORM\Column(type: 'bigint')]
    private ?int $id = null;

    /**
     * The phone number the OTP was sent to. May or may not have a
     * matching User row at issuance time — registration OTPs are
     * sent BEFORE user creation, so user_id is null in that case.
     * Stored separately from user.phone so we can rate-limit by
     * phone even before any account exists.
     */
    #[ORM\Column(type: 'string', length: 25)]
    private string $phone;

    /**
     * The user this OTP relates to, when known. Null for
     * registration OTPs (no user exists yet); set for
     * password-reset and phone-change OTPs.
     */
    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(name: 'user_id', referencedColumnName: 'id', nullable: true, onDelete: 'CASCADE')]
    private ?User $user = null;

    #[ORM\Column(type: 'string', length: 30)]
    private string $purpose;

    /**
     * SHA-256(otp || salt). The actual 6-digit code is never stored.
     */
    #[ORM\Column(name: 'code_hash', type: 'string', length: 64)]
    private string $codeHash;

    /**
     * Per-row random salt mixed into the code hash. 32 hex chars.
     * Generated at construction; never changes.
     */
    #[ORM\Column(type: 'string', length: 32)]
    private string $salt;

    #[ORM\Column(name: 'created_at', type: 'datetimetz_immutable')]
    private DateTimeImmutable $createdAt;

    /**
     * When this OTP becomes invalid (created_at + 5 minutes by
     * default). After expiry, verify always fails.
     */
    #[ORM\Column(name: 'expires_at', type: 'datetimetz_immutable')]
    private DateTimeImmutable $expiresAt;

    /**
     * When the OTP was successfully verified, or null if not yet
     * verified. After successful verify, this row is "consumed" —
     * subsequent verify attempts (even with the right code) fail.
     */
    #[ORM\Column(name: 'consumed_at', type: 'datetimetz_immutable', nullable: true)]
    private ?DateTimeImmutable $consumedAt = null;

    /**
     * Verify-attempt counter. Incremented on every /v3/auth/confirm
     * call regardless of success. When this reaches 5, the row is
     * marked exhausted and no further verify attempts are accepted
     * (forces user to request a new OTP).
     */
    #[ORM\Column(name: 'verify_attempts', type: 'smallint', options: ['default' => 0])]
    private int $verifyAttempts = 0;

    /**
     * Cap on verify_attempts. 5 by default. Keep as a column
     * (not a constant) so admin can tune it per-OTP for support
     * cases without code changes.
     */
    #[ORM\Column(name: 'max_verify_attempts', type: 'smallint', options: ['default' => 5])]
    private int $maxVerifyAttempts = 5;

    // Audit
    #[ORM\Column(name: 'requested_ip', type: 'string', length: 45, nullable: true)]
    private ?string $requestedIp = null;

    public function __construct(
        string $phone,
        string $purpose,
        string $codeHash,
        string $salt,
        DateTimeImmutable $expiresAt,
        ?User $user = null,
        ?string $requestedIp = null,
    ) {
        if (!in_array($purpose, self::ALL_PURPOSES, true)) {
            throw new \InvalidArgumentException("Unknown OTP purpose: {$purpose}");
        }
        $this->phone = $phone;
        $this->purpose = $purpose;
        $this->codeHash = $codeHash;
        $this->salt = $salt;
        $this->expiresAt = $expiresAt;
        $this->user = $user;
        $this->requestedIp = $requestedIp;
        $this->createdAt = new DateTimeImmutable();
    }

    public function getId(): ?int                      { return $this->id; }
    public function getPhone(): string                 { return $this->phone; }
    public function getUser(): ?User                   { return $this->user; }
    public function getPurpose(): string               { return $this->purpose; }
    public function getCodeHash(): string              { return $this->codeHash; }
    public function getSalt(): string                  { return $this->salt; }
    public function getCreatedAt(): DateTimeImmutable  { return $this->createdAt; }
    public function getExpiresAt(): DateTimeImmutable  { return $this->expiresAt; }
    public function getConsumedAt(): ?DateTimeImmutable{ return $this->consumedAt; }
    public function getVerifyAttempts(): int           { return $this->verifyAttempts; }
    public function getMaxVerifyAttempts(): int        { return $this->maxVerifyAttempts; }
    public function getRequestedIp(): ?string          { return $this->requestedIp; }

    public function isExpired(): bool { return $this->expiresAt <= new DateTimeImmutable(); }
    public function isConsumed(): bool { return $this->consumedAt !== null; }
    public function isExhausted(): bool { return $this->verifyAttempts >= $this->maxVerifyAttempts; }

    /** True when the OTP can still be verified against. */
    public function isUsable(): bool
    {
        return !$this->isExpired() && !$this->isConsumed() && !$this->isExhausted();
    }

    /**
     * Check whether the provided code matches this OTP. Constant-time
     * comparison. Increments verify_attempts as a side effect; caller
     * must persist the entity to record the attempt.
     */
    public function verify(string $code): bool
    {
        $this->verifyAttempts++;

        // If the OTP is in any non-usable state (expired / consumed /
        // exhausted), reject immediately — even with the right code.
        // The increment above still happens so rate-limiting upstream
        // counts the attempt regardless. Note: the increment moves us
        // to "exhausted" once verifyAttempts reaches max, which means
        // the very last allowed attempt sees the freshly-exhausted state
        // here only if max was already reached BEFORE this call.
        if ($this->isExpired() || $this->isConsumed()) {
            return false;
        }

        // For exhaustion, check the count BEFORE the increment we just
        // did. If we'd already maxed out before this call, reject.
        // (The increment counts this attempt for audit but doesn't
        // grant verification.)
        if ($this->verifyAttempts > $this->maxVerifyAttempts) {
            return false;
        }

        $candidateHash = hash('sha256', $code . $this->salt);
        $matches = hash_equals($this->codeHash, $candidateHash);

        if ($matches) {
            $this->consumedAt = new DateTimeImmutable();
            return true;
        }
        return false;
    }

    /**
     * Bind this OTP to a user — used during registration confirm
     * once the User row has been created.
     */
    public function bindUser(User $user): void
    {
        $this->user = $user;
    }
}
