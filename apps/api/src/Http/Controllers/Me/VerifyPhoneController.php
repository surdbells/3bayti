<?php

declare(strict_types=1);

namespace Bayti\Api\Http\Controllers\Me;

use Bayti\Api\Domain\User\OtpAttempt;
use Bayti\Api\Domain\User\OtpService;
use Bayti\Api\Domain\User\User;
use Bayti\Api\Domain\User\VerifyResult;
use Bayti\Api\Http\Controllers\Me\Dto\VerifyPhoneInput;
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
 * POST /v3/me/phone/verify  (AuthMiddleware)
 *
 * Confirm the OTP from POST /v3/me/phone. On success the current user's
 * phone becomes verified (is_phone_verified = true).
 *
 * Mirrors /v3/auth/confirm's verification flow but:
 *   - The user is ALREADY authenticated — no token pair is issued.
 *   - The OtpAttempt purpose must be PURPOSE_PHONE_CHANGE (not
 *     registration), guarding against cross-flow OTP reuse.
 *   - The attempt must belong to the current user.
 *
 * All verification failures collapse to one 401 OTP_VERIFICATION_FAILED
 * (no oracle on OTP state).
 */
final class VerifyPhoneController
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

        $input = $this->validator->parse($request, VerifyPhoneInput::class);

        $attempt = $this->otp->findAttempt($input->verification_id);
        if ($attempt === null) {
            throw $this->verificationFailed();
        }

        // Cross-flow guard: must be a phone-change OTP for THIS user.
        if ($attempt->getPurpose() !== OtpAttempt::PURPOSE_PHONE_CHANGE) {
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
                'Could not verify code right now. Please try again in a moment.',
                $e,
            );
        }

        if ($result !== VerifyResult::Success) {
            throw $this->verificationFailed();
        }

        $this->em->wrapInTransaction(function () use ($user): void {
            $user->markPhoneVerified();
            $this->em->flush();
        });

        return $this->ok([
            'phone' => $user->getPhone(),
            'is_phone_verified' => true,
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
