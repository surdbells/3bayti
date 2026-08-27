<?php

declare(strict_types=1);

namespace Bayti\Api\Http\Controllers\Catalog;

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
 * GET /v3/vendors/by-legacy-id/{id}/labels
 *
 * Mobile-compatibility shim during the M3.1.5.5 strangler-fig flip -
 * same response as ListVendorLabelsController (the slug variant) but
 * the vendor is resolved via legacy_vendor_id.
 *
 * Backs the mobile store_labels endpoint (legacy URL:
 * customer/read_vendor_collection).
 *
 * Behaviour:
 *   - 404 for non-numeric id, unknown legacy id, inactive vendor
 *   - Returns the same shape as the slug variant
 *
 * Retired with the rest of the by-legacy-id controllers once mobile
 * rebuilds against slug semantics (M3.1.10+).
 */
final class ListVendorLabelsByLegacyIdController
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

        /** @var VendorLabelRepository $labelRepo */
        $labelRepo = $this->em->getRepository(VendorLabel::class);
        $labels = $labelRepo->listActiveByVendor($vendor);

        return $this->ok(PaginatedEnvelope::build(
            $this->serializer->publicShapeMany($labels),
            count($labels),
            count($labels),
            0,
        ));
    }
}
