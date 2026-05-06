<?php

declare(strict_types=1);

namespace Bayti\Api\Domain\User;

use DateTimeImmutable;
use Doctrine\ORM\Mapping as ORM;

/**
 * One-time password attempt — local record of an OTP we asked
 * MessageCentral CPaaS to send.
 *
 * Architecture (Option B per Decision A.6 / M1.3 design)
 * -------------------------------------------------------
 * MessageCentral CPaaS handles code generation, SMS delivery, AND
 * code verification server-side. We never see the actual 6-digit
 * code — they generate it, send it to the user's phone, and we
 * validate by sending (verificationId + user-supplied code) back to
 * them. This entity is the LOCAL audit/coordination record:
 *
 *   1. /v3/auth/send-otp
 *        we ask MessageCentral to send → they return verificationId
 *        we INSERT a row with that verificationId
 *        we return verificationId to the client
 *
 *   2. /v3/auth/confirm  (with verificationId + code)
 *        we look up the row by verificationId
 *        we ask MessageCentral to validate (verificationId + code)
 *        on success: row.consumed_at = now()
 *        on failure: row stays usable (MessageCentral tracks attempts
 *                    on their side — typically caps at 5 retries)
 *
 * Why we still need a row at all
 * ------------------------------
 * Even though MessageCentral does verification, we keep a row for:
 *   - Rate limiting (max 3 sends per phone per hour) — counted in SQL
 *   - Audit trail (who requested OTPs, when, from what IP)
 *   - Linking to a user when known (registration OTPs are sent
 *     BEFORE the User row exists, so user_id is null at first)
 *   - Detecting suspicious patterns (same phone, multiple emails)
 *
 * What we DON'T store
 * -------------------
 * The actual 6-digit code, ever. MessageCentral has it; we don't
 * need it; storing it would be a needless leak risk.
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
     * MessageCentral's verificationId — opaque token that ties our
     * subsequent /validateOtp call to the SMS we asked them to
     * send. Returned by their /verification/v3/send response.
     *
     * Unique because each send produces a fresh id.
     */
    #[ORM\Column(name: 'verification_id', type: 'string', length: 100, unique: true)]
    private string $verificationId;

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

    #[ORM\Column(name: 'created_at', type: 'datetimetz_immutable')]
    private DateTimeImmutable $createdAt;

    /**
     * When this OTP becomes invalid. We mirror MessageCentral's TTL
     * (typically 5 minutes) so our rate-limit + lookup queries
     * align with their server-side state.
     */
    #[ORM\Column(name: 'expires_at', type: 'datetimetz_immutable')]
    private DateTimeImmutable $expiresAt;

    /**
     * When the OTP was successfully verified, or null if not yet
     * verified. Set when MessageCentral's /validateOtp returns
     * success. Once consumed, the row should not be matched by
     * findLatestUsable.
     */
    #[ORM\Column(name: 'consumed_at', type: 'datetimetz_immutable', nullable: true)]
    private ?DateTimeImmutable $consumedAt = null;

    // Audit
    #[ORM\Column(name: 'requested_ip', type: 'string', length: 45, nullable: true)]
    private ?string $requestedIp = null;

    public function __construct(
        string $verificationId,
        string $phone,
        string $purpose,
        DateTimeImmutable $expiresAt,
        ?User $user = null,
        ?string $requestedIp = null,
    ) {
        if (!in_array($purpose, self::ALL_PURPOSES, true)) {
            throw new \InvalidArgumentException("Unknown OTP purpose: {$purpose}");
        }
        $this->verificationId = $verificationId;
        $this->phone = $phone;
        $this->purpose = $purpose;
        $this->expiresAt = $expiresAt;
        $this->user = $user;
        $this->requestedIp = $requestedIp;
        $this->createdAt = new DateTimeImmutable();
    }

    public function getId(): ?int                       { return $this->id; }
    public function getVerificationId(): string         { return $this->verificationId; }
    public function getPhone(): string                  { return $this->phone; }
    public function getUser(): ?User                    { return $this->user; }
    public function getPurpose(): string                { return $this->purpose; }
    public function getCreatedAt(): DateTimeImmutable   { return $this->createdAt; }
    public function getExpiresAt(): DateTimeImmutable   { return $this->expiresAt; }
    public function getConsumedAt(): ?DateTimeImmutable { return $this->consumedAt; }
    public function getRequestedIp(): ?string           { return $this->requestedIp; }

    public function isExpired(): bool { return $this->expiresAt <= new DateTimeImmutable(); }
    public function isConsumed(): bool { return $this->consumedAt !== null; }

    /** True when the OTP can still be verified against (not expired, not consumed). */
    public function isUsable(): bool
    {
        return !$this->isExpired() && !$this->isConsumed();
    }

    /**
     * Mark this OTP consumed — called by OtpService after MessageCentral
     * confirms the user-supplied code matches. Idempotent: re-consuming
     * an already-consumed row is a no-op (preserves original timestamp).
     */
    public function markConsumed(): void
    {
        if ($this->consumedAt !== null) {
            return;
        }
        $this->consumedAt = new DateTimeImmutable();
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
