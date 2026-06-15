<?php

declare(strict_types=1);

namespace Bayti\Api\Http\Controllers\Review;

use Bayti\Api\Domain\Catalog\ProductReview;
use Bayti\Api\Domain\Catalog\ProductReviewRepository;
use Bayti\Api\Domain\Catalog\Vendor;
use Bayti\Api\Http\Errors\HttpException;
use Bayti\Api\Http\Responder;
use Bayti\Api\Http\Serializers\ReviewSerializer;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * GET /v3/vendors/{vendorId}/reviews[?limit=&offset=] — public
 * (approved) reviews across all of a vendor's products, newest first.
 * No auth; pending/rejected/spam stay hidden.
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
        $vendorId = (int) ($args['vendorId'] ?? 0);
        $vendor   = $vendorId > 0 ? $this->em->find(Vendor::class, $vendorId) : null;
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
}
