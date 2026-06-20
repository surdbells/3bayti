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
 * GET /v3/vendors/by-legacy-id/{id}/size-chart
 *
 * PUBLIC customer-facing read of a store's published size guide, keyed by
 * the legacy WordPress/CodeIgniter vendor id. The mobile PDP's size-guide
 * sheet fetches this when a shopper opens it.
 *
 * Why this exists: the only other v3 size-chart endpoint
 * (GET /v3/vendor/measurements, VendorSizeChartController::list) is
 * vendor-self-scoped — it resolves the vendor from the caller's JWT and
 * 403s for non-vendors. A shopper viewing a store's size guide is neither
 * authed-as-that-vendor nor allowed to see any other store's chart through
 * that route, so this PUBLIC by-legacy-id variant fills the gap.
 *
 * Resolves the vendor exactly like the sibling by-legacy-id controllers
 * (VendorRepository::findByLegacyId + active check) and returns the same
 * row shape as VendorSizeChartController::list via the shared
 * VendorSizeChartController::shapeRow().
 *
 * Response: { data: [ ...shapedRows ], meta: { total } }
 *
 * Retired with the rest of the by-legacy-id controllers once mobile
 * rebuilds against slug semantics (M3.1.10+).
 */
final class StoreSizeChartByLegacyIdController
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

        /** @var VendorRepository $vendorRepo */
        $vendorRepo = $this->em->getRepository(Vendor::class);
        $vendor = $vendorRepo->findByLegacyId($legacyId);
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
