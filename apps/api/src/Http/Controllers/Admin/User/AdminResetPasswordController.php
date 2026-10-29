<?php

declare(strict_types=1);

namespace Bayti\Api\Http\Controllers\Admin\User;

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
 * self-service change, this does not require the target's current password -
 * an admin is overriding it (locked-out staff, compromised credential).
 *
 * Sessions (matching the self-service flow):
 *   1. setPasswordHash() bumps password_changed_at. The target's existing
 *      ACCESS tokens fail the staleness check once and their clients
 *      recover transparently via /v3/auth/refresh.
 *   2. No REFRESH token is revoked. Product rule: a customer stays signed
 *      in on every device until they explicitly log out, so the reset only
 *      changes the password used for the NEXT sign-in. Ending sessions is
 *      the target's own /v3/auth/logout-all.
 *
 * The write is wrapped in a single transaction. Guarded by
 * AdminAuthMiddleware + Auth; the acting admin and target are recorded in
 * the audit log.
 *
 * Returns 200 with the target's public profile (no tokens, the admin is not
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

        $this->em->wrapInTransaction(function () use ($target, $users, $newHash): void {
            // New hash (also bumps password_changed_at; stale access tokens
            // recover via refresh). Sessions are deliberately left alone,
            // see class docblock.
            $target->setPasswordHash($newHash);
            $users->save($target, flush: false);

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
