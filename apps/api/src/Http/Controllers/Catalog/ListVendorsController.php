<?php

declare(strict_types=1);

namespace Bayti\Api\Http\Controllers\Catalog;

use Bayti\Api\Domain\Catalog\Vendor;
use Bayti\Api\Domain\Catalog\VendorRepository;
use Bayti\Api\Http\PaginatedEnvelope;
use Bayti\Api\Http\Responder;
use Bayti\Api\Http\Serializers\VendorSerializer;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * GET /v3/vendors  (public)
 *
 * Active vendors only, publicShape (no contact info, no commission).
 * Returns envelope { data, meta } matching v2 contract.
 *
 * Pagination: M2.1.A shipped this as a flat list. Day 2 wraps in
 * envelope BUT keeps emitting all results (no actual pagination yet).
 * Pagination will be added when vendor count grows beyond ~hundreds.
 * For now, total = count = result length, has_more = false always.
 */
final class ListVendorsController
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

    public function __invoke(ServerRequestInterface $request): ResponseInterface
    {
        /** @var VendorRepository $repo */
        $repo = $this->em->getRepository(Vendor::class);
        $vendors = $repo->findActive();
        $items = $this->serializer->publicShapeMany($vendors);

        return $this->ok(PaginatedEnvelope::build(
            $items,
            total: count($items),
            limit: count($items),
            offset: 0,
        ));
    }
}
