<?php

declare(strict_types=1);

namespace Bayti\Api\Http\Controllers\Me;

use Bayti\Api\Domain\User\OtpAttempt;
use Bayti\Api\Domain\User\User;
use Bayti\Api\Domain\User\UserRepository;
use Bayti\Api\Http\Controllers\Me\Dto\SetPhoneInput;
use Bayti\Api\Http\Errors\ErrorCodes;
use Bayti\Api\Http\Errors\HttpException;
use Bayti\Api\Http\Middleware\AuthMiddleware;
use Bayti\Api\Http\RequestContext;
use Bayti\Api\Http\Responder;
use Bayti\Api\Http\Validator\RequestValidator;
use Bayti\Api\Domain\User\OtpService;
use Bayti\Api\Infrastructure\Otp\OtpProviderException;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * POST /v3/me/phone/claim  (AuthMiddleware)
 *
 * Start linking the current (typically social-only) account to an EXISTING
 * account that already owns the given phone number. This is the recovery path
 * for the phone-after-social wall: a Google/Apple sign-up whose real phone
 * belongs to their migrated account gets 409 CONFLICT_PHONE_TAKEN from
 * POST /me/phone; here they instead prove they control that number via OTP and
 * (on /claim/verify) absorb their social identity into the existing account.
 *
 * Only dispatches the OTP when the number maps to EXACTLY ONE other active
 * account:
 *   - 0 owners → 409 PHONE_LINK_NO_ACCOUNT (nothing to link; use /me/phone).
 *   - >1 owner  → 409 PHONE_LINK_AMBIGUOUS (legacy shared number; the OTP would
 *     prove control of the number but not of a specific account → send to
 *     support rather than guess).
 *
 * OTP purpose is ACCOUNT_LINK (never PHONE_CHANGE) so a plain "add my phone"
 * code can't be replayed against the merge endpoint.
 *
 * Response: 200 { verification_id }.
 */
final class ClaimPhoneController
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
        $target = $this->resolveSingleTarget($users, $input->phone, $user);

        // Send the OTP to the number (owned by $target), bound to the CURRENT
        // caller so /claim/verify can confirm it minted this attempt. The
        // destination is the phone string; the target is re-resolved on verify.
        try {
            $verificationId = $this->otp->send(
                to: $input->phone,
                purpose: OtpAttempt::PURPOSE_ACCOUNT_LINK,
                channel: OtpAttempt::CHANNEL_SMS,
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

    /**
     * Resolve the phone to exactly one other active account, or throw the
     * appropriate 409. Shared by claim + claim/verify so the rule is identical.
     */
    public static function resolveSingleTarget(UserRepository $users, string $phone, User $current): User
    {
        $owners = array_values(array_filter(
            $users->findActiveOwnersByPhone($phone),
            static fn (User $u): bool => $u->getId() !== $current->getId(),
        ));

        if ($owners === []) {
            throw HttpException::conflict(
                ErrorCodes::PHONE_LINK_NO_ACCOUNT,
                'No existing account uses that phone number.',
            );
        }
        if (count($owners) > 1) {
            throw HttpException::conflict(
                ErrorCodes::PHONE_LINK_AMBIGUOUS,
                'That phone number is linked to more than one account. Please contact support.',
            );
        }

        return $owners[0];
    }
}
