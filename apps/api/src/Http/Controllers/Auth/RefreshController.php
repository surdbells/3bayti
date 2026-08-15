<?php

declare(strict_types=1);

namespace Bayti\Api\Http\Controllers\Auth;

use Bayti\Api\Domain\User\RefreshToken;
use Bayti\Api\Domain\User\RefreshTokenRepository;
use Bayti\Api\Domain\User\User;
use Bayti\Api\Domain\User\UserRepository;
use Bayti\Api\Http\Controllers\Auth\Dto\RefreshInput;
use Bayti\Api\Http\Errors\ErrorCodes;
use Bayti\Api\Http\Errors\HttpException;
use Bayti\Api\Http\RequestContext;
use Bayti\Api\Http\Responder;
use Bayti\Api\Http\Serializers\UserSerializer;
use Bayti\Api\Http\Validator\RequestValidator;
use Bayti\Api\Infrastructure\Auth\JwtService;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * POST /v3/auth/refresh
 *
 * Exchange a valid refresh token for a fresh access + refresh pair.
 * The presented token is invalidated (single-use rotation per
 * Decision A.6.1). If the user's access token expires, the client
 * calls this endpoint with its stored refresh token to get fresh
 * credentials without forcing the user to re-enter their password.
 *
 * Response (200) — same shape as /login, /confirm, /reset/confirm
 *   {
 *     "access_token": "...",
 *     "access_token_expires_at": "...",
 *     "refresh_token": "...",
 *     "refresh_token_expires_at": "...",
 *     "user": { ... }
 *   }
 *
 * Verification flow
 * -----------------
 *   1. Cryptographic verification via JwtService::verifyRefreshToken.
 *      Catches: bad signature, wrong issuer/audience, expired (per
 *      JWT exp claim), missing claims, malformed.
 *
 *   2. DB lookup by jti. The token's jti claim is the unique row
 *      key in user_refresh_tokens. If the row is missing, the token
 *      is forged or older than our cleanup retention.
 *
 *   3. Hash match. The presented raw token is hashed and compared
 *      to the stored token_hash. If they don't match, something
 *      crafted a JWT with the right jti but wrong contents — only
 *      possible with a leaked signing key, but defense in depth.
 *
 *   4. State check: not revoked AND not expired (per DB row's
 *      expires_at — same as JWT exp but stored separately).
 *
 *   5. User must still be active (is_active = true).
 *
 *   6. Mark the row revoked with reason 'rotated'. New pair issued
 *      after this — single-use semantics.
 *
 *   7. Persist new refresh-token row. Return new pair.
 *
 * Refresh-token reuse detection
 * -----------------------------
 * If we find the row but it's already revoked, that's suspicious —
 * a legitimate client only presents each refresh token ONCE before
 * the rotation replaces it. A revoked-token presentation suggests
 * either:
 *   (a) Token was stolen and the attacker's client got there first
 *       (real client now has a valid refresh; attacker has the
 *       same token but it's stale — they'll fail next time)
 *   (b) Real client replayed the same token (network retry that
 *       succeeded the first time but the response was lost in transit)
 *
 * Rotation grace window (case b — innocent retry)
 * ------------------------------------------------
 * Case (b) is common on mobile: the server rotates the token, but the
 * client never receives or persists the replacement (dropped connection,
 * app backgrounded mid-refresh), then retries with the token it still
 * holds. To keep those customers signed in, a token that was revoked by
 * ROTATION within ROTATION_GRACE_SECONDS is treated as an innocent retry —
 * we simply rotate again and re-issue a fresh pair (see Step 4). Only the
 * 'rotated' reason within the window qualifies.
 *
 * Defensive response otherwise: if the revoked token is outside the grace
 * window, or was revoked for any other reason (logout, logout_all,
 * password_changed, admin_force_logout), we revoke ALL of the user's
 * refresh tokens with reason 'reuse_detected'. The user is logged out
 * everywhere and forced to re-authenticate, cutting off an attacker who
 * has stolen credentials — a small UX cost for a strong security guarantee.
 *
 * All failure modes
 * -----------------
 * Unified 401 with AUTH_REFRESH_TOKEN_INVALID. Distinguishing them
 * publicly leaks state.
 */
final class RefreshController
{
    use Responder;
    use RequestContext;

    /**
     * Grace window (seconds) during which re-presenting a just-ROTATED
     * refresh token is treated as an innocent lost-response retry rather
     * than reuse/theft.
     *
     * Covers the common mobile race: the server rotates the token, but the
     * client never receives or persists the replacement (dropped connection,
     * app backgrounded mid-refresh), then retries with the token it still
     * holds. Without this, that retry trips reuse-detection and logs the
     * customer out of every session.
     *
     * 5 minutes comfortably covers a retry after a brief background/resume
     * while staying far too short to be useful to an attacker (the real
     * bearer credential is the 15-minute access token; this is only
     * defense-in-depth on the refresh path).
     */
    private const ROTATION_GRACE_SECONDS = 300;

    public function __construct(
        protected readonly ResponseFactoryInterface $responseFactory,
        private readonly RequestValidator $validator,
        private readonly EntityManagerInterface $em,
        private readonly JwtService $jwt,
        private readonly UserSerializer $userSerializer,
    ) {
    }

    protected function getResponseFactory(): ResponseFactoryInterface
    {
        return $this->responseFactory;
    }

    public function __invoke(ServerRequestInterface $request): ResponseInterface
    {
        $input = $this->validator->parse($request, RefreshInput::class);

        // Step 1: cryptographic verify.
        $claims = $this->jwt->verifyRefreshToken($input->refresh_token);
        if ($claims === null) {
            throw $this->refreshInvalid();
        }

        // Step 2: DB lookup by jti.
        /** @var RefreshTokenRepository $refreshRepo */
        $refreshRepo = $this->em->getRepository(RefreshToken::class);
        $row = $refreshRepo->findByJti($claims->jti);
        if ($row === null) {
            // Cryptographically valid but not in our DB — could be
            // forged with a leaked secret OR purged by cleanup. Either
            // way, reject.
            throw $this->refreshInvalid();
        }

        // Step 3: hash match. Defense-in-depth — JWT signature should
        // catch most forgery, but a hash-mismatch with a valid signature
        // is a real anomaly worth refusing.
        if (!$row->matchesToken($input->refresh_token)) {
            throw $this->refreshInvalid();
        }

        // Step 4: state check — revoked OR expired-in-DB.
        if ($row->isRevoked()) {
            // Rotation grace window — tell an innocent lost-response retry
            // apart from genuine token theft. A single-use token that was
            // JUST rotated and is re-presented within the grace window is
            // almost always the same client retrying after its rotation
            // response was lost (dropped connection / app suspended
            // mid-refresh), NOT an attacker. In that case we fall through
            // and rotate again (Step 6), re-issuing a fresh pair so the
            // customer's session survives — rather than revoking every
            // session. `revoke('rotated')` in Step 6 is idempotent, so the
            // grace window stays anchored to the FIRST rotation and repeated
            // retries inside the window all succeed.
            //
            // Outside the window, or revoked for ANY other reason (logout,
            // logout_all, password_changed, admin_force_logout), this is
            // treated as reuse: defensively revoke the whole family.
            if (!$row->wasRotatedWithin(self::ROTATION_GRACE_SECONDS)) {
                $refreshRepo->revokeAllForUser($row->getUser(), 'reuse_detected');
                $this->em->flush();
                throw $this->refreshInvalid();
            }
        }
        if ($row->isExpired()) {
            // Past expires_at — JWT exp claim should have caught this
            // earlier, but DB-side expiry is the authoritative limit.
            throw $this->refreshInvalid();
        }

        // Step 5: user must still be active.
        $user = $row->getUser();
        if (!$user->isActive()) {
            throw $this->refreshInvalid();
        }

        // Step 6 + 7: rotate. Revoke this token, issue new pair,
        // persist new row, recordLogin (counts as new session). All
        // in one transaction.
        $newPair = $this->jwt->issueTokenPair($user);
        $clientIp = $this->extractIp($request);
        $userAgent = $this->extractUserAgent($request);

        $this->em->wrapInTransaction(function () use ($row, $user, $newPair, $clientIp, $userAgent, $refreshRepo): void {
            $row->revoke('rotated');

            $newRow = new RefreshToken(
                user: $user,
                jti: $newPair->refreshTokenJti,
                tokenHash: $newPair->refreshTokenHash(),
                expiresAt: $newPair->refreshTokenExpiresAt,
                issuedIp: $clientIp,
                userAgent: $userAgent,
            );
            $refreshRepo->save($newRow, flush: false);

            $user->recordLogin($clientIp);

            $this->em->flush();
        });

        return $this->ok([
            'access_token' => $newPair->accessToken,
            'access_token_expires_at' => $newPair->accessTokenExpiresAt->format(\DateTimeInterface::ATOM),
            'refresh_token' => $newPair->refreshToken,
            'refresh_token_expires_at' => $newPair->refreshTokenExpiresAt->format(\DateTimeInterface::ATOM),
            'user' => $this->userSerializer->publicProfile($user),
        ]);
    }

    private function refreshInvalid(): HttpException
    {
        return HttpException::unauthorized(
            ErrorCodes::AUTH_REFRESH_TOKEN_INVALID,
            'Refresh token is invalid or expired.',
        );
    }
}
