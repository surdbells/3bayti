<?php

declare(strict_types=1);

namespace Bayti\Api\Http\Controllers\Vendor\Onboarding;

use Bayti\Api\Domain\Audit\AuditEmitter;
use Bayti\Api\Domain\Catalog\Vendor;
use Bayti\Api\Domain\Catalog\VendorRepository;
use Bayti\Api\Domain\User\User;
use Bayti\Api\Http\Controllers\Vendor\Onboarding\Dto\SubmitOnboardingInput;
use Bayti\Api\Http\Errors\ErrorCodes;
use Bayti\Api\Http\Errors\HttpException;
use Bayti\Api\Http\Middleware\AuthMiddleware;
use Bayti\Api\Http\Responder;
use Bayti\Api\Http\Serializers\VendorSerializer;
use Bayti\Api\Http\Validator\RequestValidator;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * POST /v3/vendor/onboarding/submit (M3.2.X.6-D)
 *
 * Vendor self-serve onboarding submission. Creates a new Vendor
 * entity in 'pending' status owned by the authenticated user, and
 * flips the user's is_vendor flag to true so they can access the
 * onboarding status endpoint.
 *
 * Auth requirement: AuthMiddleware only (NOT VendorAuthMiddleware).
 * This is the endpoint that GRANTS vendor role, by definition the
 * caller is not yet a vendor when they hit it (or has never used
 * the platform's vendor capabilities even if the flag is somehow
 * set).
 *
 * Body shape:
 *   {
 *     "slug": "almas-fashion",
 *     "name": "Almas Fashion",
 *     "contact_email": "store@almas.example",
 *     "description"?: "Premium kaftans and abayas",
 *     "contact_phone"?: "+971501234567",
 *     "legal_name"?: "Almas Trading LLC"
 *   }
 *
 * Success response: 201 Created with onboardingShape:
 *   {
 *     "vendor": {
 *       "id": 123,
 *       "slug": "almas-fashion",
 *       "name": "Almas Fashion",
 *       "status": "pending",
 *       ...
 *     }
 *   }
 *
 * Errors:
 *   - 401 if no auth token
 *   - 409 if slug already taken (CONFLICT_SLUG_TAKEN)
 *   - 422 if validation fails (missing required fields, invalid
 *     email/phone/slug format)
 *
 * Side effects:
 *   - New Vendor entity persisted with status=pending,
 *     owner_user_id=auth user, is_store_approved=false,
 *     is_active=true
 *   - User::setRoles(vendor: true) flipped on the auth user
 *   - Audit recordCreate emitted on the new Vendor
 *
 * What this does NOT do
 * =====================
 *   - Send notification email to admin (out of scope; admins will
 *     see pending vendors in the GET /v3/admin/vendors listing)
 *   - Send notification email to the vendor user (out of scope; the
 *     onboarding status endpoint provides self-serve visibility)
 *   - Auto-approve (Q-OnboardingFlow=A: admin approval required)
 *   - Allow multiple submissions in flight from the same user (a
 *     user CAN own multiple Vendor entities, that's the multi-
 *     store pattern; per-slug uniqueness is the only constraint)
 */
final class SubmitOnboardingController
{
    use Responder;

    public function __construct(
        protected readonly ResponseFactoryInterface $responseFactory,
        private readonly RequestValidator $validator,
        private readonly EntityManagerInterface $em,
        private readonly VendorSerializer $serializer,
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

        $input = $this->validator->parse($request, SubmitOnboardingInput::class);

        /** @var VendorRepository $vendorRepo */
        $vendorRepo = $this->em->getRepository(Vendor::class);

        // Slug uniqueness check (Q-OnboardingFlow=A: pre-flight to
        // prevent confusing downstream DB-constraint errors).
        if ($vendorRepo->slugExists($input->slug)) {
            throw HttpException::conflict(
                'slug_taken',
                "Slug '{$input->slug}' is already taken. Choose a different slug.",
            );
        }

        // Create the Vendor entity. Constructor handles slug/name/
        // contact_email; remaining fields applied via setters.
        $vendor = new Vendor(
            slug: $input->slug,
            name: $input->name,
            contactEmail: $input->contact_email,
        );
        $vendor->setOwnerUser($user);
        if ($input->description !== null) {
            $vendor->setDescription($input->description);
        }
        if ($input->contact_phone !== null) {
            $vendor->setContactPhone($input->contact_phone);
        }
        if ($input->legal_name !== null) {
            $vendor->setLegalName($input->legal_name);
        }

        // Vendor status defaults to STATUS_PENDING per the entity's
        // initial state, no explicit transition needed.

        // Flip the user's vendor flag so they can access the
        // onboarding status endpoint + future vendor surfaces once
        // approved.
        $user->setRoles(vendor: true);

        $this->em->persist($vendor);
        $this->em->flush();

        $this->audit->recordCreate(
            request: $request,
            actor: $user,
            subject: $vendor,
            afterSnapshot: $this->audit->snapshot($vendor),
        );

        return $this->created([
            'vendor' => $this->serializer->onboardingShape($vendor),
        ]);
    }
}
