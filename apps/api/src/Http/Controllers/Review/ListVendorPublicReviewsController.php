<?php

declare(strict_types=1);

namespace Bayti\Api\Http\Controllers\Review;

use Bayti\Api\Domain\Catalog\ProductReview;
use Bayti\Api\Domain\Catalog\ProductReviewRepository;
use Bayti\Api\Domain\Catalog\Vendor;
use Bayti\Api\Domain\Catalog\VendorRepository;
use Bayti\Api\Http\Errors\HttpException;
use Bayti\Api\Http\Responder;
use Bayti\Api\Http\Serializers\ReviewSerializer;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * GET /v3/vendors/{vendorId}/reviews[?limit=&offset=], public
 * (approved) reviews across all of a vendor's products PLUS store-level
 * (product IS NULL) reviews, newest first. No auth; pending/rejected/spam
 * stay hidden.
 *
 * The {vendorId} path arg is resolved FLEXIBLY so every caller works on the
 * one route:
 *   - all-digits  -> em->find(Vendor, n); if null, VendorRepository::findByLegacyId(n)
 *   - non-numeric -> VendorRepository::findBySlug (active vendors only)
 *
 * This single resolution order fixes BOTH mobile (sends the LEGACY store id,
 * which only findByLegacyId matches) AND web (sends the vendor slug), while
 * keeping the original v3-PK behaviour. Migrated reviews are vendor-scoped
 * (product_id NULL, status 'approved') and are returned by
 * ProductReviewRepository::findApprovedForVendorPaginated.
 */
final class ListVendorPublicReviewsController
{
    use Responder;

    public function __construct(
        protected readonly ResponseFactoryInterface $responseFactory,
        private readonly EntityManagerInterface $em,
        private readonly ReviewSerializer $serializer,
    ) {
    }

    protected function getResponseFactory(): ResponseFactoryInterface
    {
        return $this->responseFactory;
    }

    /**
     * @param array<string, string> $args
     */
    public function __invoke(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $vendor = $this->resolveVendor((string) ($args['vendorId'] ?? ''));
        if (!$vendor instanceof Vendor) {
            throw HttpException::notFound('Vendor not found.');
        }

        $q      = $request->getQueryParams();
        $limit  = max(1, min(100, (int) ($q['limit'] ?? 20)));
        $offset = max(0, (int) ($q['offset'] ?? 0));

        /** @var ProductReviewRepository $repo */
        $repo   = $this->em->getRepository(ProductReview::class);
        $result = $repo->findApprovedForVendorPaginated($vendor, $limit, $offset);

        return $this->ok([
            'data' => array_map([$this->serializer, 'publicShape'], $result['items']),
            'meta' => ['total' => $result['total'], 'limit' => $limit, 'offset' => $offset],
        ]);
    }

    /**
     * Resolve the {vendorId} path arg flexibly.
     *
     * Resolution order:
     *   1. all-digits -> em->find(Vendor, n) (the v3 primary key)
     *   2. all-digits, still unresolved -> VendorRepository::findByLegacyId(n)
     *      (mobile sends the LEGACY store id)
     *   3. non-numeric -> VendorRepository::findBySlug (web sends the slug);
     *      inactive vendors are treated as nonexistent for slug callers,
     *      matching the other public slug controllers.
     */
    private function resolveVendor(string $raw): ?Vendor
    {
        $raw = trim($raw);
        if ($raw === '') {
            return null;
        }

        if (ctype_digit($raw)) {
            $id = (int) $raw;
            $vendor = $this->em->find(Vendor::class, $id);
            if ($vendor instanceof Vendor) {
                return $vendor;
            }

            /** @var VendorRepository $vendorRepo */
            $vendorRepo = $this->em->getRepository(Vendor::class);
            return $vendorRepo->findByLegacyId($id);
        }

        /** @var VendorRepository $vendorRepo */
        $vendorRepo = $this->em->getRepository(Vendor::class);
        $vendor = $vendorRepo->findBySlug($raw);
        if ($vendor === null || !$vendor->isActive()) {
            return null;
        }

        return $vendor;
    }
}
