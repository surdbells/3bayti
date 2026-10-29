<?php

declare(strict_types=1);

namespace Bayti\Api\Http\Controllers\Auth;

use Bayti\Api\Domain\User\RefreshToken;
use Bayti\Api\Domain\User\RefreshTokenRepository;
use Bayti\Api\Http\Controllers\Auth\Dto\RefreshInput;
use Bayti\Api\Http\Errors\ErrorCodes;
use Bayti\Api\Http\Errors\HttpException;
use Bayti\Api\Http\RequestContext;
use Bayti\Api\Http\Responder;
use Bayti\Api\Http\Serializers\UserSerializer;
use Bayti\Api\Http\Validator\RequestValidator;
use Bayti\Api\Infrastructure\Auth\JwtService;
use Bayti\Api\Infrastructure\Auth\TokenPair;
use Doctrine\DBAL\LockMode;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Log\LoggerInterface;

/**
 * POST /v3/auth/refresh
 *
 * Exchange a refresh token for a fresh access + refresh pair. If the
 * user's access token expires, the client calls this endpoint with its
 * stored refresh token to get fresh credentials without asking the user
 * to sign in again.
 *
 * Response (200), same shape as /login, /confirm, /reset/confirm
 *   {
 *     "access_token": "...",
 *     "access_token_expires_at": "...",
 *     "refresh_token": "...",
 *     "refresh_token_expires_at": "...",
 *     "user": { ... }
 *   }
 *
 * Product rule
 * ------------
 * A customer stays signed in FOREVER until they explicitly tap "Log out".
 * Signing in elsewhere, changing or resetting a password, an admin
 * password reset, a phone-claim account merge, a retry, a replay or a
 * parallel refresh must never end a session on any device. The only
 * definitive "this session is over" signal is 401
 * AUTH_REFRESH_TOKEN_INVALID from this endpoint, and it is returned only
 * when the presented token's own session really has ended: logged out
 * (that device's family, or every family via logout-all), deactivated or
 * deleted account, token past its 5-year sliding expiry, or a token we
 * don't know. Clients treat every other failure as transient.
 *
 * Session families
 * ----------------
 * Each sign-in starts a family (RefreshToken::$familyId = the first
 * token's jti). Every rotation revokes the presented row with reason
 * 'rotated' and inserts the replacement in the SAME family, so one
 * family is one device's whole token history. (A password change, a
 * reset made while signed in, or a phone-claim merge hands the device a
 * new pair in that same family too, via the access token's `sid`
 * claim.) Logout ends the device's family
 * (RefreshTokenRepository::revokeFamily); logout-all ends all of them.
 * Nothing else revokes anything.
 *
 * Rotated tokens stay re-issuable
 * -------------------------------
 * A token revoked by ROTATION is accepted again, at any age, and simply
 * rotated once more (a fresh pair in the same family). Re-presenting a
 * rotated token is overwhelmingly innocent: the rotation response was
 * lost (dropped connection, app suspended or killed mid-refresh), two
 * tabs refreshed at once, or the app wrote a stale copy of its session
 * back to storage. The old design treated that as theft and revoked
 * EVERY session of the user, labelling them 'reuse_detected'; each other
 * device that later presented one of those rows re-triggered the wipe,
 * a self-feeding cascade ("I signed in on the web and my phone logged
 * me out"). Reuse therefore no longer revokes anything, and a rejected
 * token never touches any row but its own.
 *
 * Security trade-off: refresh-token THEFT DETECTION is deliberately given
 * up in exchange for never signing customers out. What remains: tokens
 * are hashed at rest and signature-checked; explicit logout kills the
 * whole family, so none of that device's old tokens can be replayed;
 * logout-all kills every family; access tokens (the real bearer
 * credential) still live only 15 minutes.
 *
 * Verification flow
 * -----------------
 *   1. Cryptographic verification via JwtService::verifyRefreshToken
 *      (signature, issuer, audience, JWT exp, required claims).
 *   2. DB lookup by jti. Missing row = forged, purged or deleted user.
 *   3. Hash match of the presented raw token against token_hash.
 *   4. Revoked? 'rotated' falls through (replay, re-issue); any other
 *      reason is a 401 for THIS token only.
 *   5. Not expired per the row's expires_at.
 *   6. The row's user (not the JWT sub: a phone-claim merge re-points
 *      rows to the surviving account) is active and not deleted.
 *   7. The response profile is built BEFORE rotating, so a serializer or
 *      DB failure there can't rotate the token and then 500.
 *   8. One transaction: re-read the row under a row lock and re-run
 *      steps 4-6 (a logout, logout-all or merge may have committed
 *      since), then revoke the row as 'rotated', insert the new row in
 *      the same family, record the login.
 *
 * Every rejection is logged (warning) with a reason tag and the user id
 * when known, and a rotated-token replay is logged at info, so a
 * reported logout can be traced. Token values are never logged.
 *
 * All failure modes
 * -----------------
 * Unified 401 with AUTH_REFRESH_TOKEN_INVALID. Distinguishing them
 * publicly leaks state; the reason lives in the server log only.
 */
final class RefreshController
{
    use Responder;
    use RequestContext;

    public function __construct(
        protected readonly ResponseFactoryInterface $responseFactory,
        private readonly RequestValidator $validator,
        private readonly EntityManagerInterface $em,
        private readonly JwtService $jwt,
        private readonly UserSerializer $userSerializer,
        private readonly LoggerInterface $logger,
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
            throw $this->reject('invalid_jwt');
        }

        // Step 2: DB lookup by jti.
        /** @var RefreshTokenRepository $refreshRepo */
        $refreshRepo = $this->em->getRepository(RefreshToken::class);
        $row = $refreshRepo->findByJti($claims->jti);
        if ($row === null) {
            // Validly signed but not in our DB: forged with a leaked
            // secret, purged by cleanup, or the user was hard-deleted
            // (rows cascade). The signed sub is trustworthy for the log.
            throw $this->reject('unknown_jti', $claims->userId);
        }

        $user = $row->getUser();

        // Step 3: hash match. Defense-in-depth, a hash mismatch with a
        // valid signature is a real anomaly worth refusing.
        if (!$row->matchesToken($input->refresh_token)) {
            throw $this->reject('hash_mismatch', $user->getId());
        }

        // Step 4: a ROTATED token falls through: a lost response, a
        // parallel refresh or a stale stored copy, re-issued below.
        if ($row->wasRotated()) {
            $this->logger->info('auth.refresh.rotated_replay', [
                'user_id' => $user->getId(),
                'token_row_id' => $row->getId(),
                'rotated_at' => $row->getRevokedAt()?->format(\DateTimeInterface::ATOM),
            ]);
        }

        // Steps 4-6: ended session, expiry, user state (see
        // rejectionReason). Checked here without a lock so a dead token
        // never opens a transaction, and again under the lock in step 8.
        $reason = $this->rejectionReason($row);
        if ($reason !== null) {
            throw $this->reject($reason, $user->getId());
        }

        // Step 7: build the response profile BEFORE rotating, so a
        // serializer/DB failure here leaves the presented token untouched
        // instead of rotating it and then answering 500.
        $profile = $this->userSerializer->publicProfile($user);

        $clientIp = $this->extractIp($request);
        $userAgent = $this->extractUserAgent($request);

        // Step 8: rotate, in one transaction that FIRST re-reads the
        // presented row under a row lock (SELECT ... FOR UPDATE) and
        // re-checks it. Logout, logout-all and the phone-claim merge lock
        // the same rows before writing (RefreshTokenRepository), so a
        // refresh can no longer interleave with them: without the lock a
        // logout committed between the checks above and this write was
        // overwritten ('rotated' over 'logout', plus a new live row), a
        // logout could miss the row a concurrent refresh was inserting,
        // and a merge could leave that row on the retired account.
        //
        // A rejection found under the lock writes nothing and returns its
        // reason (throwing inside wrapInTransaction would close the EM).
        /** @var array{TokenPair, array<string, mixed>}|string $outcome */
        $outcome = $this->em->wrapInTransaction(function () use (
            $row,
            $user,
            $profile,
            $claims,
            $clientIp,
            $userAgent,
            $refreshRepo,
        ): array|string {
            $this->em->refresh($row, LockMode::PESSIMISTIC_WRITE);

            $reason = $this->rejectionReason($row);
            if ($reason !== null) {
                return $reason;
            }

            // A merge that committed meanwhile re-pointed the row to the
            // surviving account: continue as that account.
            $lockedUser = $row->getUser();
            if ($lockedUser !== $user) {
                $profile = $this->userSerializer->publicProfile($lockedUser);
            }

            // Carry the impersonator through so a rotated admin-
            // impersonation session stays an impersonation session, and
            // the family so the device keeps ONE family.
            $newPair = $this->jwt->issueTokenPair($lockedUser, $claims->impersonatorId, $row->getFamilyId());

            // A no-op on a replayed, already-rotated row, which keeps its
            // original timestamp.
            $row->revoke(RefreshToken::REASON_ROTATED);

            $newRow = new RefreshToken(
                user: $lockedUser,
                jti: $newPair->refreshTokenJti,
                tokenHash: $newPair->refreshTokenHash(),
                expiresAt: $newPair->refreshTokenExpiresAt,
                issuedIp: $clientIp,
                userAgent: $userAgent,
                familyId: $newPair->familyId,
            );
            $refreshRepo->save($newRow, flush: false);

            $lockedUser->recordLogin($clientIp);

            $this->em->flush();

            return [$newPair, $profile];
        });

        if (is_string($outcome)) {
            throw $this->reject($outcome, $row->getUser()->getId());
        }
        [$newPair, $profile] = $outcome;

        return $this->ok([
            'access_token' => $newPair->accessToken,
            'access_token_expires_at' => $newPair->accessTokenExpiresAt->format(\DateTimeInterface::ATOM),
            'refresh_token' => $newPair->refreshToken,
            'refresh_token_expires_at' => $newPair->refreshTokenExpiresAt->format(\DateTimeInterface::ATOM),
            'user' => $profile,
        ]);
    }

    /**
     * Why this row can't be re-issued, as a log reason tag, or null when
     * it can (steps 4-6):
     *
     *   4. revoked for any reason but ROTATION: the session was ended on
     *      purpose (logout / logout_all, or a historical reason). Only
     *      THIS token is refused; no other row is touched, ever.
     *   5. past expires_at. The JWT exp claim normally catches this in
     *      step 1; the DB row is the authoritative limit.
     *   6. the row's user may no longer sign in.
     */
    private function rejectionReason(RefreshToken $row): ?string
    {
        if ($row->isRevoked() && !$row->wasRotated()) {
            return 'revoked:' . ($row->getRevokedReason() ?? 'unknown');
        }
        if ($row->isExpired()) {
            return 'expired';
        }
        $user = $row->getUser();
        if (!$user->isActive() || $user->isDeleted()) {
            return 'inactive_user';
        }
        return null;
    }

    /**
     * Log why a refresh was refused and build the uniform 401. The reason
     * tag never reaches the client (no oracle); the token value is never
     * logged.
     */
    private function reject(string $reason, ?int $userId = null): HttpException
    {
        $this->logger->warning('auth.refresh.rejected', [
            'reason' => $reason,
            'user_id' => $userId,
        ]);

        return HttpException::unauthorized(
            ErrorCodes::AUTH_REFRESH_TOKEN_INVALID,
            'Refresh token is invalid or expired.',
        );
    }
}
