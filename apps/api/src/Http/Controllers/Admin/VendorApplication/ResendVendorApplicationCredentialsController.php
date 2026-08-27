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

        // Resolve the seller's user account: the vendor owner if linked, else
        // by the application email (older approvals may predate the link).
        $user = $application->getVendor()?->getOwnerUser();
        if (!$user instanceof User) {
            /** @var UserRepository $userRepo */
            $userRepo = $this->em->getRepository(User::class);
            $user = $userRepo->findByEmail($application->getEmail());
        }
        if (!$user instanceof User) {
            throw HttpException::businessRuleViolation(
                message: 'No seller account is linked to this application.',
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
            'by_admin_id' => $admin->getId(),
        ]);

        return $this->ok([
            'application' => $this->serializer->adminShape($application),
        ]);
    }
}
