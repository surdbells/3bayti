<?php

declare(strict_types=1);

namespace Bayti\Api\Http\Controllers\Following;

use Bayti\Api\Domain\Catalog\Vendor;
use Bayti\Api\Domain\Following\VendorFollow;
use Bayti\Api\Domain\Following\VendorFollowRepository;
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
 * DELETE /v3/me/following/{vendorId} — unfollow a vendor.
 *
 * Idempotent: returns 204 whether or not the user was actually
 * following. "Make me not follow this vendor" is the goal; if it's
 * already so, the goal is met. (Mirrors the idempotent-POST posture and
 * doesn't leak vendor existence either.)
 */
final class UnfollowVendorController
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
    public function __invoke(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $user = $request->getAttribute(AuthMiddleware::ATTR_USER);
        if (!$user instanceof User) {
            throw HttpException::unauthorized(
                ErrorCodes::AUTH_INVALID_TOKEN,
                'Authentication required.',
            );
        }

        $vendorId = (int) ($args['vendorId'] ?? 0);
        $vendor = $vendorId > 0 ? $this->em->find(Vendor::class, $vendorId) : null;
        if ($vendor instanceof Vendor) {
            /** @var VendorFollowRepository $repo */
            $repo = $this->em->getRepository(VendorFollow::class);
            $existing = $repo->findOneForUserAndVendor($user, $vendor);
            if ($existing !== null) {
                $repo->remove($existing);
            }
        }

        return $this->noContent();
    }
}
