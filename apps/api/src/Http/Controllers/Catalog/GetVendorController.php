<?php

declare(strict_types=1);

namespace Bayti\Api\Http\Controllers\Catalog;

use Bayti\Api\Domain\Catalog\Vendor;
use Bayti\Api\Domain\Catalog\VendorRepository;
use Bayti\Api\Domain\Following\VendorFollow;
use Bayti\Api\Domain\Following\VendorFollowRepository;
use Bayti\Api\Domain\User\User;
use Bayti\Api\Http\Errors\HttpException;
use Bayti\Api\Http\Middleware\AuthMiddleware;
use Bayti\Api\Http\PaginatedEnvelope;
use Bayti\Api\Http\Responder;
use Bayti\Api\Http\Serializers\VendorSerializer;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * GET /v3/vendors/{slug}
 */
final class GetVendorController
{
    use Responder;

    public function __construct(
        protected readonly ResponseFactoryInterface $responseFactory,
        private readonly EntityManagerInterface $em,
        private readonly VendorSerializer $serializer,
    ) {
    }

    protected function getResponseFactory(): ResponseFactoryInterface
    {
        return $this->responseFactory;
    }

    /**
     * @param array<string, string> $args
     */
    public function __invoke(
        ServerRequestInterface $request,
        ResponseInterface $response,
        array $args,
    ): ResponseInterface {
        $slug = (string) ($args['slug'] ?? '');
        if ($slug === '') {
            throw HttpException::notFound('Vendor not found.');
        }

        /** @var VendorRepository $repo */
        $repo = $this->em->getRepository(Vendor::class);
        $vendor = $repo->findBySlug($slug);
        if ($vendor === null || !$vendor->isActive() || !$vendor->isApproved()) {
            throw HttpException::notFound('Vendor not found.');
        }

        // OptionalAuth: resolve the viewer's follow state so the storefront can
        // render the Follow/Following button. Anonymous reads pass null, which
        // omits is_following and keeps the shape cacheable.
        $viewer = $request->getAttribute(AuthMiddleware::ATTR_USER);
        $isFollowing = null;
        if ($viewer instanceof User) {
            /** @var VendorFollowRepository $followRepo */
            $followRepo = $this->em->getRepository(VendorFollow::class);
            $isFollowing = $followRepo->isFollowing($viewer, $vendor);
        }

        return $this->ok(PaginatedEnvelope::single(
            $this->serializer->publicShape($vendor, $isFollowing),
        ));
    }
}
