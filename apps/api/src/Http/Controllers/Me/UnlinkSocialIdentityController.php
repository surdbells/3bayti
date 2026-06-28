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
 * DELETE /v3/me/social-identities/{provider}  (AuthMiddleware)
 *
 * Unlink the current user's Google or Apple account.
 *
 * Last-method guard
 * -----------------
 * We refuse (422) to remove the identity if doing so would leave the
 * user with NO way to sign in — i.e. they have no password
 * (User::hasPassword() === false) AND no other social identity would
 * remain. Otherwise they'd lock themselves out permanently.
 *
 * 404 if the user has no identity for that provider.
 */
final class UnlinkSocialIdentityController
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

    /**
     * @param array<string, string> $args
     */
    public function __invoke(ServerRequestInterface $request, ResponseInterface $_response, array $args): ResponseInterface
    {
        $user = $request->getAttribute(AuthMiddleware::ATTR_USER);
        if (!$user instanceof User) {
            throw HttpException::unauthorized(
                ErrorCodes::AUTH_INVALID_TOKEN,
                'Authentication required.',
            );
        }

        $provider = strtolower(trim($args['provider'] ?? ''));
        if (!in_array($provider, SocialIdentity::ALL_PROVIDERS, true)) {
            throw HttpException::notFound('No linked account for that provider.');
        }

        /** @var SocialIdentityRepository $identities */
        $identities = $this->em->getRepository(SocialIdentity::class);

        $all = $identities->findAllForUser($user);

        $target = null;
        foreach ($all as $identity) {
            if ($identity->getProvider() === $provider) {
                $target = $identity;
                break;
            }
        }

        if ($target === null) {
            throw HttpException::notFound('No linked account for that provider.');
        }

        // Last-method guard: removing this identity must not strand the
        // user. They're safe if they have a password OR at least one
        // OTHER social identity survives.
        $otherSocialRemains = count($all) > 1;
        if (!$user->hasPassword() && !$otherSocialRemains) {
            throw HttpException::businessRuleViolation(
                ErrorCodes::BUSINESS_RULE_VIOLATION,
                'You cannot remove your only sign-in method. Set a password or link another account first.',
            );
        }

        $this->em->remove($target);
        $this->em->flush();

        return $this->noContent();
    }
}
