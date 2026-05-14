<?php

declare(strict_types=1);

namespace Bayti\Api\Http\Controllers\Catalog;

use Bayti\Api\Domain\Catalog\Vendor;
use Bayti\Api\Domain\Catalog\VendorRepository;
use Bayti\Api\Http\Errors\HttpException;
use Bayti\Api\Http\PaginatedEnvelope;
use Bayti\Api\Http\Responder;
use Bayti\Api\Http\Serializers\VendorSerializer;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * GET /v3/vendors/by-legacy-id/{id}
 *
 * Mobile-compatibility shim during the M3.1.5 strangler-fig flip.
 * See GetProductByLegacyIdController for full rationale — same
 * pattern: mobile sends a legacy integer id (here as `store_id`),
 * we resolve it via VendorRepository::findByLegacyId and return the
 * same response shape as the slug-based controller.
 *
 * Retired with the rest of the by-legacy-id controllers once mobile
 * rebuilds against slug semantics (M3.1.10+).
 */
final class GetVendorByLegacyIdController
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

    public function __invoke(
        ServerRequestInterface $request,
        ResponseInterface $response,
        array $args,
    ): ResponseInterface {
        $rawId = (string) ($args['id'] ?? '');
        if ($rawId === '' || !ctype_digit($rawId)) {
            throw HttpException::notFound('Vendor not found.');
        }
        $legacyId = (int) $rawId;

        /** @var VendorRepository $repo */
        $repo = $this->em->getRepository(Vendor::class);
        $vendor = $repo->findByLegacyId($legacyId);
        if ($vendor === null || !$vendor->isActive()) {
            throw HttpException::notFound('Vendor not found.');
        }

        return $this->ok(PaginatedEnvelope::single(
            $this->serializer->publicShape($vendor),
        ));
    }
}
