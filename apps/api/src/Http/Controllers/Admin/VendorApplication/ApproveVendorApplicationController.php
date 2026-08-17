<?php

declare(strict_types=1);

namespace Bayti\Api\Http\Controllers\Admin\VendorApplication;

use Bayti\Api\Domain\Audit\AuditEmitter;
use Bayti\Api\Domain\Catalog\Vendor;
use Bayti\Api\Domain\Catalog\VendorApplication;
use Bayti\Api\Domain\Catalog\VendorApplicationRepository;
use Bayti\Api\Domain\Catalog\VendorRepository;
use Bayti\Api\Domain\Common\SlugHelper;
use Bayti\Api\Domain\User\User;
use Bayti\Api\Domain\User\UserRepository;
use Bayti\Api\Http\Errors\ErrorCodes;
use Bayti\Api\Http\Errors\HttpException;
use Bayti\Api\Http\Middleware\AuthMiddleware;
use Bayti\Api\Http\RequestContext;
use Bayti\Api\Http\Responder;
use Bayti\Api\Http\Serializers\VendorApplicationSerializer;
use Bayti\Api\Notification\VendorApplicationWelcomeMailer;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Log\LoggerInterface;

/**
 * POST /v3/admin/vendor-applications/{id}/approve  (gated vendors.approve)
 *
 * Approve a pending seller application:
 *   1. Find or create a User by the application's email. A freshly-
 *      created user gets a random strong password hash, markEmailVerified()
 *      (admin-vetted), and setRoles(vendor: true).
 *   2. Create a Vendor (slug auto via SlugHelper::generateUnique,
 *      name=business_name, contactEmail=email, owner=user,
 *      legal_name=business_name, contact_phone) and call vendor->approve()
 *      so it is approved + active + is_store_approved in one step.
 *   3. Mark the application approved (vendor_id + reviewed_by + reviewed_at).
 *   4. Email the applicant a welcome/approval message with their login
 *      credentials — the portal URL, their email, and (for a freshly-created
 *      account) a temporary password to change on first sign-in. Non-blocking.
 *
 * Idempotent: re-calling on an already-approved application is a 200
 * no-op returning the existing record. Rejected applications cannot be
 * approved (422).
 *
 * Success: 200 { "application": { ...adminShape, vendor_id, status:"approved" } }
 */
final class ApproveVendorApplicationController
{
    use Responder;
    use RequestContext;

    public function __construct(
        protected readonly ResponseFactoryInterface $responseFactory,
        private readonly EntityManagerInterface $em,
        private readonly VendorApplicationSerializer $serializer,
        private readonly VendorApplicationWelcomeMailer $welcomeMailer,
        private readonly AuditEmitter $audit,
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

        // Idempotent: already approved → return the existing record.
        if ($application->isApproved()) {
            return $this->ok([
                'application' => $this->serializer->adminShape($application),
            ]);
        }

        // Rejected applications cannot be approved.
        if ($application->isRejected()) {
            throw HttpException::businessRuleViolation(
                message: 'This application has already been rejected and cannot be approved.',
            );
        }

        $before = $this->audit->snapshot($application);

        // 1. Find or provision the user.
        /** @var UserRepository $userRepo */
        $userRepo = $this->em->getRepository(User::class);
        $user = $userRepo->findByEmail($application->getEmail());
        $userWasCreated = false;
        // Set for a freshly-provisioned account so we can email the credential;
        // stays null when the applicant already had a 3bayti account (we never
        // reset a password they already control).
        $tempPassword = null;

        if ($user === null) {
            $tempPassword = $this->welcomeMailer->generateTempPassword();
            $user = new User(
                email: $application->getEmail(),
                phone: $application->getPhone(),
                passwordHash: password_hash($tempPassword, PASSWORD_BCRYPT),
                countryCode: $application->getCountryCode(),
            );
            $user->setName($application->getFirstName(), $application->getLastName());
            // Admin-vetted → email is trusted.
            $user->markEmailVerified();
            // They log in with the temp password once, then must replace it.
            $user->requirePasswordChange();
            $userWasCreated = true;
        }

        // Grant the vendor role + keep legacy store flags in sync.
        $user->setRoles(vendor: true);
        $user->setStoreState(approved: true, active: true);
        $this->em->persist($user);

        // 2. Create + approve the Vendor.
        /** @var VendorRepository $vendorRepo */
        $vendorRepo = $this->em->getRepository(Vendor::class);
        $slug = SlugHelper::generateUnique(
            $application->getBusinessName(),
            static fn (string $candidate): bool => $vendorRepo->slugExists($candidate),
        );

        $vendor = new Vendor(
            slug: $slug,
            name: $application->getBusinessName(),
            contactEmail: $application->getEmail(),
        );
        $vendor->setOwnerUser($user);
        $vendor->setLegalName($application->getBusinessName());
        $vendor->setContactPhone($application->getPhone());
        if ($application->getMessage() !== null) {
            $vendor->setDescription($application->getMessage());
        }
        if ($application->getLicenseNumber() !== null) {
            $vendor->setTradeLicenseNumber($application->getLicenseNumber());
            $user->setStoreInfo($application->getBusinessName(), $application->getLicenseNumber());
        }
        // approve() sets status=approved + is_store_approved=true atomically.
        $vendor->approve('Approved from seller application #' . $application->getId());
        $this->em->persist($vendor);

        // 3. Mark the application approved + link vendor + admin.
        $application->markApproved($vendor, $admin);

        $this->em->flush();

        // 4. Welcome the applicant + hand them their login credentials.
        //    Non-blocking: a mailer failure must NOT fail the approval (the
        //    account + vendor are already committed).
        $this->welcomeMailer->sendApprovalWelcome($user, $application, $tempPassword);

        $this->audit->recordUpdate(
            request: $request,
            actor: $admin,
            subject: $application,
            beforeSnapshot: $before,
            afterSnapshot: $this->audit->snapshot($application),
        );

        return $this->ok([
            'application' => $this->serializer->adminShape($application),
        ]);
    }
}
