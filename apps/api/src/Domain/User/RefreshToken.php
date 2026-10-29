<?php

declare(strict_types=1);

namespace Bayti\Api\Domain\User;

use DateTimeImmutable;
use Doctrine\ORM\Mapping as ORM;

/**
 * Refresh-token registry, one row per active refresh token issued.
 *
 * Why this exists
 * ---------------
 * Access tokens (the JWTs sent in Authorization headers) are
 * stateless, we verify them by signature alone, no DB lookup. That
 * makes them fast but also makes them impossible to revoke before
 * their expiry. So we keep their TTL short (15 minutes per Decision
 * A.6.1) and pair them with longer-lived refresh tokens that ARE
 * stored server-side.
 *
 * When an access token expires, the client calls /v3/auth/refresh
 * with its refresh token. We:
 *   1. Look up the refresh token by jti (jwt id) in this table
 *   2. Verify it's not expired AND its session has not ended (a token
 *      revoked only by ROTATION is still accepted, see RefreshController)
 *   3. Mark it revoked with reason 'rotated'
 *   4. Issue a new access + refresh token pair in the SAME family
 *
 * Session families
 * ----------------
 * Every sign-in starts a new family (family_id = the first token's
 * jti) and every rotation's replacement inherits it, so one family is
 * one signed-in device/browser. Logout ends that device's family only
 * (RefreshTokenRepository::revokeFamily); logout-everywhere ends every
 * family of the user (revokeAllForUser). Nothing else ends a session:
 * not a password change/reset, an admin reset, a refresh-token replay
 * or an account merge. Product rule: a customer stays signed in until
 * they explicitly log out.
 *
 * Storage details
 * ---------------
 * We store the SHA-256 hash of the refresh token, not the token
 * itself. If the database is leaked, attackers see hashes, they
 * still need the original token to authenticate. This is a
 * defense-in-depth measure; the JWT signature alone would catch
 * forged tokens, but storing hashes means a DB read doesn't yield
 * usable credentials.
 *
 * The jti field is what links the JWT (which embeds jti as a claim)
 * to this row. Indexed because every refresh request is a lookup
 * on jti.
 */
#[ORM\Entity(repositoryClass: RefreshTokenRepository::class)]
#[ORM\Table(name: 'user_refresh_tokens')]
#[ORM\Index(columns: ['user_id'], name: 'idx_refresh_tokens_user')]
#[ORM\Index(columns: ['jti'], name: 'idx_refresh_tokens_jti')]
#[ORM\Index(columns: ['expires_at'], name: 'idx_refresh_tokens_expiry')]
#[ORM\Index(columns: ['family_id'], name: 'idx_refresh_tokens_family')]
class RefreshToken
{
    /**
     * The one revoke reason that keeps a token re-issuable (see
     * wasRotated). Written by RefreshController on every rotation.
     */
    public const REASON_ROTATED = 'rotated';

    #[ORM\Id]
    #[ORM\GeneratedValue(strategy: 'IDENTITY')]
    #[ORM\Column(type: 'bigint')]
    // @phpstan-ignore-next-line property.unusedType
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: User::class, inversedBy: 'refreshTokens')]
    #[ORM\JoinColumn(name: 'user_id', referencedColumnName: 'id', nullable: false, onDelete: 'CASCADE')]
    private User $user;

    /**
     * JWT ID claim, uuid embedded in the refresh token's payload.
     * Globally unique. We use ramsey/uuid v7 (time-ordered) so
     * adjacent issuances cluster on disk, helping the index.
     */
    #[ORM\Column(type: 'string', length: 36, unique: true)]
    private string $jti;

    /**
     * Session family: the jti of the token that started this sign-in
     * (login, OTP, social, a password reset made while signed out). Every
     * rotation copies it onto the replacement row, and so do a password
     * change, a reset made while signed in and a phone-claim merge (from
     * the access token's `sid` claim), so all the tokens one device has
     * ever held share it. Logout revokes the whole family at once, which
     * stops an older, already-rotated token of a logged-out device from
     * being re-issued.
     *
     * NULL only on rows written before the column existed
     * (Version20261029000001 deliberately does not backfill them). Such a
     * row is its own family: read it through getFamilyId(), which falls
     * back to the jti, as RefreshTokenRepository::revokeFamily() does.
     */
    #[ORM\Column(name: 'family_id', type: 'string', length: 36, nullable: true)]
    private ?string $familyId = null;

    /**
     * SHA-256 hash of the refresh token string. Used to detect
     * token-substitution attacks: if the jti claim in the presented
     * token matches a row but the hash doesn't, something forged
     * the JWT (only possible with a leaked signing key, at which
     * point all bets are off, but we'll detect it).
     *
     * 64 hex characters.
     */
    #[ORM\Column(name: 'token_hash', type: 'string', length: 64)]
    private string $tokenHash;

    /**
     * When this token was issued (= when the row was created). Used
     * for audit logs and "active sessions" UI.
     */
    #[ORM\Column(name: 'issued_at', type: 'datetimetz_immutable')]
    private DateTimeImmutable $issuedAt;

    /**
     * When this token expires (issued_at + JWT_REFRESH_TOKEN_TTL, 5 years,
     * enforced as a floor in config/di.php). Sliding: every rotation
     * issues the replacement with a fresh 5-year expiry.
     */
    #[ORM\Column(name: 'expires_at', type: 'datetimetz_immutable')]
    private DateTimeImmutable $expiresAt;

    /**
     * When this token was revoked, or null if still active.
     * Rotation sets it (reason 'rotated') the moment a token is used to
     * refresh; a rotated token can still be re-presented and is re-issued.
     * Logout sets it for the device's whole family; logout-everywhere
     * for all of a user's tokens.
     */
    #[ORM\Column(name: 'revoked_at', type: 'datetimetz_immutable', nullable: true)]
    private ?DateTimeImmutable $revokedAt = null;

    /**
     * Why was the token revoked? Only 'rotated' keeps the token usable
     * (it is re-issued); every other value means the session is over.
     * Written today: 'rotated', 'logout', 'logout_all'.
     * Historical (no longer written, may remain on old rows):
     * 'reuse_detected', 'password_changed', 'admin_password_reset',
     * 'account_merged_into_<id>'.
     */
    #[ORM\Column(name: 'revoked_reason', type: 'string', length: 30, nullable: true)]
    private ?string $revokedReason = null;

    // -------------------------------------------------------------------
    // Audit fields, context of issuance, helps detect anomalies
    // -------------------------------------------------------------------

    /**
     * IP address the token was issued from. Helpful for "active
     * sessions" UI and detecting credential-stuffing patterns.
     */
    #[ORM\Column(name: 'issued_ip', type: 'string', length: 45, nullable: true)]
    private ?string $issuedIp = null;

    /**
     * User-Agent header from issuance. Used to render readable
     * "logged in on Chrome on macOS" labels in the active-sessions UI.
     * Truncated to 500 chars to bound storage.
     */
    #[ORM\Column(name: 'user_agent', type: 'string', length: 500, nullable: true)]
    private ?string $userAgent = null;

    public function __construct(
        User $user,
        string $jti,
        string $tokenHash,
        DateTimeImmutable $expiresAt,
        ?string $issuedIp = null,
        ?string $userAgent = null,
        ?string $familyId = null,
    ) {
        $this->user = $user;
        $this->jti = $jti;
        // No family given = a fresh sign-in, which starts its own family.
        $this->familyId = $familyId ?? $jti;
        $this->tokenHash = $tokenHash;
        $this->expiresAt = $expiresAt;
        $this->issuedIp = $issuedIp;
        $this->userAgent = $userAgent !== null ? mb_substr($userAgent, 0, 500) : null;
        $this->issuedAt = new DateTimeImmutable();
    }

    public function getId(): ?int                      { return $this->id; }
    public function getUser(): User                    { return $this->user; }
    public function getJti(): string                   { return $this->jti; }
    public function getFamilyId(): string              { return $this->familyId ?? $this->jti; }
    public function getTokenHash(): string             { return $this->tokenHash; }
    public function getIssuedAt(): DateTimeImmutable   { return $this->issuedAt; }
    public function getExpiresAt(): DateTimeImmutable  { return $this->expiresAt; }
    public function getRevokedAt(): ?DateTimeImmutable { return $this->revokedAt; }
    public function getRevokedReason(): ?string        { return $this->revokedReason; }
    public function getIssuedIp(): ?string             { return $this->issuedIp; }
    public function getUserAgent(): ?string            { return $this->userAgent; }

    public function isRevoked(): bool { return $this->revokedAt !== null; }
    public function isExpired(): bool { return $this->expiresAt <= new DateTimeImmutable(); }

    /**
     * True when this token was revoked by ROTATION (single-use refresh),
     * no matter how long ago.
     *
     * Such a token is re-issued when re-presented: it is the same client
     * retrying after the rotation response was lost (dropped connection,
     * app suspended or killed mid-refresh, days in the background), a
     * parallel refresh from a second tab, or a stale copy of the session
     * the app wrote back. None of those may sign the customer out. Every
     * other reason (logout, logout_all and the historical ones) means the
     * session is over.
     */
    public function wasRotated(): bool
    {
        return $this->revokedAt !== null && $this->revokedReason === self::REASON_ROTATED;
    }

    /** Token is usable iff not revoked AND not expired. */
    public function isActive(): bool
    {
        return !$this->isRevoked() && !$this->isExpired();
    }

    /**
     * Check whether the presented refresh token (raw string) matches
     * the stored hash. Constant-time comparison to avoid timing leaks.
     */
    public function matchesToken(string $rawToken): bool
    {
        $candidateHash = hash('sha256', $rawToken);
        return hash_equals($this->tokenHash, $candidateHash);
    }

    /**
     * Mark this token revoked. Reason 'rotated' keeps it re-issuable
     * (see wasRotated); with any other reason a refresh with it gets a
     * 401. Reason is recorded for audit.
     */
    public function revoke(string $reason): void
    {
        if ($this->revokedAt !== null) {
            // Idempotent, re-revoking is a no-op (don't overwrite
            // the original timestamp/reason).
            return;
        }
        $this->revokedAt = new DateTimeImmutable();
        $this->revokedReason = $reason;
    }
}
