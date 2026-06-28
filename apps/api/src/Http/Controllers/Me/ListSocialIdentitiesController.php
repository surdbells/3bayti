<?php

declare(strict_types=1);

namespace Bayti\Api\Http\Controllers\Me;

use Bayti\Api\Domain\User\SocialIdentity;
use Bayti\Api\Domain\User\SocialIdentityRepository;
use Bayti\Api\Domain\User\User;
use Bayti\Api\Http\Errors\ErrorCodes;
use Bayti\Api\Http\Errors\HttpException;
use Bayti\Api\Http\Middleware\AuthMiddleware;
use Bayti\Api\Http\Responder;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * GET /v3/me/social-identities  (AuthMiddleware)
 *
 * List the current user's connected Google/Apple accounts. Backs the
 * "connected accounts" section of account settings.
 *
 * Each entry: { provider, email, created_at }. Own-data read — no audit.
 */
final class ListSocialIdentitiesController
{
    use Responder;

    public function __construct(
        protected readonly ResponseFactoryInterface $responseFactory,
        private readonly EntityManagerInterface $em,
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

        /** @var SocialIdentityRepository $identities */
        $identities = $this->em->getRepository(SocialIdentity::class);

        $data = array_map(
            static fn (SocialIdentity $i): array => [
                'provider' => $i->getProvider(),
                'email' => $i->getEmail(),
                'created_at' => $i->getCreatedAt()->format(\DateTimeInterface::ATOM),
            ],
            $identities->findAllForUser($user),
        );

        return $this->ok(['data' => $data]);
    }
}
