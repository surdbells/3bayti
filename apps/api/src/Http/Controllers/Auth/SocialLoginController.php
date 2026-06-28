<?php

declare(strict_types=1);

namespace Bayti\Api\Http\Controllers\Auth;

use Bayti\Api\Domain\User\RefreshToken;
use Bayti\Api\Domain\User\RefreshTokenRepository;
use Bayti\Api\Domain\User\SocialIdentity;
use Bayti\Api\Domain\User\SocialIdentityRepository;
use Bayti\Api\Domain\User\User;
use Bayti\Api\Domain\User\UserRepository;
use Bayti\Api\Http\Controllers\Auth\Dto\SocialLoginInput;
use Bayti\Api\Http\Errors\ErrorCodes;
use Bayti\Api\Http\Errors\HttpException;
use Bayti\Api\Http\RequestContext;
use Bayti\Api\Http\Responder;
use Bayti\Api\Http\Serializers\UserSerializer;
use Bayti\Api\Http\Validator\RequestValidator;
use Bayti\Api\Infrastructure\Auth\FirebaseIdTokenVerifier;
use Bayti\Api\Infrastructure\Auth\JwtService;
use Bayti\Api\Infrastructure\Auth\SocialTokenVerificationException;
use Bayti\Api\Infrastructure\Auth\VerifiedSocialIdentity;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * POST /v3/auth/social  (public)
 *
 * Google / Apple federated sign-in. The client signs in with the
 * Firebase SDK and posts the resulting ID token; we verify it and then
 * resolve a user via one of three branches:
 *
 *   (a) KNOWN IDENTITY — a SocialIdentity already exists for
 *       (provider, provider_uid). Log that identity's user in.
 *
 *   (b) AUTO-LINK — no identity row, but the token's email is verified
 *       AND matches an existing user. Link the new identity to that
 *       user and log them in. (Email is only trusted as a link key when
 *       the provider asserts email_verified; an unverified email could
 *       be attacker-controlled and would let one take over an account.)
 *
 *   (c) CREATE — otherwise, provision a brand-new social-only user
 *       (password_hash NULL, is_email_verified=true, is_phone_verified
 *       =false) and create the identity row.
 *
 * Response: EXACTLY the LoginController shape (access_token,
 * access_token_expires_at, refresh_token, refresh_token_expires_at,
 * user). The frontend treats social + password logins identically.
 *
 * Failure modes
 * -------------
 *   - Token fails verification → 401 AUTH_INVALID_TOKEN (uniform; no
 *     oracle leak about which check failed).
 *   - Resolved/created account is inactive → 401 AUTH_ACCOUNT_INACTIVE.
 */
final class SocialLoginController
{
    use Responder;
    use RequestContext;

    public function __construct(
        protected readonly ResponseFactoryInterface $responseFactory,
        private readonly RequestValidator $validator,
        private readonly EntityManagerInterface $em,
        private readonly JwtService $jwt,
        private readonly FirebaseIdTokenVerifier $verifier,
        private readonly UserSerializer $userSerializer,
    ) {
    }

    protected function getResponseFactory(): ResponseFactoryInterface
    {
        return $this->responseFactory;
    }

    public function __invoke(ServerRequestInterface $request): ResponseInterface
    {
        $input = $this->validator->parse($request, SocialLoginInput::class);

        try {
            $verified = $this->verifier->verify($input->id_token);
        } catch (SocialTokenVerificationException) {
            throw HttpException::unauthorized(
                ErrorCodes::AUTH_INVALID_TOKEN,
                'Could not verify your sign-in. Please try again.',
            );
        }

        /** @var SocialIdentityRepository $identities */
        $identities = $this->em->getRepository(SocialIdentity::class);
        /** @var UserRepository $users */
        $users = $this->em->getRepository(User::class);

        // (a) Known identity → its user.
        $identity = $identities->findByProviderUid($verified->provider, $verified->providerUid);
        if ($identity !== null) {
            $user = $identity->getUser();
            return $this->issueFor($user, $request);
        }

        // (b) Auto-link by verified email.
        $existing = null;
        if ($verified->emailVerified && $verified->email !== null) {
            $existing = $users->findByEmail($verified->email);
        }

        if ($existing !== null) {
            $this->guardActive($existing);
            $this->createIdentity($existing, $verified);
            return $this->issueFor($existing, $request);
        }

        // (c) Create a new social-only user + its identity.
        $user = $this->createUser($verified, $input);
        $this->createIdentity($user, $verified);

        return $this->issueFor($user, $request);
    }

    /**
     * Provision a new social-only user. password_hash is NULL (no
     * password — only social sign-in). Email is verified (the provider
     * asserted it, or it's a private-relay Apple email we still trust as
     * the account's own). Phone is unverified — the user can add one
     * later via POST /v3/me/phone.
     */
    private function createUser(VerifiedSocialIdentity $verified, SocialLoginInput $input): User
    {
        // Email: prefer the verified token email. Apple private-relay /
        // no-email logins fall back to a stable, unique placeholder
        // derived from the provider uid so the NOT NULL UNIQUE email
        // column is satisfied without colliding.
        $email = $verified->email ?? sprintf(
            '%s_%s@social.3bayti.invalid',
            $verified->provider,
            substr(hash('sha256', $verified->providerUid), 0, 24),
        );

        $user = new User(
            email: $email,
            phone: null,
            passwordHash: null,
            countryCode: 'AE',
        );

        // Name: client-supplied hints win when present (the user just
        // typed them), else split the provider-asserted display name.
        [$first, $last] = $this->resolveName($verified, $input);
        $user->setName($first, $last);

        // Provider asserted the email → treat it as verified for the
        // account. Phone stays unverified (no phone yet).
        $user->markEmailVerified();

        /** @var UserRepository $users */
        $users = $this->em->getRepository(User::class);
        try {
            $users->save($user);
        } catch (UniqueConstraintViolationException $e) {
            // Extremely rare race: two concurrent first-time social
            // logins for the same email. Re-resolve and link instead of
            // failing the user.
            $this->em->clear();
            $winner = $users->findByEmail($email);
            if ($winner !== null) {
                return $winner;
            }
            throw HttpException::conflict(
                ErrorCodes::CONFLICT_DUPLICATE,
                'Could not complete sign-in. Please try again.',
            );
        }

        return $user;
    }

    /**
     * @return array{0: ?string, 1: ?string}
     */
    private function resolveName(VerifiedSocialIdentity $verified, SocialLoginInput $input): array
    {
        if ($input->first_name !== null && $input->first_name !== '') {
            return [$input->first_name, $input->last_name];
        }

        if ($verified->name !== null) {
            $parts = preg_split('/\s+/', trim($verified->name), 2) ?: [];
            $first = $parts[0] ?? null;
            $last = $parts[1] ?? null;
            return [$first, $last];
        }

        return [null, null];
    }

    /**
     * Persist a SocialIdentity row linking $user to the verified
     * provider account. Defends against the unique-constraint race
     * (someone linked the same identity concurrently) by treating a
     * violation as "already linked — proceed".
     */
    private function createIdentity(User $user, VerifiedSocialIdentity $verified): void
    {
        $identity = new SocialIdentity(
            user: $user,
            provider: $verified->provider,
            providerUid: $verified->providerUid,
            email: $verified->email,
        );

        try {
            /** @var SocialIdentityRepository $identities */
            $identities = $this->em->getRepository(SocialIdentity::class);
            $identities->save($identity);
        } catch (UniqueConstraintViolationException) {
            // Concurrent link of the same (provider, provider_uid).
            // Harmless — the row exists; carry on issuing tokens.
        }
    }

    private function guardActive(User $user): void
    {
        if (!$user->isActive()) {
            throw HttpException::unauthorized(
                ErrorCodes::AUTH_ACCOUNT_INACTIVE,
                'This account has been disabled.',
            );
        }
    }

    /**
     * Issue the token pair + persist the refresh row + record login,
     * then return the LoginController-identical response envelope.
     */
    private function issueFor(User $user, ServerRequestInterface $request): ResponseInterface
    {
        $this->guardActive($user);

        $pair = $this->jwt->issueTokenPair($user);

        $this->em->wrapInTransaction(function () use ($user, $pair, $request): void {
            /** @var RefreshTokenRepository $refreshRepo */
            $refreshRepo = $this->em->getRepository(RefreshToken::class);
            $refreshRow = new RefreshToken(
                user: $user,
                jti: $pair->refreshTokenJti,
                tokenHash: $pair->refreshTokenHash(),
                expiresAt: $pair->refreshTokenExpiresAt,
                issuedIp: $this->extractIp($request),
                userAgent: $request->getHeaderLine('User-Agent') ?: null,
            );
            $refreshRepo->save($refreshRow, flush: false);

            $user->recordLogin($this->extractIp($request));

            $this->em->flush();
        });

        return $this->ok([
            'access_token' => $pair->accessToken,
            'access_token_expires_at' => $pair->accessTokenExpiresAt->format(\DateTimeInterface::ATOM),
            'refresh_token' => $pair->refreshToken,
            'refresh_token_expires_at' => $pair->refreshTokenExpiresAt->format(\DateTimeInterface::ATOM),
            'user' => $this->userSerializer->publicProfile($user),
        ]);
    }
}
