<?php

declare(strict_types=1);

namespace Bayti\Api\Http\Controllers\Profile;

use Bayti\Api\Domain\Audit\AuditEmitter;
use Bayti\Api\Domain\User\RefreshToken;
use Bayti\Api\Domain\User\RefreshTokenRepository;
use Bayti\Api\Domain\User\User;
use Bayti\Api\Http\Controllers\Profile\Dto\DeleteAccountInput;
use Bayti\Api\Http\Errors\ErrorCodes;
use Bayti\Api\Http\Errors\HttpException;
use Bayti\Api\Http\Middleware\AuthMiddleware;
use Bayti\Api\Http\Responder;
use Bayti\Api\Http\Validator\RequestValidator;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * DELETE /v3/me — delete the authenticated user's account.
 *
 * Semantics (operator-confirmed, M3.2.Y.6)
 * ----------------------------------------
 *   - Q6.1: BOTH deactivate() (is_active = false) AND softDelete()
 *     (deleted_at stamp). Deactivation locks the account out
 *     immediately — both LoginController and AuthMiddleware already
 *     reject !isActive() users, so every existing access token stops
 *     working on its next request and re-login is impossible. The
 *     soft-delete stamp marks the row for retention/eventual purge
 *     and lets support distinguish "deactivated" from "deleted".
 *   - Q6.2: re-authentication required. The caller must supply their
 *     current_password; a mismatch is 401 AUTH_INVALID_CREDENTIALS
 *     (same code/status as login + change-password).
 *   - Order history is retained (the soft delete does not cascade to
 *     orders); this is a soft-deactivate, not a hard wipe.
 *
 * On success: revoke all refresh tokens (the account is gone, so no
 * session may continue), record a 'deleted' audit event, return 204.
 *
 * No migration: the User entity already has deactivate()/softDelete()/
 * is_active/deleted_at, and RefreshTokenRepository::revokeAllForUser
 * already exists.
 */
final class DeleteAccountController
{
    use Responder;

    public function __construct(
        protected readonly ResponseFactoryInterface $responseFactory,
        private readonly RequestValidator $validator,
        private readonly EntityManagerInterface $em,
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

        $input = $this->validator->parse($request, DeleteAccountInput::class);

        // Re-authenticate BEFORE any writes. password_verify is
        // constant-time + bcrypt-slow. Same posture as change-password:
        // a wrong current_password is 401 AUTH_INVALID_CREDENTIALS and
        // leaks nothing beyond "that credential is wrong".
        $passwordOk = password_verify(
            $input->current_password,
            $user->getPasswordHash(),
        );
        if (!$passwordOk) {
            throw HttpException::unauthorized(
                ErrorCodes::AUTH_INVALID_CREDENTIALS,
                'Current password is incorrect.',
            );
        }

        // Snapshot for audit BEFORE mutation.
        $beforeSnapshot = $this->snapshot($user);

        // All mutations in one transaction.
        $this->em->wrapInTransaction(function () use ($user): void {
            // Q6.1: both flags. deactivate() locks out now; softDelete()
            // stamps deleted_at for retention/purge.
            $user->deactivate();
            $user->softDelete();

            // Revoke every refresh token — the account is gone; no
            // session may continue. (Access tokens already fail on
            // their next request via the AuthMiddleware !isActive
            // check.)
            /** @var RefreshTokenRepository $refreshRepo */
            $refreshRepo = $this->em->getRepository(RefreshToken::class);
            $refreshRepo->revokeAllForUser($user, 'account_deleted');

            $this->em->flush();
        });

        // Audit AFTER flush so the change is durable. recordDelete is
        // the canonical event for subject removal.
        $this->audit->recordDelete(
            request: $request,
            actor: $user,
            subject: $user,
            beforeSnapshot: $beforeSnapshot,
        );

        return $this->noContent();
    }

    /**
     * Minimal pre-deletion snapshot for the audit trail. No secrets.
     *
     * @return array<string, mixed>
     */
    private function snapshot(User $user): array
    {
        return [
            'id' => $user->getId(),
            'is_active' => $user->isActive(),
            'is_deleted' => $user->isDeleted(),
        ];
    }
}
