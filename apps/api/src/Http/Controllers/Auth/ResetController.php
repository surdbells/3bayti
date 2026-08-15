<?php

declare(strict_types=1);

namespace Bayti\Api\Http\Controllers\Auth;

use Bayti\Api\Domain\User\OtpAttempt;
use Bayti\Api\Domain\User\OtpService;
use Bayti\Api\Domain\User\User;
use Bayti\Api\Domain\User\UserRepository;
use Bayti\Api\Http\Controllers\Auth\Dto\ResetInput;
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
 * POST /v3/auth/reset
 *
 * Initiates password reset. Looks up the user by email, sends an
 * OTP to their REGISTERED phone (not user-supplied), returns a
 * verification_id for the next step.
 *
 * Response (always 200, regardless of whether email is registered)
 * ----------------------------------------------------------------
 *   { "verification_id": "..." }
 *
 * Anti-enumeration: identical response shape whether email exists
 * or not. The fake-vid path uses a 'fake-' prefix; an attacker
 * COULD detect this prefix post-hoc, but our anti-enumeration goal
 * is to make BULK probing expensive, not impossible. The /confirm
 * step is rate-limited at MessageCentral's side, so probing whether
 * a vid is fake one-at-a-time costs ~5 attempts before being
 * blocked anyway.
 *
 * Edge cases
 * ----------
 *   - User exists, is_active=false → fake vid (deactivated accounts
 *     can't reset; would be confusing to reveal that state)
 *   - User exists, is_phone_verified=false → fake vid (registration
 *     not completed; reset doesn't make sense)
 *
 * Why not 200 with no body
 * ------------------------
 * We need to return SOME verification_id to the frontend so it
 * can drive the /reset/confirm flow. An empty response would force
 * the frontend to ask for the vid in a separate step, awkward UX.
 * Returning a fake vid that just won't validate is the cleanest
 * way to keep one consistent response shape.
 */
final class ResetController
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
        $input = $this->validator->parse($request, ResetInput::class);

        // channel=email → email-OTP to the supplied email.
        // channel=phone → SMS-OTP to the registered phone (looked up by phone).
        // channel=sms (DEFAULT, back-compat) → look up by email, SMS to
        //   the user's registered phone. This is exactly the legacy
        //   email-only flow, so existing mobile clients that post just
        //   { email } keep working.
        if ($input->channel === 'email') {
            return $this->resetViaEmail($request, $input->email);
        }

        return $this->resetViaSms($request, $input->channel, $input->email, $input->phone);
    }

    /**
     * Email channel: look up by email, send an email-OTP to that email.
     */
    private function resetViaEmail(ServerRequestInterface $request, ?string $email): ResponseInterface
    {
        /** @var UserRepository $users */
        $users = $this->em->getRepository(User::class);
        $user = $email !== null ? $users->findByEmail($email) : null;

        // Anti-enumeration: fake vid for unknown / inactive / unverified.
        // We also require the email to be present + the user's email be
        // verified is NOT required (a half-verified phone-first account
        // can still reset via email it owns) — but the account must be
        // active and have completed registration (phone verified).
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
     * SMS channel (including the back-compat default): resolve the user
     * by phone (channel=phone) or by email (default), then SMS their
     * registered phone.
     */
    private function resetViaSms(
        ServerRequestInterface $request,
        string $channel,
        ?string $email,
        ?string $phone,
    ): ResponseInterface {
        /** @var UserRepository $users */
        $users = $this->em->getRepository(User::class);

        $user = null;
        if ($channel === 'phone' && $phone !== null) {
            $user = $users->findByPhone($phone);
        } elseif ($email !== null) {
            // Default 'sms' channel: legacy email-first lookup.
            $user = $users->findByEmail($email);
        }

        if ($user === null || !$user->isActive() || !$user->isPhoneVerified() || $user->getPhone() === null) {
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
     * Send the reset OTP through the chosen channel + map provider
     * errors. Shared by both channels so the response shape stays
     * identical.
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
                purpose: OtpAttempt::PURPOSE_PASSWORD_RESET,
                channel: $channel,
                user: $user,
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

    private function fakeVid(): ResponseInterface
    {
        return $this->ok([
            'verification_id' => 'fake-' . bin2hex(random_bytes(12)),
        ]);
    }
}
