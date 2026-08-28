<?php

declare(strict_types=1);

namespace Bayti\Api\Http\Controllers\Me;

use Bayti\Api\Domain\User\OtpAttempt;
use Bayti\Api\Domain\User\OtpService;
use Bayti\Api\Domain\User\RefreshToken;
use Bayti\Api\Domain\User\RefreshTokenRepository;
use Bayti\Api\Domain\User\SocialIdentity;
use Bayti\Api\Domain\User\SocialIdentityRepository;
use Bayti\Api\Domain\User\User;
use Bayti\Api\Domain\User\UserRepository;
use Bayti\Api\Domain\User\VerifyResult;
use Bayti\Api\Http\Controllers\Me\Dto\VerifyPhoneInput;
use Bayti\Api\Http\Errors\ErrorCodes;
use Bayti\Api\Http\Errors\HttpException;
use Bayti\Api\Http\Middleware\AuthMiddleware;
use Bayti\Api\Http\RequestContext;
use Bayti\Api\Http\Responder;
use Bayti\Api\Http\Serializers\UserSerializer;
use Bayti\Api\Http\Validator\RequestValidator;
use Bayti\Api\Infrastructure\Auth\JwtService;
use Bayti\Api\Infrastructure\Otp\OtpProviderException;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * POST /v3/me/phone/claim/verify  (AuthMiddleware)
 *
 * Confirm the OTP from POST /me/phone/claim and MERGE the current (social-only)
 * account into the existing account that owns the phone. The OTP delivered to
 * that phone is the sole authorization: whoever receives it proves control of
 * the number, and therefore of the account, so the merge is irreversible.
 *
 * On success, inside one transaction:
 *   1. move the caller's social identity(ies) onto the target account,
 *   2. mark the target's phone verified (target keeps its own email + phone),
 *   3. revoke the caller's sessions and soft-delete the throwaway account,
 *   4. issue a fresh token pair for the TARGET account.
 *
 * Returns the LoginController-identical envelope (tokens + user) for the
 * TARGET account — the client swaps its session to it.
 *
 * All OTP/guard failures collapse to one 401 OTP_VERIFICATION_FAILED. If the
 * phone no longer maps to a single account (claimed/ambiguous between send and
 * verify) it surfaces PHONE_LINK_NO_ACCOUNT / PHONE_LINK_AMBIGUOUS.
 */
final class ClaimPhoneVerifyController
{
    use Responder;
    use RequestContext;

    public function __construct(
        protected readonly ResponseFactoryInterface $responseFactory,
        private readonly RequestValidator $validator,
        private readonly EntityManagerInterface $em,
        private readonly OtpService $otp,
        private readonly JwtService $jwt,
        private readonly UserSerializer $userSerializer,
    ) {
    }

    protected function getResponseFactory(): ResponseFactoryInterface
    {
        return $this->responseFactory;
    }

    public function __invoke(ServerRequestInterface $request): ResponseInterface
    {
        $source = $request->getAttribute(AuthMiddleware::ATTR_USER);
        if (!$source instanceof User) {
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
        // Cross-flow guard: must be an ACCOUNT_LINK OTP minted for THIS caller.
        if ($attempt->getPurpose() !== OtpAttempt::PURPOSE_ACCOUNT_LINK) {
            throw $this->verificationFailed();
        }
        $attemptUser = $attempt->getUser();
        if ($attemptUser === null || $attemptUser->getId() !== $source->getId()) {
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

        /** @var UserRepository $users */
        $users = $this->em->getRepository(User::class);
        // Re-resolve against the number that was actually OTP-verified — the
        // ownership could have changed between send and verify.
        $target = ClaimPhoneController::resolveSingleTarget($users, $attempt->getPhone(), $source);

        $pair = null;
        $this->em->wrapInTransaction(function () use ($source, $target, $request, &$pair): void {
            /** @var SocialIdentityRepository $identities */
            $identities = $this->em->getRepository(SocialIdentity::class);
            // (1) Move the social identity(ies) onto the surviving account.
            $identities->reassignToUser((int) $source->getId(), (int) $target->getId());

            // (2) The number is now proven — mark the target's phone verified.
            //     Target keeps its existing email + phone.
            $target->markPhoneVerified();

            // (3) Retire the throwaway account: kill its sessions + soft-delete.
            /** @var RefreshTokenRepository $refreshRepo */
            $refreshRepo = $this->em->getRepository(RefreshToken::class);
            $refreshRepo->revokeAllForUser($source, 'account_merged_into_' . $target->getId());
            $source->deactivate();
            $source->softDelete();

            // (4) Log the caller into the TARGET account.
            $pair = $this->jwt->issueTokenPair($target);
            $refreshRow = new RefreshToken(
                user: $target,
                jti: $pair->refreshTokenJti,
                tokenHash: $pair->refreshTokenHash(),
                expiresAt: $pair->refreshTokenExpiresAt,
                issuedIp: $this->extractIp($request),
                userAgent: $request->getHeaderLine('User-Agent') ?: null,
            );
            $refreshRepo->save($refreshRow, flush: false);
            $target->recordLogin($this->extractIp($request));

            $this->em->flush();
        });

        return $this->ok([
            'access_token' => $pair->accessToken,
            'access_token_expires_at' => $pair->accessTokenExpiresAt->format(\DateTimeInterface::ATOM),
            'refresh_token' => $pair->refreshToken,
            'refresh_token_expires_at' => $pair->refreshTokenExpiresAt->format(\DateTimeInterface::ATOM),
            'user' => $this->userSerializer->publicProfile($target),
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
