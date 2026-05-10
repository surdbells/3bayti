<?php

declare(strict_types=1);

namespace Bayti\Api\Http\Controllers\Admin\Brand;

use Bayti\Api\Domain\Catalog\Brand;
use Bayti\Api\Domain\Catalog\BrandRepository;
use Bayti\Api\Http\Responder;
use Bayti\Api\Http\Serializers\BrandSerializer;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * GET /v3/admin/brands
 *
 * Lists ALL brands (active + inactive) using the admin shape.
 * The public counterpart at GET /v3/brands shows only active.
 */
final class ListBrandsAdminController
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
        $brands = $repo->findAll();

        return $this->ok([
            'brands' => $this->serializer->adminShapeMany($brands),
        ]);
    }
}
