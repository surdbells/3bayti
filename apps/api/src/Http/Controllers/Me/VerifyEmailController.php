<?php

declare(strict_types=1);

namespace Bayti\Api\Http\Controllers\Me;

use Bayti\Api\Domain\User\OtpAttempt;
use Bayti\Api\Domain\User\OtpService;
use Bayti\Api\Domain\User\User;
use Bayti\Api\Domain\User\UserRepository;
use Bayti\Api\Domain\User\VerifyResult;
use Bayti\Api\Http\Controllers\Me\Dto\VerifyEmailInput;
use Bayti\Api\Http\Errors\ErrorCodes;
use Bayti\Api\Http\Errors\HttpException;
use Bayti\Api\Http\Middleware\AuthMiddleware;
use Bayti\Api\Http\Responder;
use Bayti\Api\Http\Validator\RequestValidator;
use Bayti\Api\Infrastructure\Otp\OtpProviderException;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * POST /v3/me/email/verify  (AuthMiddleware)
 *
 * Confirm the OTP from POST /v3/me/email. On success the current user's email
 * is switched to the verified address and is_email_verified becomes true (the
 * OTP proved the address is deliverable). Mirrors VerifyPhoneController.
 *
 *   - The OtpAttempt purpose must be PURPOSE_EMAIL_CHANGE (guards against
 *     cross-flow OTP reuse, e.g. a registration email OTP).
 *   - The attempt must belong to the current user.
 *
 * All verification failures collapse to one 401 OTP_VERIFICATION_FAILED.
 */
final class VerifyEmailController
{
    use Responder;

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

        $input = $this->validator->parse($request, VerifyEmailInput::class);

        $attempt = $this->otp->findAttempt($input->verification_id);
        if ($attempt === null) {
            throw $this->verificationFailed();
        }

        // Cross-flow guard: must be an email-change OTP for THIS user.
        if ($attempt->getPurpose() !== OtpAttempt::PURPOSE_EMAIL_CHANGE) {
            throw $this->verificationFailed();
        }
        $attemptUser = $attempt->getUser();
        if ($attemptUser === null || $attemptUser->getId() !== $user->getId()) {
            throw $this->verificationFailed();
        }

        try {
            $result = $this->otp->verify($input->verification_id, $input->code);
        } catch (OtpProviderException $e) {
            throw HttpException::upstreamFailure(
                ErrorCodes::OTP_PROVIDER_ERROR,
                'Could not verify the code right now. Please try again in a moment.',
                $e,
            );
        }

        if ($result !== VerifyResult::Success) {
            throw $this->verificationFailed();
        }

        // Promote the address that was actually OTP-verified (the attempt's
        // destination). Re-check uniqueness — it could have been claimed by
        // another account between the send and this verify.
        $verifiedEmail = $attempt->getEmail() ?? '';
        if ($verifiedEmail === '') {
            throw $this->verificationFailed();
        }

        /** @var UserRepository $users */
        $users = $this->em->getRepository(User::class);
        $owner = $users->findByEmail($verifiedEmail);
        if ($owner !== null && $owner->getId() !== $user->getId()) {
            throw HttpException::conflict(
                ErrorCodes::CONFLICT_EMAIL_TAKEN,
                'That email address is already registered.',
            );
        }

        $this->em->wrapInTransaction(function () use ($user, $verifiedEmail): void {
            $user->promotePendingEmail($verifiedEmail);
            $this->em->flush();
        });

        return $this->ok([
            'email' => $user->getEmail(),
            'is_email_verified' => true,
            'needs_email_update' => $user->needsEmailUpdate(),
        ]);
    }

    private function verificationFailed(): HttpException
    {
        return HttpException::unauthorized(
            ErrorCodes::OTP_VERIFICATION_FAILED,
            'Verification failed. Please check the code and try again.',
        );
    }
}
