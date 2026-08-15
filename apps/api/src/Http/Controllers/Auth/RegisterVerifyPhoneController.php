<?php

declare(strict_types=1);

namespace Bayti\Api\Http\Controllers\Auth;

use Bayti\Api\Domain\User\OtpAttempt;
use Bayti\Api\Domain\User\OtpService;
use Bayti\Api\Domain\User\VerifyResult;
use Bayti\Api\Http\Controllers\Auth\Dto\RegisterVerifyPhoneInput;
use Bayti\Api\Http\Errors\ErrorCodes;
use Bayti\Api\Http\Errors\HttpException;
use Bayti\Api\Http\RequestContext;
use Bayti\Api\Http\Responder;
use Bayti\Api\Http\Validator\RequestValidator;
use Bayti\Api\Infrastructure\Auth\JwtService;
use Bayti\Api\Infrastructure\Otp\OtpProviderException;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * POST /v3/auth/register/verify-phone
 *
 * Phone-first registration step 2 of 4. Verifies the SMS-OTP and, on
 * success, mints a short-lived registration token bound to the phone.
 *
 *   Request:  { verification_id, code }
 *   Response: 200 { registration_token }
 *
 * The OtpAttempt MUST be a registration-purpose, SMS-channel row — a
 * password-reset OTP or an email-channel OTP submitted here is rejected
 * with the same uniform 401 (cross-flow abuse guard). The phone bound
 * into the token is the attempt's own phone, NOT anything supplied by
 * the client.
 *
 * On failure (unknown vid, wrong code, expired, consumed, wrong
 * purpose/channel) → 401 OTP_VERIFICATION_FAILED.
 */
final class RegisterVerifyPhoneController
{
    use Responder;
    use RequestContext;

    public function __construct(
        protected readonly ResponseFactoryInterface $responseFactory,
        private readonly RequestValidator $validator,
        private readonly OtpService $otp,
        private readonly JwtService $jwt,
    ) {
    }

    protected function getResponseFactory(): ResponseFactoryInterface
    {
        return $this->responseFactory;
    }

    public function __invoke(ServerRequestInterface $request): ResponseInterface
    {
        $input = $this->validator->parse($request, RegisterVerifyPhoneInput::class);

        // Look up the attempt first so we can reject cross-flow /
        // cross-channel submissions without calling MessageCentral.
        $attempt = $this->otp->findAttempt($input->verification_id);
        if ($attempt === null) {
            throw $this->verificationFailed();
        }
        if ($attempt->getPurpose() !== OtpAttempt::PURPOSE_REGISTRATION) {
            throw $this->verificationFailed();
        }
        if (!$attempt->isSmsChannel()) {
            // Phone verification must be the SMS channel.
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

        // Phone proven. Mint the registration token bound to it. The
        // attempt has no country_code, so default to 'AE' (the platform
        // default; the User entity uppercases / defaults the same way).
        $registrationToken = $this->jwt->issueRegistrationToken(
            phone: $attempt->getPhone(),
            countryCode: 'AE',
        );

        return $this->ok([
            'registration_token' => $registrationToken,
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
