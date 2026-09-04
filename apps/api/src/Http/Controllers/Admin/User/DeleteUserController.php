<?php

declare(strict_types=1);

namespace Bayti\Api\Http\Controllers\Admin\User;

use Bayti\Api\Domain\Audit\AuditEmitter;
use Bayti\Api\Domain\User\HardDeleteUserService;
use Bayti\Api\Domain\User\User;
use Bayti\Api\Domain\User\UserRepository;
use Bayti\Api\Http\Errors\ErrorCodes;
use Bayti\Api\Http\Errors\HttpException;
use Bayti\Api\Http\Middleware\AuthMiddleware;
use Bayti\Api\Http\Responder;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * DELETE /v3/admin/users/{id}
 *
 * PERMANENTLY delete a customer account and ALL of its data — profile, orders,
 * order items, addresses, payment transactions, gift cards, promo redemptions,
 * return requests, cart, wishlist, sessions, etc. Irreversible. Distinct from
 * POST .../deactivate (a reversible suspension via is_active) — this is a hard
 * erase (HardDeleteUserService).
 *
 * Guards (all 4xx, nothing is deleted):
 *   - 404 if no such (non-deleted) user.
 *   - 422 if the target is the acting admin themselves.
 *   - 422 if the target is a staff or vendor account — this action is for
 *     CUSTOMER accounts only. Staff should be off-boarded via role/deactivate
 *     flows; vendor businesses carry catalog + payout history of their own.
 *
 * Gated by the `users.delete` permission (super admins hold it implicitly).
 */
final class DeleteUserController
{
    use Responder;

    public function __construct(
        protected readonly ResponseFactoryInterface $responseFactory,
        private readonly EntityManagerInterface $em,
        private readonly HardDeleteUserService $hardDelete,
        private readonly AuditEmitter $audit,
    ) {
    }

    protected function getResponseFactory(): ResponseFactoryInterface
    {
        return $this->responseFactory;
    }

    public function __invoke(ServerRequestInterface $request): ResponseInterface
    {
        $actor = $request->getAttribute(AuthMiddleware::ATTR_USER);
        if (!$actor instanceof User) {
            throw HttpException::unauthorized(
                ErrorCodes::AUTH_INVALID_TOKEN,
                'Authentication required.',
            );
        }

        $id = (int) $request->getAttribute('id');
        if ($id <= 0) {
            throw HttpException::notFound('User not found.');
        }

        /** @var UserRepository $repo */
        $repo = $this->em->getRepository(User::class);
        $target = $repo->findById($id);
        if ($target === null) {
            throw HttpException::notFound('User not found.');
        }

        // Never let an admin delete their own account through this endpoint.
        if ($target->getId() === $actor->getId()) {
            throw HttpException::businessRuleViolation(
                ErrorCodes::BUSINESS_RULE_VIOLATION,
                'You cannot delete your own account.',
            );
        }

        // Customer accounts only. Staff and vendor accounts are out of scope:
        // staff are off-boarded via role/deactivate, and a vendor account owns
        // catalog + payout history that must not be erased this way.
        if ($target->isStaff() || $target->isVendor()) {
            throw HttpException::businessRuleViolation(
                ErrorCodes::BUSINESS_RULE_VIOLATION,
                'Only customer accounts can be permanently deleted.',
            );
        }

        // Snapshot the essentials for the audit trail BEFORE the row is gone.
        $snapshot = [
            'id' => $target->getId(),
            'email' => $target->getEmail(),
            'phone' => $target->getPhone(),
            'is_active' => $target->isActive(),
            'is_deleted' => $target->isDeleted(),
        ];

        $this->hardDelete->delete($target);

        // Record the erasure. audit_log.user_id has no FK, so this row survives
        // the subject's deletion; recordDelete is the canonical removal event.
        $this->audit->recordDelete(
            request: $request,
            actor: $actor,
            subject: $target,
            beforeSnapshot: $snapshot,
        );

        return $this->noContent();
    }
}
