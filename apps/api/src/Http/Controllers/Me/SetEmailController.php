<?php

declare(strict_types=1);

namespace Bayti\Api\Http\Controllers\Me;

use Bayti\Api\Domain\User\OtpAttempt;
use Bayti\Api\Domain\User\OtpService;
use Bayti\Api\Domain\User\User;
use Bayti\Api\Domain\User\UserRepository;
use Bayti\Api\Http\Controllers\Me\Dto\SetEmailInput;
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
 * POST /v3/me/email  (AuthMiddleware)
 *
 * Request setting/changing the authenticated user's email: dispatch an OTP
 * to the given address and stash it as PENDING. The active email is NOT
 * changed until POST /v3/me/email/verify confirms the OTP (pending-email
 * model), so an abandoned change keeps the current email intact.
 *
 * Primary use case: a customer who signed in with Apple and got a private-
 * relay (…@privaterelay.appleid.com) or placeholder (.invalid) email that
 * can't receive our transactional mail, moving to a deliverable address.
 *
 * Guards:
 *   - 422 if the NEW address is itself non-deliverable (relay / placeholder)
 *   - 409 if the email is already registered to ANOTHER account
 *
 * Response: 200 { verification_id }, same contract as the phone OTP send.
 */
final class SetEmailController
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

        $input = $this->validator->parse($request, SetEmailInput::class);

        // The whole point is a deliverable address — reject a new email that is
        // itself an Apple relay / placeholder (or otherwise non-deliverable).
        if (User::isNonDeliverableEmail($input->email)) {
            throw HttpException::validation([
                'email' => ['Please use an email address that can receive messages (not an Apple private-relay address).'],
            ]);
        }

        /** @var UserRepository $users */
        $users = $this->em->getRepository(User::class);

        // Reject an address already registered to a DIFFERENT account.
        $owner = $users->findByEmail($input->email);
        if ($owner !== null && $owner->getId() !== $user->getId()) {
            throw HttpException::conflict(
                ErrorCodes::CONFLICT_EMAIL_TAKEN,
                'That email address is already registered.',
            );
        }

        // Pending-email model: stash the requested address and OTP it, without
        // touching the active email until the code is verified.
        if ($user->getPendingEmail() !== $input->email) {
            $user->setPendingEmail($input->email);
            $users->save($user);
        }

        try {
            $verificationId = $this->otp->send(
                to: $input->email,
                purpose: OtpAttempt::PURPOSE_EMAIL_CHANGE,
                channel: OtpAttempt::CHANNEL_EMAIL,
                user: $user,
                requestedIp: $this->extractIp($request),
            );
        } catch (OtpProviderException $e) {
            throw HttpException::upstreamFailure(
                ErrorCodes::OTP_PROVIDER_ERROR,
                'Could not send the verification email. Please try again in a moment.',
                $e,
            );
        }

        return $this->ok([
            'verification_id' => $verificationId,
        ]);
    }
}
