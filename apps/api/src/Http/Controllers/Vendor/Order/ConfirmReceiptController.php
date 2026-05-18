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
 * POST /v3/vendor/returns/{id}/confirm-receipt
 *
 * Vendor confirms that the returned goods have physically arrived
 * back at their warehouse — this is the ONLY vendor-side state
 * transition (per Q-VendorRole locked: vendor does NOT approve or
 * deny — admin does that; vendor only confirms receipt).
 *
 * State machine: STATUS_PICKED_UP → STATUS_DELIVERED_TO_VENDOR.
 * Aggregate root rejects illegal transitions with DomainException
 * which we translate to 422 with RETURN_CANNOT_CONFIRM_RECEIPT.
 *
 * Authorization:
 *   - VendorAuthMiddleware enforces approved-vendor upstream
 *   - Vendor must own at least one vendor whose items appear in
 *     the return (intersection check)
 *
 * Response: 200 with vendor-shape (status now delivered_to_vendor,
 * delivered_to_vendor_at stamped).
 */
final class ConfirmReceiptController
{
    use Responder;

    public function __construct(
        protected readonly ResponseFactoryInterface $responseFactory,
        private readonly EntityManagerInterface $em,
        private readonly ReturnRequestSerializer $serializer,
        private readonly \Bayti\Api\Notification\OrderNotificationService $notifications,
        private readonly \Psr\Log\LoggerInterface $logger,
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

        $intersection = array_values(array_intersect(
            $returnRequest->getVendorIds(),
            $userVendorIds,
        ));
        if ($intersection === []) {
            throw HttpException::notFound('Return request not found.');
        }

        try {
            $returnRequest->confirmReceivedByVendor();
        } catch (\DomainException $e) {
            throw new HttpException(
                status: 422,
                errorCode: 'RETURN_CANNOT_CONFIRM_RECEIPT',
                publicMessage: $e->getMessage(),
            );
        }

        $repo->save($returnRequest);

        try {
            $this->notifications->returnReceivedByVendor($returnRequest->getOrder(), [
                'return_reference' => 'RET-' . ($returnRequest->getId() ?? 0),
            ]);
        } catch (\Throwable $e) {
            $this->logger->error('return.notification.received_failed', [
                'return_id' => $returnRequest->getId(),
                'error' => $e->getMessage(),
            ]);
        }

        sort($intersection);
        $displayedVendorId = $intersection[0];

        return $this->ok([
            'data' => $this->serializer->vendorShape($returnRequest, $displayedVendorId),
        ]);
    }
}
