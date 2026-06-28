<?php

declare(strict_types=1);

namespace Bayti\Api\Http\Controllers\Me;

use Bayti\Api\Domain\User\OtpAttempt;
use Bayti\Api\Domain\User\OtpService;
use Bayti\Api\Domain\User\User;
use Bayti\Api\Domain\User\UserRepository;
use Bayti\Api\Http\Controllers\Me\Dto\SetPhoneInput;
use Bayti\Api\Http\Errors\ErrorCodes;
use Bayti\Api\Http\Errors\HttpException;
use Bayti\Api\Http\Middleware\AuthMiddleware;
use Bayti\Api\Http\RequestContext;
use Bayti\Api\Http\Responder;
use Bayti\Api\Http\Validator\RequestValidator;
use Bayti\Api\Infrastructure\Otp\OtpProviderException;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * POST /v3/me/phone  (AuthMiddleware)
 *
 * Set (or change) the authenticated user's phone number and dispatch an
 * OTP to it. The number is stored immediately but marked UNVERIFIED;
 * POST /v3/me/phone/verify completes the loop.
 *
 * Primary use case: a user who signed up via Google/Apple (no phone)
 * adding one so they can receive SMS / unlock phone-gated features.
 *
 * Response: 200 { verification_id } — same contract as the registration
 * OTP send endpoints.
 *
 * Conflict: 409 if the number is already in use by ANOTHER user.
 */
final class SetPhoneController
{
    use Responder;
    use RequestContext;

    public function __construct(
        protected readonly ResponseFactoryInterface $responseFactory,
        private readonly RequestValidator $validator,
        private readonly EntityManagerInterface $em,
        private readonly OtpService $otp,
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

        $input = $this->validator->parse($request, SetPhoneInput::class);

        /** @var UserRepository $users */
        $users = $this->em->getRepository(User::class);

        // Reject a number already taken by a DIFFERENT account. (Same
        // number on the current user's own record is fine — re-sending.)
        $owner = $users->findByPhone($input->phone);
        if ($owner !== null && $owner->getId() !== $user->getId()) {
            throw HttpException::conflict(
                ErrorCodes::CONFLICT_PHONE_TAKEN,
                'That phone number is already registered.',
            );
        }

        // Store the new number (unverified) before sending, so verify can
        // confirm the OTP destination matches the user's pending phone.
        if ($user->getPhone() !== $input->phone) {
            $user->setPhone($input->phone);
            $users->save($user);
        }

        try {
            $verificationId = $this->otp->send(
                to: $input->phone,
                purpose: OtpAttempt::PURPOSE_PHONE_CHANGE,
                channel: OtpAttempt::CHANNEL_SMS,
                user: $user,
                requestedIp: $this->extractIp($request),
            );
        } catch (OtpProviderException) {
            throw HttpException::upstreamFailure(
                ErrorCodes::OTP_PROVIDER_ERROR,
                'Could not send verification code. Please try again in a moment.',
            );
        }

        return $this->ok([
            'verification_id' => $verificationId,
        ]);
    }
}
