<?php

declare(strict_types=1);

namespace Bayti\Api\Http\Controllers\Auth;

use Bayti\Api\Domain\User\User;
use Bayti\Api\Domain\User\UserRepository;
use Bayti\Api\Http\Controllers\Auth\Dto\ValidateEmailInput;
use Bayti\Api\Http\Responder;
use Bayti\Api\Http\Validator\RequestValidator;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * POST /v3/auth/validate-email
 *
 * Anonymous endpoint used by registration forms to check email
 * availability before submitting the full registration. Returns
 *   { "available": bool, "email": "<normalized>" }
 *
 * Why expose this at all
 * ----------------------
 * Without it, the frontend has to actually attempt registration to
 * find out the email is taken, wasteful UX (blocks form submission
 * + shows a generic error). With it, the frontend can show
 * "this email is already registered" inline as the user types.
 *
 * Privacy note
 * ------------
 * This endpoint DOES leak which emails are registered with the
 * platform. That's the unavoidable trade-off, we either give
 * better UX or guard the user-enumeration vector. We're choosing
 * UX because:
 *   1. Registration itself reveals the same info (failed register
 *      with a duplicate email returns a 409 with the same signal)
 *   2. Most platforms expose this for the same UX reason
 *   3. M5 hardening will add IP-based rate limiting (and phone-
 *      based for /validate-phone) to make bulk enumeration costly
 *
 * If we ever flip the policy to "always say available, fail at
 * registration time", change ONLY the response, no schema change.
 */
final class ValidateEmailController
{
    use Responder;

    public function __construct(
        protected readonly ResponseFactoryInterface $responseFactory,
        private readonly RequestValidator $validator,
        private readonly EntityManagerInterface $em,
    ) {
    }

    protected function getResponseFactory(): ResponseFactoryInterface
    {
        return $this->responseFactory;
    }

    public function __invoke(ServerRequestInterface $request): ResponseInterface
    {
        $input = $this->validator->parse($request, ValidateEmailInput::class);

        // findByEmail handles case-insensitivity + trim normalization.
        // We re-normalize here so the response echoes the canonical form
        // back to the client (helpful for tests and for clients that
        // want to display the normalized email).
        $normalised = strtolower(trim($input->email));

        /** @var UserRepository $users */
        $users = $this->em->getRepository(User::class);
        $available = $users->isEmailAvailable($normalised);

        return $this->ok([
            'email' => $normalised,
            'available' => $available,
        ]);
    }
}
