<?php

declare(strict_types=1);

namespace Bayti\Api\Http\Controllers\Auth;

use Bayti\Api\Domain\User\OtpAttempt;
use Bayti\Api\Domain\User\OtpService;
use Bayti\Api\Domain\User\User;
use Bayti\Api\Domain\User\UserRepository;
use Bayti\Api\Http\Controllers\Auth\Dto\OtpLoginSendInput;
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
 * POST /v3/auth/otp-login/send
 *
 * Passwordless-login step 1. Resolves a user by phone (channel=phone)
 * or email (channel=email) and, if that user exists + is active + has
 * completed registration, sends a login OTP to that identifier:
 *
 *   - channel=phone → SMS-OTP via MessageCentral (same /verification/v3
 *     flow channel registration uses)
 *   - channel=email → email-OTP via the local provider (ZeptoMail)
 *
 * Always returns { verification_id }.
 *
 * Request
 * -------
 *   { "channel": "phone", "phone": "+9715...", "country_code": "AE" }
 *   { "channel": "email", "email": "alice@example.com" }
 *
 * Response (always 200)
 * ---------------------
 *   { "verification_id": "..." }
 *
 * Anti-enumeration
 * ----------------
 * Identical to ResetController: the response shape is the same whether
 * the identifier maps to a real user or not. Unknown / inactive /
 * unverified accounts get a 'fake-' verification_id that simply won't
 * validate at /otp-login/verify. The goal is to make BULK probing
 * expensive (each /verify attempt is rate-limited by the provider),
 * not to make single-shot detection impossible.
 *
 * Edge cases that yield a fake vid
 * --------------------------------
 *   - No user with that phone / email
 *   - User exists but is_active = false (disabled account can't log in)
 *   - User exists but is_phone_verified = false (registration not
 *     completed — there is no usable session to mint yet)
 *   - channel=email but the user has no stored phone is irrelevant
 *     here (email channel sends to the email); channel=phone but the
 *     stored phone is null yields a fake vid (nothing to SMS).
 *
 * Purpose
 * -------
 * The OtpAttempt is stamped PURPOSE_LOGIN_2FA so /otp-login/verify can
 * reject cross-flow submissions (a registration or reset OTP replayed
 * here, or a login OTP replayed at /confirm).
 */
final class OtpLoginSendController
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
        $input = $this->validator->parse($request, OtpLoginSendInput::class);

        if ($input->channel === 'email') {
            return $this->sendViaEmail($request, $input->email);
        }

        return $this->sendViaPhone($request, $input->phone);
    }

    /**
     * Email channel: resolve by email, send an email-OTP to that email.
     */
    private function sendViaEmail(ServerRequestInterface $request, ?string $email): ResponseInterface
    {
        /** @var UserRepository $users */
        $users = $this->em->getRepository(User::class);
        $user = $email !== null ? $users->findByEmail($email) : null;

        if ($user === null || !$user->isActive() || !$user->isPhoneVerified() || $email === null) {
            return $this->fakeVid();
        }

        return $this->dispatch(
            request: $request,
            to: $email,
            channel: OtpAttempt::CHANNEL_EMAIL,
            user: $user,
        );
    }

    /**
     * Phone channel: resolve by the supplied phone, SMS the registered
     * phone (which equals the supplied phone on a match).
     */
    private function sendViaPhone(ServerRequestInterface $request, ?string $phone): ResponseInterface
    {
        /** @var UserRepository $users */
        $users = $this->em->getRepository(User::class);
        $user = $phone !== null ? $users->findByPhone($phone) : null;

        if (
            $user === null
            || !$user->isActive()
            || !$user->isPhoneVerified()
            || $user->getPhone() === null
        ) {
            return $this->fakeVid();
        }

        return $this->dispatch(
            request: $request,
            to: $user->getPhone(),
            channel: OtpAttempt::CHANNEL_SMS,
            user: $user,
        );
    }

    /**
     * Send the login OTP through the chosen channel + map provider
     * errors. Shared so the response shape stays identical across
     * channels.
     */
    private function dispatch(
        ServerRequestInterface $request,
        string $to,
        string $channel,
        User $user,
    ): ResponseInterface {
        try {
            $verificationId = $this->otp->send(
                to: $to,
                purpose: OtpAttempt::PURPOSE_LOGIN_2FA,
                channel: $channel,
                user: $user,
                requestedIp: $this->extractIp($request),
            );
        } catch (OtpProviderException) {
            throw HttpException::upstreamFailure(
                ErrorCodes::OTP_PROVIDER_ERROR,
                'Could not send login code. Please try again in a moment.',
            );
        }

        return $this->ok([
            'verification_id' => $verificationId,
        ]);
    }

    private function fakeVid(): ResponseInterface
    {
        return $this->ok([
            'verification_id' => 'fake-' . bin2hex(random_bytes(12)),
        ]);
    }
}
