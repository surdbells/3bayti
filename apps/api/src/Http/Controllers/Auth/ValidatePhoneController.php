<?php

declare(strict_types=1);

namespace Bayti\Api\Http\Controllers\Auth;

use Bayti\Api\Domain\User\User;
use Bayti\Api\Domain\User\UserRepository;
use Bayti\Api\Http\Controllers\Auth\Dto\ValidatePhoneInput;
use Bayti\Api\Http\Responder;
use Bayti\Api\Http\Validator\RequestValidator;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * POST /v3/auth/validate-phone
 *
 * Anonymous endpoint used by registration forms to check phone-number
 * availability before issuing an OTP. Returns
 *   { "available": bool, "phone": "<E.164>" }
 *
 * Why a separate endpoint from validate-email
 * -------------------------------------------
 * Phone availability is checked at a different point in the
 * registration flow (after OTP send) so the form treats it as a
 * separate field. Could be combined with /validate-email for fewer
 * round-trips, but separation matches the legacy mobile app's flow
 * which lets us migrate clients incrementally.
 *
 * Privacy note: same trade-off as validate-email. See that file
 * for reasoning.
 */
final class ValidatePhoneController
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
        $input = $this->validator->parse($request, ValidatePhoneInput::class);
        $normalised = trim($input->phone);

        /** @var UserRepository $users */
        $users = $this->em->getRepository(User::class);
        $available = $users->isPhoneAvailable($normalised);

        return $this->ok([
            'phone' => $normalised,
            'available' => $available,
        ]);
    }
}
