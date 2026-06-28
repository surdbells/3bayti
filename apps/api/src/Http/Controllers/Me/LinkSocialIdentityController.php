<?php

declare(strict_types=1);

namespace Bayti\Api\Http\Controllers\Me;

use Bayti\Api\Domain\User\SocialIdentity;
use Bayti\Api\Domain\User\SocialIdentityRepository;
use Bayti\Api\Domain\User\User;
use Bayti\Api\Http\Controllers\Me\Dto\LinkSocialIdentityInput;
use Bayti\Api\Http\Errors\ErrorCodes;
use Bayti\Api\Http\Errors\HttpException;
use Bayti\Api\Http\Middleware\AuthMiddleware;
use Bayti\Api\Http\Responder;
use Bayti\Api\Http\Validator\RequestValidator;
use Bayti\Api\Infrastructure\Auth\FirebaseIdTokenVerifier;
use Bayti\Api\Infrastructure\Auth\SocialTokenVerificationException;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * POST /v3/me/social-identities  (AuthMiddleware)
 *
 * Attach a Google/Apple account to the CURRENT user. The client signs in
 * with the provider afresh and posts the resulting Firebase ID token; we
 * verify it, then link (provider, provider_uid) to the authenticated
 * user.
 *
 * Conflict
 * --------
 * If that (provider, provider_uid) is ALREADY linked to a DIFFERENT
 * user, respond 409 — a provider account can belong to only one
 * platform account. If it's already linked to THIS user, it's a no-op
 * success (idempotent re-link).
 */
final class LinkSocialIdentityController
{
    use Responder;

    public function __construct(
        protected readonly ResponseFactoryInterface $responseFactory,
        private readonly RequestValidator $validator,
        private readonly EntityManagerInterface $em,
        private readonly FirebaseIdTokenVerifier $verifier,
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

        $input = $this->validator->parse($request, LinkSocialIdentityInput::class);

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

        $existing = $identities->findByProviderUid($verified->provider, $verified->providerUid);
        if ($existing !== null) {
            if ($existing->getUser()->getId() === $user->getId()) {
                // Already linked to this user — idempotent success.
                return $this->created([
                    'provider' => $existing->getProvider(),
                    'email' => $existing->getEmail(),
                    'created_at' => $existing->getCreatedAt()->format(\DateTimeInterface::ATOM),
                ]);
            }

            // Linked to a different account.
            throw HttpException::conflict(
                ErrorCodes::CONFLICT_DUPLICATE,
                'This account is already linked to another user.',
            );
        }

        $identity = new SocialIdentity(
            user: $user,
            provider: $verified->provider,
            providerUid: $verified->providerUid,
            email: $verified->email,
        );
        $identities->save($identity);

        return $this->created([
            'provider' => $identity->getProvider(),
            'email' => $identity->getEmail(),
            'created_at' => $identity->getCreatedAt()->format(\DateTimeInterface::ATOM),
        ]);
    }
}
