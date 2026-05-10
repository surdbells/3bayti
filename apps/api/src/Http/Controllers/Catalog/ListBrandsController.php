<?php

declare(strict_types=1);

namespace Bayti\Api\Http\Controllers\Catalog;

use Bayti\Api\Domain\Catalog\Brand;
use Bayti\Api\Domain\Catalog\BrandRepository;
use Bayti\Api\Http\Responder;
use Bayti\Api\Http\Serializers\BrandSerializer;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * GET /v3/brands  (public)
 *
 * Lists active brands only. Used by storefront filter UI ("brand: ____").
 * Uses publicShape — no lifecycle fields exposed.
 *
 * No pagination yet. Brands are bounded by definition (~hundreds even
 * at scale) and the response is fast to serialize. If the count grows
 * beyond a few hundred we'll add cursor pagination.
 */
final class ListBrandsController
{
    use Responder;

    public function __construct(
        protected readonly ResponseFactoryInterface $responseFactory,
        private readonly EntityManagerInterface $em,
        private readonly BrandSerializer $serializer,
    ) {
    }

    protected function getResponseFactory(): ResponseFactoryInterface
    {
        return $this->responseFactory;
    }

    public function __invoke(ServerRequestInterface $request): ResponseInterface
    {
        /** @var BrandRepository $repo */
        $repo = $this->em->getRepository(Brand::class);
        $brands = $repo->findActive();

        return $this->ok([
            'brands' => $this->serializer->publicShapeMany($brands),
        ]);
    }
}
