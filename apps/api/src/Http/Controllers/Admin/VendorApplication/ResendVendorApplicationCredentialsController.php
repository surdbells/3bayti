<?php

declare(strict_types=1);

namespace Bayti\Api\Http\Controllers\Admin\VendorApplication;

use Bayti\Api\Domain\Catalog\VendorApplication;
use Bayti\Api\Domain\Catalog\VendorApplicationRepository;
use Bayti\Api\Domain\User\User;
use Bayti\Api\Domain\User\UserRepository;
use Bayti\Api\Http\Errors\ErrorCodes;
use Bayti\Api\Http\Errors\HttpException;
use Bayti\Api\Http\Middleware\AuthMiddleware;
use Bayti\Api\Http\Responder;
use Bayti\Api\Http\Serializers\VendorApplicationSerializer;
use Bayti\Api\Notification\VendorApplicationWelcomeMailer;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Log\LoggerInterface;

/**
 * POST /v3/admin/vendor-applications/{id}/resend-credentials  (gated vendors.approve)
 *
 * Re-send the "your seller account is approved" welcome email with a fresh set
 * of login credentials for an already-APPROVED application. Used when the
 * original email never arrived, or a vendor lost access.
 *
 * Because we never store the temporary password in plaintext, "resend" means
 * ISSUE A NEW temporary password: we reset the seller's password to a fresh
 * temp value, flag the account must-change-on-next-sign-in, and email it. The
 * portal confirms first (this overwrites any password the vendor already set).
 *
 * Only valid for approved applications (400 otherwise). Idempotent-safe to
 * call repeatedly, each call simply issues a new temporary password.
 *
 * Success: 200 { "application": { ...adminShape } }
 */
final class ResendVendorApplicationCredentialsController
{
    use Responder;

    public function __construct(
        protected readonly ResponseFactoryInterface $responseFactory,
        private readonly EntityManagerInterface $em,
        private readonly VendorApplicationSerializer $serializer,
        private readonly VendorApplicationWelcomeMailer $welcomeMailer,
        private readonly LoggerInterface $logger,
    ) {
    }

    protected function getResponseFactory(): ResponseFactoryInterface
    {
        return $this->responseFactory;
    }

    /**
     * @param array<string, string> $args
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
            throw HttpException::notFound('Application not found.');
        }

        /** @var VendorApplicationRepository $appRepo */
        $appRepo = $this->em->getRepository(VendorApplication::class);
        $application = $appRepo->findById((int) $idRaw);
        if ($application === null) {
            throw HttpException::notFound('Application not found.');
        }

        if (!$application->isApproved()) {
            throw HttpException::businessRuleViolation(
                message: 'Only an approved application can have its credentials resent.',
            );
        }

        /** @var UserRepository $userRepo */
        $userRepo = $this->em->getRepository(User::class);

        // Resolve the account a LOGIN for this application's email actually
        // authenticates against. Login uses findByEmail() (non-deleted,
        // case-insensitive); the vendor->owner link is NOT guaranteed to point at
        // that same row — a linked owner can be soft-deleted, shadowed by a newer
        // account with the same email, or stored under a non-normalised email.
        // Resetting anything other than the login row emails a password that can
        // never work (the exact failure reported: a resend email's password 401s
        // at /auth/login while the store showed the account "active").
        $appEmail = $application->getEmail();
        $user = $userRepo->findByEmail($appEmail);

        if (!$user instanceof User) {
            // No live account for the application email. Fall back to the linked
            // owner ONLY when login can still reach it via its own (possibly
            // changed) email — otherwise resetting it hands out a dead credential.
            $owner = $application->getVendor()?->getOwnerUser();
            if (
                $owner instanceof User
                && !$owner->isDeleted()
                && $userRepo->findByEmail($owner->getEmail())?->getId() === $owner->getId()
            ) {
                $user = $owner;
            }
        }

        if (!$user instanceof User) {
            // The seller account can't be signed in to — deleted, or registered
            // under a different/non-normalised email. Surface it instead of
            // silently emailing a credential that will 401, so the operator
            // repairs the account (restore/de-duplicate/fix the email) first.
            throw HttpException::businessRuleViolation(
                message: 'No active seller account can be signed in to for '
                    . $appEmail . '. The account may have been deleted or registered '
                    . 'under a different email; repair the account, then resend.',
            );
        }

        // Issue a fresh temporary password (setPasswordHash clears the flag,
        // so re-flag AFTER) and email the credentials.
        $tempPassword = $this->welcomeMailer->generateTempPassword();
        $user->setPasswordHash(password_hash($tempPassword, PASSWORD_BCRYPT));
        $user->requirePasswordChange();
        $this->em->flush();

        $this->welcomeMailer->sendApprovalWelcome($user, $application, $tempPassword);

        $this->logger->info('vendor-application credentials resent', [
            'application_id' => $application->getId(),
            'user_id' => $user->getId(),
            'sent_to' => $user->getEmail(),
            'application_email' => $appEmail,
            'by_admin_id' => $admin->getId(),
        ]);

        return $this->ok([
            'application' => $this->serializer->adminShape($application),
            // Surface WHERE the credentials landed so a stale/mismatched owner
            // link is visible to the operator instead of silently misdelivered.
            'sent_to' => $user->getEmail(),
        ]);
    }
}
