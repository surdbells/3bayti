<?php

declare(strict_types=1);

namespace Bayti\Api\Http\Controllers\Profile;

use Bayti\Api\Domain\Audit\AuditEmitter;
use Bayti\Api\Domain\User\RefreshToken;
use Bayti\Api\Domain\User\RefreshTokenRepository;
use Bayti\Api\Domain\User\User;
use Bayti\Api\Http\Controllers\Profile\Dto\ChangePasswordInput;
use Bayti\Api\Http\Errors\ErrorCodes;
use Bayti\Api\Http\Errors\HttpException;
use Bayti\Api\Http\Middleware\AuthMiddleware;
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
 * PATCH /v3/me/password
 *
 * Change the authenticated user's password.
 *
 * Auth credential
 * ---------------
 * This endpoint requires TWO things:
 *   1. A valid access token (AuthMiddleware enforces)
 *   2. The current password in the request body
 *
 * Both must succeed. The token alone is not sufficient: even if an
 * attacker steals a token, they cannot lock the real owner out
 * without also knowing the current password. This is the canonical
 * "re-auth check" pattern.
 *
 * Distinct from password reset
 * ----------------------------
 * /v3/auth/reset/confirm uses an OTP as the auth credential (user
 * forgot the password). This endpoint uses the current password
 * (user knows it, wants to rotate). The two should never be confused.
 *
 * Session handling after successful change
 * ----------------------------------------
 * Following the same pattern as /v3/auth/reset/confirm:
 *
 *   1. user.password_changed_at is bumped (by User::setPasswordHash).
 *      This invalidates ALL access tokens for this user via the
 *      AuthMiddleware pwd_changed_at staleness check on the NEXT
 *      request.
 *
 *   2. All existing refresh tokens are revoked (including the
 *      requesting client's).
 *
 *   3. A fresh token pair is issued and returned in the response.
 *      The user stays logged in on THIS device; all other sessions
 *      are kicked out.
 *
 * This is the security-correct default: if someone hijacked the
 * account on another device, changing the password kicks them out.
 *
 * Deviation from 0e.2 contract
 * ----------------------------
 * The 0e.2 contract specified:
 *   - status 204 (no content)
 *   - "Does NOT revoke other sessions"
 *
 * Both were based on an incorrect assumption: the contract author
 * (me, when designing 0e.2) didn't know about the pwd_changed_at
 * token-invalidation mechanism. In practice, changing the hash
 * always invalidates the current access token on next request, so
 * "204 + don't revoke other sessions" would still kick the user
 * out — just inconsistently.
 *
 * Mirroring /v3/auth/reset/confirm gives consistent UX, consistent
 * security properties, and a single audit trail. M3.1.1h will
 * reconcile the 0e.2 contract text.
 *
 * Request body
 * ------------
 *   {
 *     "current_password": "...",   REQUIRED, re-auth check
 *     "new_password":     "..."    REQUIRED, min 8 chars
 *   }
 *
 * Response (200 OK)
 * -----------------
 *   {
 *     "access_token":             "...",
 *     "access_token_expires_at":  "ISO-8601",
 *     "refresh_token":            "...",
 *     "refresh_token_expires_at": "ISO-8601",
 *     "user":                     { ...UserSerializer::publicProfile }
 *   }
 *
 * Error responses
 * ---------------
 *   401 AUTH_MISSING_TOKEN / AUTH_INVALID_TOKEN  (AuthMiddleware)
 *   401 AUTH_INVALID_CREDENTIALS                 current_password wrong
 *   422 VALIDATION_FAILED                        bad body / new == current
 *
 * Why same 401 for missing token vs wrong current_password
 * ---------------------------------------------------------
 * Token errors hit AuthMiddleware and 401 with AUTH_*_TOKEN.
 * Wrong current_password 401s with AUTH_INVALID_CREDENTIALS.
 * Different error codes; same status. Frontend distinguishes by
 * code. This matches LoginController which uses AUTH_INVALID_CREDENTIALS
 * for the "wrong email/password" case.
 *
 * Why no leak between "user not found" and "wrong password"
 * ----------------------------------------------------------
 * Doesn't apply here: the user MUST be present (AuthMiddleware
 * loaded them from the token). The only password-related failure
 * is "current_password doesn't match" → 401.
 *
 * Rate limiting (M3.1.x posture)
 * -------------------------------
 * A malicious actor with a stolen access token could call this
 * endpoint repeatedly to brute-force the current password (high-
 * stakes because success = full account takeover).
 *
 * In M3.1.1f we DO NOT implement per-user rate limiting on this
 * endpoint specifically. Mitigations in place:
 *   - bcrypt password_verify is intentionally slow (~tens of ms)
 *   - access tokens expire in 30 minutes
 *   - WAF + reverse proxy provide global IP-level rate limiting
 *
 * In M4 (security hardening) we should add per-user lockout after
 * N failed current_password attempts. Tracking flagged in M5
 * hardening checklist.
 *
 * Audit log
 * ---------
 * Per M1.6.1.C, a 'password_changed' audit event is recorded.
 * Subject is User. Changes map records that the password was
 * changed (we do NOT log hashes; just a marker that change occurred).
 * Same pattern as the reset-confirm flow but with action='updated'
 * via AuditEmitter::recordUpdate with a minimal snapshot diff.
 */
final class ChangePasswordController
{
    use Responder;
    use RequestContext;

    public function __construct(
        protected readonly ResponseFactoryInterface $responseFactory,
        private readonly RequestValidator $validator,
        private readonly EntityManagerInterface $em,
        private readonly JwtService $jwt,
        private readonly UserSerializer $userSerializer,
        private readonly AuditEmitter $audit,
    ) {
    }

    protected function getResponseFactory(): ResponseFactoryInterface
    {
        return $this->responseFactory;
    }

    public function __invoke(ServerRequestInterface $request): ResponseInterface
    {
        $user = $request->getAttribute(AuthMiddleware::ATTR_USER);
        if (!$user instanceof User) {
            throw HttpException::unauthorized(
                ErrorCodes::AUTH_INVALID_TOKEN,
                'Authentication required.',
            );
        }

        $input = $this->validator->parse($request, ChangePasswordInput::class);

        // Verify current password BEFORE any DB writes. password_verify
        // is constant-time and bcrypt-slow (~tens of ms). Same call
        // pattern as LoginController.
        $currentPasswordOk = password_verify(
            $input->current_password,
            $user->getPasswordHash(),
        );
        if (!$currentPasswordOk) {
            // Same status (401) and code as login failure. Don't
            // leak that the user exists / token is valid / token's
            // user mapping is valid — only that the credential the
            // body supplied is wrong.
            throw HttpException::unauthorized(
                ErrorCodes::AUTH_INVALID_CREDENTIALS,
                'Current password is incorrect.',
            );
        }

        // Reject "new == current" — UX safeguard against accidental
        // re-setting and a tiny security improvement (each change
        // should advance the secret). Check AFTER verifying current
        // to avoid telling an attacker "your guess for current is
        // also your guess for new" before they've confirmed the
        // current.
        if (hash_equals($input->current_password, $input->new_password)) {
            throw HttpException::validation([
                'new_password' => ['New password must differ from current password.'],
            ]);
        }

        // Capture pre-mutation state for audit. We snapshot a minimal
        // view of the user (just the password_changed_at) — never
        // log hashes.
        $beforeSnapshot = $this->minimalSnapshot($user);

        // Pre-compute fresh token pair + new bcrypt hash OUTSIDE the
        // transaction so the transaction is fast (the hash itself
        // is bcrypt-slow). Doing it inside would hold a row lock
        // for tens of ms.
        $pair = $this->jwt->issueTokenPair($user);
        $newPasswordHash = password_hash($input->new_password, PASSWORD_BCRYPT);
        $clientIp = $this->extractIp($request);
        $userAgent = $this->extractUserAgent($request);

        // All mutations in one transaction. Mirror reset-confirm's pattern.
        $this->em->wrapInTransaction(function () use (
            $user,
            $pair,
            $newPasswordHash,
            $clientIp,
            $userAgent,
        ): void {
            // setPasswordHash() also bumps user.password_changed_at,
            // which makes ALL old access tokens stale via the
            // AuthMiddleware pwd_changed_at check on the next request.
            $user->setPasswordHash($newPasswordHash);

            // Revoke all refresh tokens — including the requesting
            // client's. We then issue a fresh pair, so the user stays
            // logged in on THIS device. All others are kicked out.
            // Same security posture as reset-confirm.
            /** @var RefreshTokenRepository $refreshRepo */
            $refreshRepo = $this->em->getRepository(RefreshToken::class);
            $refreshRepo->revokeAllForUser($user, 'password_changed');

            // Persist the new refresh-token row for THIS session.
            $refreshRow = new RefreshToken(
                user: $user,
                jti: $pair->refreshTokenJti,
                tokenHash: $pair->refreshTokenHash(),
                expiresAt: $pair->refreshTokenExpiresAt,
                issuedIp: $clientIp,
                userAgent: $userAgent,
            );
            $refreshRepo->save($refreshRow, flush: false);

            $this->em->flush();
        });

        // Audit AFTER flush so the change is durable when the row lands.
        // Use recordUpdate with minimal before/after — the diff shows
        // that password_changed_at advanced; no hash content logged.
        $this->audit->recordUpdate(
            request: $request,
            actor: $user,
            subject: $user,
            beforeSnapshot: $beforeSnapshot,
            afterSnapshot: $this->minimalSnapshot($user),
        );

        return $this->ok([
            'access_token' => $pair->accessToken,
            'access_token_expires_at' => $pair->accessTokenExpiresAt->format(\DateTimeInterface::ATOM),
            'refresh_token' => $pair->refreshToken,
            'refresh_token_expires_at' => $pair->refreshTokenExpiresAt->format(\DateTimeInterface::ATOM),
            'user' => $this->userSerializer->publicProfile($user),
        ]);
    }

    /**
     * Minimal audit snapshot — records only that the password
     * changed (via the timestamp). We deliberately do NOT call
     * AuditEmitter::snapshot($user) because that returns the full
     * user shape, which is overkill for a password-change diff
     * and would emit noise into audit_log on every change.
     *
     * The snapshot is fed to recordUpdate which diffs before/after.
     * The only field changing in this snapshot is password_changed_at,
     * so the resulting audit row's `changes` will be:
     *
     *   { before: {password_changed_at: 'old'}, after: {password_changed_at: 'new'} }
     *
     * Confirming that a password change occurred without logging
     * the password itself (or its hash).
     *
     * @return array<string, mixed>
     */
    private function minimalSnapshot(User $user): array
    {
        return [
            'password_changed_at' => $user->getPasswordChangedAt()?->format(\DateTimeInterface::ATOM),
        ];
    }
}
