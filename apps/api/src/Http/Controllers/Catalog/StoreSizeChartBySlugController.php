<?php

declare(strict_types=1);

namespace Bayti\Api\Http\Controllers\Catalog;

use Bayti\Api\Domain\Catalog\Vendor;
use Bayti\Api\Domain\Catalog\VendorRepository;
use Bayti\Api\Domain\Catalog\VendorSizeChart;
use Bayti\Api\Domain\Catalog\VendorSizeChartRepository;
use Bayti\Api\Http\Controllers\Vendor\VendorSizeChartController;
use Bayti\Api\Http\Errors\HttpException;
use Bayti\Api\Http\Responder;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * GET /v3/vendors/{slug}/size-chart
 *
 * PUBLIC customer-facing read of a store's published size guide, keyed by
 * the vendor's slug. The apps/web storefront size-guide consumes this; it
 * is the slug-native sibling of StoreSizeChartByLegacyIdController (which
 * mobile hits by legacy id).
 *
 * Resolves the vendor by slug (active vendors only, like the other public
 * slug controllers GetVendorController / ListVendorProductsController) and
 * returns the SAME row shape as the vendor-self-scoped
 * GET /v3/vendor/measurements via the shared
 * VendorSizeChartController::shapeRow() + VendorSizeChartRepository::findForVendor.
 *
 * Response: { data: [ {id, size, values:{...}, created_at, updated_at} ], meta: { total } }
 *   - 404 if the slug is unknown or the vendor is inactive.
 *   - 200 with an empty data array when the vendor has no chart rows.
 */
final class StoreSizeChartBySlugController
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
    public function __invoke(
        ServerRequestInterface $request,
        ResponseInterface $response,
        array $args,
    ): ResponseInterface {
        $slug = trim((string) ($args['slug'] ?? ''));
        if ($slug === '') {
            throw HttpException::notFound('Vendor not found.');
        }

        /** @var VendorRepository $vendorRepo */
        $vendorRepo = $this->em->getRepository(Vendor::class);
        $vendor = $vendorRepo->findBySlug($slug);
        if ($vendor === null || !$vendor->isActive()) {
            throw HttpException::notFound('Vendor not found.');
        }

        /** @var VendorSizeChartRepository $repo */
        $repo = $this->em->getRepository(VendorSizeChart::class);
        $rows = $repo->findForVendor((int) $vendor->getId());

        return $this->ok([
            'data' => array_map(
                [VendorSizeChartController::class, 'shapeRow'],
                $rows,
            ),
            'meta' => ['total' => count($rows)],
        ]);
    }
}
