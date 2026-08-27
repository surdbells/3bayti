<?php

declare(strict_types=1);

namespace Bayti\Api\Http\Controllers\Vendor\Onboarding;

use Bayti\Api\Domain\Catalog\Vendor;
use Bayti\Api\Domain\Catalog\VendorRepository;
use Bayti\Api\Domain\User\User;
use Bayti\Api\Http\Errors\ErrorCodes;
use Bayti\Api\Http\Errors\HttpException;
use Bayti\Api\Http\Middleware\AuthMiddleware;
use Bayti\Api\Http\Responder;
use Bayti\Api\Http\Serializers\VendorSerializer;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * GET /v3/vendor/onboarding/status (M3.2.X.6-D)
 *
 * Vendor self-serve visibility into their own store(s) lifecycle
 * state. Returns ALL vendors owned by the authenticated user
 * regardless of status, so pending vendors can see they're still
 * waiting for review, and suspended vendors can see the reason.
 *
 * Auth design (Q-MiddlewareGate=A + Option I locked in plan)
 * ============================================================
 * This endpoint is the ONLY vendor endpoint that legitimately
 * allows non-approved vendors through. Two options were considered:
 *
 *   Option I:  Separate route group with AuthMiddleware only +
 *              inline is_vendor check in the controller
 *   Option II: Add VendorAuthMiddleware::ALLOW_NON_APPROVED_PATHS
 *              constant array; middleware bypasses gate for matching
 *              paths
 *
 * Option I won, simpler, isolated to one controller, doesn't
 * complicate the shared middleware with one-off exceptions.
 *
 * Flow:
 *   1. AuthMiddleware verifies token + populates user attribute
 *   2. This controller's inline is_vendor check filters out non-
 *      vendor users (returns 403 with FORBIDDEN, they should
 *      submit onboarding first)
 *   3. Repository query fetches all vendors owned by the user
 *
 * Response shape:
 *   {
 *     "vendors": [
 *       { "id": 1, "slug": "...", "status": "pending", ... },
 *       { "id": 2, "slug": "...", "status": "approved", ... }
 *     ]
 *   }
 *
 * Multi-vendor users see all their stores in one response. A vendor
 * with no stores (is_vendor=true but never submitted) sees an empty
 * vendors array, semantically correct, not a 404.
 *
 * Errors:
 *   - 401 if no auth token (AuthMiddleware)
 *   - 403 if user is not a vendor (inline check), code FORBIDDEN
 *
 * Audit
 * =====
 * This endpoint does NOT emit an audit row. Q-Audit=A scope is
 * limited to state transitions. Self-serve reads of own data are
 * not auditable events.
 */
final class GetOnboardingStatusController
{
    use Responder;

    public function __construct(
        protected readonly ResponseFactoryInterface $responseFactory,
        private readonly EntityManagerInterface $em,
        private readonly VendorSerializer $serializer,
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

        // Inline is_vendor check (Option I): plain customers shouldn't
        // hit this endpoint. They should submit onboarding first via
        // POST /v3/vendor/onboarding/submit, which flips is_vendor.
        if (!$user->isVendor()) {
            throw HttpException::forbidden(
                'You have not submitted vendor onboarding yet. Submit your store details first.',
            );
        }

        /** @var VendorRepository $repo */
        $repo = $this->em->getRepository(Vendor::class);
        $vendors = $repo->findByOwnerUser($user);

        return $this->ok([
            'vendors' => $this->serializer->onboardingShapeMany($vendors),
        ]);
    }
}
