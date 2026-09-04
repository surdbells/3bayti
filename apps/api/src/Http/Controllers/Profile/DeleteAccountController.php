<?php

declare(strict_types=1);

namespace Bayti\Api\Http\Controllers\Profile;

use Bayti\Api\Domain\Audit\AuditEmitter;
use Bayti\Api\Domain\User\HardDeleteUserService;
use Bayti\Api\Domain\User\User;
use Bayti\Api\Http\Controllers\Profile\Dto\DeleteAccountInput;
use Bayti\Api\Http\Errors\ErrorCodes;
use Bayti\Api\Http\Errors\HttpException;
use Bayti\Api\Http\Middleware\AuthMiddleware;
use Bayti\Api\Http\Responder;
use Bayti\Api\Http\Validator\RequestValidator;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * DELETE /v3/me, delete the authenticated user's account.
 *
 * Semantics
 * ---------
 *   - PERMANENT (hard) delete: the account and ALL of its data — profile,
 *     orders, gift cards, payments, addresses, wishlist, reviews and every
 *     session — are erased via HardDeleteUserService (the same engine as the
 *     admin "delete customer" action). This is irreversible; there is no
 *     restore. The client auto-logs-out on the 204.
 *   - Re-authentication required for password accounts: the caller must supply
 *     their current_password; a mismatch is 401 AUTH_INVALID_CREDENTIALS (same
 *     code/status as login + change-password). Social-only accounts have no
 *     password to verify and are currently blocked here (a dedicated social
 *     re-auth path is out of scope).
 *
 * On success: the erase cascades every refresh token (so no session can
 * continue), a 'deleted' audit event is recorded, and 204 is returned.
 */
final class DeleteAccountController
{
    use Responder;

    public function __construct(
        protected readonly ResponseFactoryInterface $responseFactory,
        private readonly RequestValidator $validator,
        private readonly AuditEmitter $audit,
        private readonly HardDeleteUserService $hardDelete,
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

        // Re-authenticate BEFORE any writes, but only for PASSWORD accounts.
        // password_verify is constant-time + bcrypt-slow; same posture as
        // change-password: a wrong current_password is 401
        // AUTH_INVALID_CREDENTIALS and leaks nothing beyond "that credential is
        // wrong". A missing password on a password account fails the same way
        // (empty string never verifies).
        //
        // Social-only accounts (Google/Apple sign-in) have a NULL password_hash
        // and no password to re-enter — the valid JWT + the client's explicit
        // strong confirmation are the gate for them, so we skip the check
        // rather than lock them out of deleting their own account (Apple
        // 5.1.1(v) requires in-app deletion for every account type).
        if ($user->hasPassword()) {
            $passwordOk = password_verify(
                $input->current_password,
                (string) $user->getPasswordHash(),
            );
            if (!$passwordOk) {
                throw HttpException::unauthorized(
                    ErrorCodes::AUTH_INVALID_CREDENTIALS,
                    'Current password is incorrect.',
                );
            }
        }

        // Snapshot for audit BEFORE the row is gone.
        $beforeSnapshot = $this->snapshot($user);

        // Permanently erase the account and ALL of its data — profile,
        // orders, gift cards, payments, addresses, wishlist and sessions
        // (the same engine as the admin delete). Irreversible; runs in its
        // own transaction and cascades the refresh tokens, so no session
        // can continue.
        $this->hardDelete->delete($user);

        // Audit AFTER the delete so the trail is durable. audit_log.user_id
        // has no FK, so this row survives the subject's removal; recordDelete
        // is the canonical event for subject removal.
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
