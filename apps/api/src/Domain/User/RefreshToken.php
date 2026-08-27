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
 *   2. Verify it's not expired AND not revoked
 *   3. Mark it revoked (single-use rotation per Decision A.6.1)
 *   4. Issue a new access + refresh token pair
 *
 * Logout = mark revoked. Logout-everywhere = mark all of a user's
 * tokens revoked (Decision A.6.2). Password change = same.
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
class RefreshToken
{
    #[ORM\Id]
    #[ORM\GeneratedValue(strategy: 'IDENTITY')]
    #[ORM\Column(type: 'bigint')]
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
     * When this token expires (issued_at + JWT_REFRESH_TOKEN_TTL,
     * which defaults to 7 days per Decision A.6.1).
     */
    #[ORM\Column(name: 'expires_at', type: 'datetimetz_immutable')]
    private DateTimeImmutable $expiresAt;

    /**
     * When this token was revoked, or null if still active.
     * Single-use rotation: tokens are revoked the moment they're
     * used to refresh. Logout sets this for the active token;
     * logout-everywhere sets it for all of a user's tokens.
     */
    #[ORM\Column(name: 'revoked_at', type: 'datetimetz_immutable', nullable: true)]
    private ?DateTimeImmutable $revokedAt = null;

    /**
     * Why was the token revoked? Useful for security audits.
     * Values: 'logout', 'logout_all', 'rotated', 'password_changed',
     * 'admin_force_logout', 'expired_cleanup'.
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
    ) {
        $this->user = $user;
        $this->jti = $jti;
        $this->tokenHash = $tokenHash;
        $this->expiresAt = $expiresAt;
        $this->issuedIp = $issuedIp;
        $this->userAgent = $userAgent !== null ? mb_substr($userAgent, 0, 500) : null;
        $this->issuedAt = new DateTimeImmutable();
    }

    public function getId(): ?int                      { return $this->id; }
    public function getUser(): User                    { return $this->user; }
    public function getJti(): string                   { return $this->jti; }
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
     * True when this token was revoked specifically by ROTATION (single-use
     * refresh) within the last $graceSeconds.
     *
     * This distinguishes an innocent lost-response retry, the same client
     * re-presenting a token that was just rotated because it never received
     * or persisted the replacement (dropped connection, app suspended
     * mid-refresh), from genuine refresh-token reuse/theft. Only the
     * 'rotated' reason qualifies: a token revoked by logout / logout_all /
     * password_changed / admin_force_logout is deliberately dead and must
     * never be honoured, regardless of how recently it happened.
     */
    public function wasRotatedWithin(int $graceSeconds): bool
    {
        if ($this->revokedReason !== 'rotated' || $this->revokedAt === null) {
            return false;
        }
        $elapsed = (new DateTimeImmutable())->getTimestamp() - $this->revokedAt->getTimestamp();
        return $elapsed >= 0 && $elapsed <= $graceSeconds;
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
     * Mark this token revoked. After this, any attempt to refresh
     * with it gets a 401. Reason is recorded for audit.
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
