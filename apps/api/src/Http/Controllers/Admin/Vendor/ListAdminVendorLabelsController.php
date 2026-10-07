<?php

declare(strict_types=1);

namespace Bayti\Api\Http\Controllers\Admin\Vendor;

use Bayti\Api\Domain\Catalog\Vendor;
use Bayti\Api\Domain\Catalog\VendorLabel;
use Bayti\Api\Domain\Catalog\VendorLabelRepository;
use Bayti\Api\Domain\Catalog\VendorRepository;
use Bayti\Api\Http\Errors\HttpException;
use Bayti\Api\Http\Responder;
use Bayti\Api\Http\Serializers\VendorLabelSerializer;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * GET /v3/admin/vendors/{id}/labels
 *
 * A store's labels for the admin product form's label picker, keyed by the
 * v3 vendor id. The existing label reads don't fit the admin form:
 * GET /v3/vendor/labels is scoped to the CALLER's own store (an admin has
 * none), and the public GET /v3/vendors/{slug}/labels 404s inactive/pending
 * stores (which admins still manage) and returns the storefront shape.
 *
 * Mirrors GET /v3/vendor/labels exactly: active labels only (soft-deleted
 * ones are hidden), ordered by display_order (NULLS LAST) then name, in the
 * same management shape (VendorLabelSerializer::manageShape), so the portal
 * renders both with one code path. No vendor active/approved gate.
 *
 * Returns: { data: [{ id, label, name, slug, display_order, is_active }] }
 *
 * Authorization: admin group middleware + products.view (routes.php).
 */
final class ListAdminVendorLabelsController
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
        ResponseInterface $_response,
        array $args,
    ): ResponseInterface {
        $rawId = (string) ($args['id'] ?? '');
        if ($rawId === '' || !ctype_digit($rawId)) {
            throw HttpException::notFound('Vendor not found.');
        }

        /** @var VendorRepository $vendorRepo */
        $vendorRepo = $this->em->getRepository(Vendor::class);
        $vendor = $vendorRepo->find((int) $rawId);
        if ($vendor === null) {
            throw HttpException::notFound('Vendor not found.');
        }

        /** @var VendorLabelRepository $labelRepo */
        $labelRepo = $this->em->getRepository(VendorLabel::class);
        $labels = $labelRepo->listActiveByVendor($vendor);

        return $this->ok(['data' => $this->serializer->manageShapeMany($labels)]);
    }
}
