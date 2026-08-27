<?php

declare(strict_types=1);

namespace Bayti\Api\Http\Controllers\Vendor\Order;

use Bayti\Api\Domain\Catalog\Vendor;
use Bayti\Api\Domain\Catalog\VendorRepository;
use Bayti\Api\Domain\Order\OrderReturnRequest;
use Bayti\Api\Domain\Order\OrderReturnRequestRepository;
use Bayti\Api\Domain\User\User;
use Bayti\Api\Http\Errors\ErrorCodes;
use Bayti\Api\Http\Errors\HttpException;
use Bayti\Api\Http\Middleware\AuthMiddleware;
use Bayti\Api\Http\Responder;
use Bayti\Api\Http\Serializers\ReturnRequestSerializer;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * GET /v3/vendor/returns/{id}
 *
 * Returns the vendor-shape detail of a single return request. The
 * shape filters items to those belonging to the calling vendor only.
 *
 * Authorization:
 *   - VendorAuthMiddleware enforces "approved vendor user" upstream
 *   - This controller resolves the user's vendor IDs and confirms
 *     at least one item in the return belongs to one of them.
 *     Returns where no items belong to the user's vendors → 404
 *     (existence-leak prevention).
 *
 * Picking the displayed vendor_id
 * ================================
 * For multi-vendor users where multiple of their vendors have items
 * in the same return: we filter using the FIRST matching vendor id
 * (deterministic, smallest id wins). The UI can supply ?vendor_id
 * to select explicitly; we honor it if the user owns that vendor
 * AND that vendor has items in the return.
 */
final class GetVendorReturnController
{
    use Responder;

    public function __construct(
        protected readonly ResponseFactoryInterface $responseFactory,
        private readonly EntityManagerInterface $em,
        private readonly ReturnRequestSerializer $serializer,
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
        $user = $request->getAttribute(AuthMiddleware::ATTR_USER);
        if (!$user instanceof User) {
            throw HttpException::unauthorized(
                ErrorCodes::AUTH_INVALID_TOKEN,
                'Authentication required.',
            );
        }

        $returnId = (int) ($args['id'] ?? 0);
        if ($returnId <= 0) {
            throw HttpException::notFound('Return request not found.');
        }

        /** @var VendorRepository $vendorRepo */
        $vendorRepo = $this->em->getRepository(Vendor::class);
        $userVendorIds = $vendorRepo->findIdsByOwnerUser($user);
        if ($userVendorIds === []) {
            throw HttpException::notFound('Return request not found.');
        }

        /** @var OrderReturnRequestRepository $repo */
        $repo = $this->em->getRepository(OrderReturnRequest::class);
        $returnRequest = $repo->findById($returnId);
        if ($returnRequest === null) {
            throw HttpException::notFound('Return request not found.');
        }

        // Resolve which of the user's vendors actually has items
        // in this return. Take the intersection.
        $returnVendorIds = $returnRequest->getVendorIds();
        $intersection = array_values(array_intersect($returnVendorIds, $userVendorIds));
        if ($intersection === []) {
            // User owns vendors, but none of them sold anything in
            // this return. Same response a stranger would see.
            throw HttpException::notFound('Return request not found.');
        }

        // Honor explicit ?vendor_id if it's both owned and present
        // in the return. Otherwise pick deterministically (smallest).
        $queryParams = $request->getQueryParams();
        $explicit = isset($queryParams['vendor_id']) ? (int) $queryParams['vendor_id'] : null;
        if ($explicit !== null && in_array($explicit, $intersection, true)) {
            $displayedVendorId = $explicit;
        } else {
            sort($intersection);
            $displayedVendorId = $intersection[0];
        }

        return $this->ok([
            'data' => $this->serializer->vendorShape($returnRequest, $displayedVendorId),
        ]);
    }
}
