<?php

declare(strict_types=1);

namespace Bayti\Api\Http\Controllers\Admin\Vendor;

use Bayti\Api\Domain\Audit\AuditEmitter;
use Bayti\Api\Domain\Catalog\Vendor;
use Bayti\Api\Domain\User\RefreshToken;
use Bayti\Api\Domain\User\RefreshTokenRepository;
use Bayti\Api\Domain\User\TemporaryPassword;
use Bayti\Api\Domain\User\User;
use Bayti\Api\Http\Controllers\Admin\Vendor\Dto\ResetVendorPasswordInput;
use Bayti\Api\Http\Errors\ErrorCodes;
use Bayti\Api\Http\Errors\HttpException;
use Bayti\Api\Http\Middleware\AuthMiddleware;
use Bayti\Api\Http\RequestContext;
use Bayti\Api\Http\Responder;
use Bayti\Api\Http\Validator\RequestValidator;
use Bayti\Api\Notification\VendorPasswordResetMailer;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * POST /v3/admin/vendors/{id}/reset-password
 *
 * Gated on `vendors.reset_password`. Resets the password of the vendor's
 * owner account (the seller login), for lockout recovery or a suspected
 * credential compromise.
 *
 * Body: see {@see ResetVendorPasswordInput}
 *   { "mode": "generate" | "manual", "password"?: string, "reason"?: string }
 *
 * Side effects, committed atomically in one transaction:
 *   1. New bcrypt hash, generated (generate) or admin-supplied (manual).
 *      setPasswordHash() bumps password_changed_at, which invalidates every
 *      existing ACCESS token for the owner on their next request.
 *   2. must_change_password = true, so the vendor replaces the admin-known
 *      credential on next sign-in (portal guard enforces it).
 *   3. All of the owner's REFRESH tokens are revoked ('admin_password_reset'),
 *      so every session on every device is terminated.
 *
 * After commit:
 *   - Audit row (ACTION_OVERRIDDEN on the owner User) with the mode, vendor,
 *     reason and email outcome. Never the password or its hash.
 *   - Email to the owner (non-blocking). In generate mode it carries the
 *     temporary password; in manual mode it is a security notice only.
 *
 * The generated password is never returned to the admin: the email is its
 * only channel. If delivery fails (`email_sent: false`) the admin can retry
 * or fall back to manual mode.
 *
 * Guards (same as impersonation): the vendor must have an owner account, it
 * can't be the acting admin's own account, staff accounts can't be reset
 * through this vendor action, and inactive owners are rejected.
 *
 * Returns 200:
 *   { "vendor_id", "owner_user_id", "mode", "email", "email_sent",
 *     "must_change_password": true, "sessions_revoked": int }
 *
 * Errors: 404 unknown vendor, 422 invalid body or guard failure, 403 staff
 * owner or missing permission.
 */
final class ResetVendorPasswordController
{
    use Responder;
    use RequestContext;

    public const REVOKE_REASON = 'admin_password_reset';

    public function __construct(
        protected readonly ResponseFactoryInterface $responseFactory,
        private readonly RequestValidator $validator,
        private readonly EntityManagerInterface $em,
        private readonly AuditEmitter $audit,
        private readonly VendorPasswordResetMailer $mailer,
        private readonly LoggerInterface $logger = new NullLogger(),
    ) {
    }

    protected function getResponseFactory(): ResponseFactoryInterface
    {
        return $this->responseFactory;
    }

    /**
     * @param array<string, string> $args Route placeholder values from Slim
     */
    public function __invoke(
        ServerRequestInterface $request,
        ResponseInterface $response,
        array $args,
    ): ResponseInterface {
        $admin = $request->getAttribute(AuthMiddleware::ATTR_USER);
        if (!$admin instanceof User) {
            throw HttpException::unauthorized(ErrorCodes::AUTH_INVALID_TOKEN, 'Authentication required.');
        }

        $idRaw = $args['id'] ?? '';
        if (!ctype_digit((string) $idRaw)) {
            throw HttpException::notFound('Vendor not found.');
        }

        $vendor = $this->em->getRepository(Vendor::class)->find((int) $idRaw);
        if (!$vendor instanceof Vendor) {
            throw HttpException::notFound('Vendor not found.');
        }

        $input = $this->validator->parse($request, ResetVendorPasswordInput::class);

        $owner = $this->resolveOwner($vendor, $admin);

        $plain = $input->isGenerate() ? TemporaryPassword::generate() : (string) $input->password;
        $newHash = password_hash($plain, PASSWORD_BCRYPT);
        $mustChangeBefore = $owner->mustChangePassword();

        /** @var RefreshTokenRepository $refreshRepo */
        $refreshRepo = $this->em->getRepository(RefreshToken::class);

        $revoked = $this->em->wrapInTransaction(
            function () use ($owner, $newHash, $refreshRepo): int {
                // Hash first: setPasswordHash() clears must_change_password,
                // so the requirement has to be (re)applied after it.
                $owner->setPasswordHash($newHash);
                $owner->requirePasswordChange();
                $count = $refreshRepo->revokeAllForUser($owner, self::REVOKE_REASON);
                $this->em->flush();
                return $count;
            },
        );

        $emailSent = $this->mailer->sendResetNotice(
            $owner,
            $vendor,
            $input->isGenerate() ? $plain : null,
        );

        $this->audit->recordOverride(
            request: $request,
            actor: $admin,
            subject: $owner,
            changes: [
                'before' => ['must_change_password' => $mustChangeBefore],
                'after' => ['must_change_password' => true, 'password_reset' => true],
                'reason' => $input->reason,
                'action' => 'vendor.password_reset',
                'vendor_id' => $vendor->getId(),
                'mode' => $input->mode,
                'sessions_revoked' => (int) $revoked,
                'email_sent' => $emailSent,
            ],
        );

        $this->logger->warning('admin.vendor.password_reset', [
            'admin_id' => $admin->getId(),
            'vendor_id' => $vendor->getId(),
            'owner_user_id' => $owner->getId(),
            'mode' => $input->mode,
            'sessions_revoked' => (int) $revoked,
            'email_sent' => $emailSent,
            'ip' => $this->extractIp($request),
        ]);

        return $this->ok([
            'vendor_id' => $vendor->getId(),
            'owner_user_id' => $owner->getId(),
            'mode' => $input->mode,
            'email' => $owner->getEmail(),
            'email_sent' => $emailSent,
            'must_change_password' => true,
            'sessions_revoked' => (int) $revoked,
        ]);
    }

    private function resolveOwner(Vendor $vendor, User $admin): User
    {
        $owner = $vendor->getOwnerUser();
        if (!$owner instanceof User) {
            throw HttpException::businessRuleViolation(
                message: 'This vendor has no owner account to reset.',
            );
        }
        if ($owner->getId() === $admin->getId()) {
            throw HttpException::businessRuleViolation(
                message: 'Use your own account settings to change your password.',
            );
        }
        if ($owner->isAdmin() || $owner->isFinance() || $owner->isSupport() || $owner->isSubAdmin()) {
            throw HttpException::forbidden('Staff account passwords cannot be reset from a vendor.');
        }
        if (!$owner->isActive()) {
            throw HttpException::businessRuleViolation(
                message: 'This account is inactive. Reactivate it before resetting the password.',
            );
        }
        return $owner;
    }
}
