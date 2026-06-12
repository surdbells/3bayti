<?php

declare(strict_types=1);

namespace Bayti\Api\Http\Controllers\Admin\User;

use Bayti\Api\Domain\User\RefreshToken;
use Bayti\Api\Domain\User\RefreshTokenRepository;
use Bayti\Api\Domain\User\User;
use Bayti\Api\Domain\User\UserRepository;
use Bayti\Api\Http\Controllers\Admin\User\Dto\AdminResetPasswordInput;
use Bayti\Api\Http\Errors\HttpException;
use Bayti\Api\Http\Middleware\AuthMiddleware;
use Bayti\Api\Http\PaginatedEnvelope;
use Bayti\Api\Http\Responder;
use Bayti\Api\Http\Serializers\UserSerializer;
use Bayti\Api\Http\Validator\RequestValidator;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Log\LoggerInterface;

/**
 * PATCH /v3/admin/users/{id}/password
 *
 * Admin-initiated password reset for another user's account. Unlike the
 * self-service change, this does not require the target's current password —
 * an admin is overriding it (locked-out staff, compromised credential).
 *
 * Security properties (matching the self-service flow):
 *   1. setPasswordHash() bumps password_changed_at, which invalidates every
 *      existing ACCESS token for the target on their next request.
 *   2. All of the target's REFRESH tokens are revoked, so they can't mint a
 *      new access token — every session is terminated.
 *   Together: an admin reset fully kicks the target out everywhere, which is
 *   the correct default for a credential-rotation / lockout-recovery action.
 *
 * The write is wrapped in a single transaction so the hash change and token
 * revocation commit atomically. Guarded by AdminAuthMiddleware + Auth; the
 * acting admin and target are recorded in the audit log.
 *
 * Returns 200 with the target's public profile (no tokens — the admin is not
 * the target, so there is nothing to re-issue for this session).
 */
final class AdminResetPasswordController
{
    use Responder;

    public function __construct(
        protected readonly ResponseFactoryInterface $responseFactory,
        private readonly RequestValidator $validator,
        private readonly EntityManagerInterface $em,
        private readonly UserSerializer $serializer,
        private readonly LoggerInterface $logger,
    ) {}

    protected function getResponseFactory(): ResponseFactoryInterface
    {
        return $this->responseFactory;
    }

    public function __invoke(ServerRequestInterface $request): ResponseInterface
    {
        $id    = (int) $request->getAttribute('id');
        $input = $this->validator->parse($request, AdminResetPasswordInput::class);

        /** @var UserRepository $users */
        $users = $this->em->getRepository(User::class);
        $target = $users->findById($id);
        if ($target === null) {
            throw HttpException::notFound('User not found.');
        }

        $newHash = password_hash($input->password, PASSWORD_BCRYPT);

        /** @var RefreshTokenRepository $refreshRepo */
        $refreshRepo = $this->em->getRepository(RefreshToken::class);

        $this->em->wrapInTransaction(function () use ($target, $users, $refreshRepo, $newHash): void {
            // 1. New hash (also bumps password_changed_at → access tokens die).
            $target->setPasswordHash($newHash);
            $users->save($target, flush: false);

            // 2. Revoke all refresh tokens → no new access tokens can be minted.
            $refreshRepo->revokeAllForUser($target, 'admin_password_reset');

            $this->em->flush();
        });

        $actingAdmin = $request->getAttribute(AuthMiddleware::ATTR_USER);
        $this->logger->info('admin.user.password_reset', [
            'target_user_id' => $target->getId(),
            'target_email'   => $target->getEmail(),
            'by_admin_id'    => $actingAdmin instanceof User ? $actingAdmin->getId() : null,
        ]);

        return $this->ok(
            PaginatedEnvelope::single($this->serializer->publicProfile($target)),
        );
    }
}
