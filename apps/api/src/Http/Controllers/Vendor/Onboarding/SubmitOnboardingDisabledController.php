<?php

declare(strict_types=1);

namespace Bayti\Api\Http\Controllers\Vendor\Onboarding;

use Bayti\Api\Http\Errors\ErrorCodes;
use Bayti\Api\Http\Errors\HttpException;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * POST /v3/vendor/onboarding/submit, DISABLED (410 Gone).
 *
 * Self-serve vendor creation is closed. Sellers now APPLY via the public
 * POST /v3/vendor-applications endpoint and an admin approves the
 * application (which provisions the User + Vendor). This controller
 * replaces SubmitOnboardingController so the old route returns a clear
 * 410 pointing at the application flow rather than silently creating a
 * vendor.
 *
 * GET /v3/vendor/onboarding/status stays working for vendors who already
 * have a (pending/approved/suspended) store.
 */
final class SubmitOnboardingDisabledController
{
    public function __invoke(ServerRequestInterface $request): ResponseInterface
    {
        throw new HttpException(
            status: 410,
            errorCode: ErrorCodes::BUSINESS_RULE_VIOLATION,
            publicMessage: 'Self-serve vendor onboarding is closed. '
                . 'Please apply to become a seller at POST /v3/vendor-applications; '
                . 'an administrator will review your application.',
        );
    }
}
