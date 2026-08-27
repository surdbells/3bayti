<?php

declare(strict_types=1);

namespace Bayti\Api\Http\Controllers\Catalog;

use Bayti\Api\Domain\Catalog\Product;
use Bayti\Api\Domain\Catalog\ProductRepository;
use Bayti\Api\Domain\Catalog\Vendor;
use Bayti\Api\Domain\Catalog\VendorLabel;
use Bayti\Api\Domain\Catalog\VendorLabelRepository;
use Bayti\Api\Domain\Catalog\VendorRepository;
use Bayti\Api\Http\Errors\HttpException;
use Bayti\Api\Http\PaginatedEnvelope;
use Bayti\Api\Http\Responder;
use Bayti\Api\Http\Serializers\VendorLabelSerializer;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * GET /v3/vendors/{slug}/labels
 *
 * Lists all active labels (merchandising collections) curated by a
 * vendor. Storefront UX uses these as filter chips above the
 * product strip: tap "Eid Collection" to filter products to that
 * label.
 *
 * No pagination, vendors typically have < 20 labels. Returning all
 * at once simplifies the storefront UI. If a vendor accumulates
 * many labels (>50), pagination becomes a follow-up.
 *
 * Behaviour:
 *   - 404 for unknown vendor slug OR inactive vendors
 *   - Returns {data: VendorLabel[], meta: {...}}, meta is empty
 *     pagination shape (count() === total)
 *   - Inactive labels filtered out at the repository level
 *   - Ordered by display_order (NULLS LAST), then name
 *
 * Companion to ListVendorLabelsByLegacyIdController (M3.1.5.5e).
 */
final class ListVendorLabelsController
{
    use Responder;

    public function __construct(
        protected readonly ResponseFactoryInterface $responseFactory,
        private readonly EntityManagerInterface $em,
        private readonly VendorLabelSerializer $serializer,
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

        /** @var VendorRepository $vendorRepo */
        $vendorRepo = $this->em->getRepository(Vendor::class);
        $vendor = $vendorRepo->findBySlug($slug);
        if ($vendor === null || !$vendor->isActive()) {
            throw HttpException::notFound('Vendor not found.');
        }

        /** @var VendorLabelRepository $labelRepo */
        $labelRepo = $this->em->getRepository(VendorLabel::class);
        $labels = $labelRepo->listActiveByVendor($vendor);

        // Active-product count per label for the storefront chip badge (one
        // grouped query for the whole storefront, not per-label).
        /** @var ProductRepository $productRepo */
        $productRepo = $this->em->getRepository(Product::class);
        $counts = $productRepo->countActiveByLabelForVendor($vendor->getId() ?? 0);

        // No pagination, total === count.
        return $this->ok(PaginatedEnvelope::build(
            $this->serializer->publicShapeMany($labels, $counts),
            count($labels),
            count($labels), // limit
            0,              // offset
        ));
    }
}
