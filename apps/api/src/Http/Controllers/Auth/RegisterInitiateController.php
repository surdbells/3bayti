<?php

declare(strict_types=1);

namespace Bayti\Api\Http\Controllers\Auth;

use Bayti\Api\Domain\User\OtpAttempt;
use Bayti\Api\Domain\User\OtpService;
use Bayti\Api\Domain\User\User;
use Bayti\Api\Domain\User\UserRepository;
use Bayti\Api\Http\Controllers\Auth\Dto\RegisterInitiateInput;
use Bayti\Api\Http\Errors\ErrorCodes;
use Bayti\Api\Http\Errors\HttpException;
use Bayti\Api\Http\RequestContext;
use Bayti\Api\Http\Responder;
use Bayti\Api\Http\Validator\RequestValidator;
use Bayti\Api\Infrastructure\Otp\OtpProviderException;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * POST /v3/auth/register/initiate
 *
 * Phone-first registration step 1 of 4. Checks phone availability and,
 * if free, sends an SMS-OTP to that phone.
 *
 *   Request:  { phone, country_code? }
 *   Response: 200 { verification_id }
 *
 * Failure modes
 * -------------
 *   - 409 CONFLICT_PHONE_TAKEN — phone already registered
 *   - 422 VALIDATION_FAILED    — body shape errors
 *   - 429 OTP_RATE_LIMITED     — phone hit hourly cap
 *   - 502 OTP_PROVIDER_ERROR   — MessageCentral down/refusing
 *
 * Unlike the existing /register (which creates the User row up front),
 * NO user is created here — the account is created later at
 * /register/submit once email + password are collected. This endpoint
 * only verifies the phone is free + reachable.
 *
 * Anti-enumeration note: we return an explicit 409 when the phone is
 * taken (matching the existing /register behaviour). The phone-first
 * flow is a deliberate UX choice and the conflict is surfaced for the
 * same reason /register surfaces it — phone-uniqueness is a hard
 * registration constraint the user must see to proceed.
 */
final class RegisterInitiateController
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
        $input = $this->validator->parse($request, RegisterInitiateInput::class);

        /** @var UserRepository $users */
        $users = $this->em->getRepository(User::class);

        if (!$users->isPhoneAvailable($input->phone)) {
            throw HttpException::conflict(
                ErrorCodes::CONFLICT_PHONE_TAKEN,
                'That phone number is already registered.',
            );
        }

        try {
            $verificationId = $this->otp->send(
                to: $input->phone,
                purpose: OtpAttempt::PURPOSE_REGISTRATION,
                channel: OtpAttempt::CHANNEL_SMS,
                user: null,
                requestedIp: $this->extractIp($request),
            );
        } catch (OtpProviderException $e) {
            throw HttpException::upstreamFailure(
                ErrorCodes::OTP_PROVIDER_ERROR,
                'Could not send verification code. Please try again in a moment.',
                $e,
            );
        }

        return $this->ok([
            'verification_id' => $verificationId,
        ]);
    }
}
