<?php

declare(strict_types=1);

namespace Bayti\Api\Http\Controllers\Order;

use Bayti\Api\Domain\Order\Order;
use Bayti\Api\Domain\Order\OrderRepository;
use Bayti\Api\Domain\Order\OrderReturnRequest;
use Bayti\Api\Domain\Order\OrderReturnRequestItem;
use Bayti\Api\Domain\Order\OrderReturnRequestPhoto;
use Bayti\Api\Domain\Order\OrderReturnRequestRepository;
use Bayti\Api\Domain\Order\ReturnPhotoStorageService;
use Bayti\Api\Domain\Order\ReturnRequestEligibilityService;
use Bayti\Api\Domain\User\User;
use Bayti\Api\Http\Controllers\Order\Dto\SubmitReturnInput;
use Bayti\Api\Http\Errors\ErrorCodes;
use Bayti\Api\Http\Errors\HttpException;
use Bayti\Api\Http\Middleware\AuthMiddleware;
use Bayti\Api\Http\PaginatedEnvelope;
use Bayti\Api\Http\Responder;
use Bayti\Api\Http\Serializers\ReturnRequestSerializer;
use Bayti\Api\Http\Validator\RequestValidator;
use Doctrine\ORM\EntityManagerInterface;
use League\Flysystem\FilesystemException;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\UploadedFileInterface;
use Psr\Log\LoggerInterface;

/**
 * POST /v3/orders/{id}/returns
 *
 * Customer submits a new return request for one or more items in
 * one of their orders. The order must be paid + within the
 * eligibility window (14 days from paid_at). Items must be in
 * DELIVERED status and not already in an in-flight return.
 *
 * Request body
 * ============
 * multipart/form-data with these fields:
 *   - reason: one of OrderReturnRequest::ALL_REASONS
 *   - customer_notes: optional; required when reason='other'
 *   - order_item_ids: list of OrderItem ids to return
 *   - photos[]: 0–5 uploaded files (jpeg/png/webp, max 5 MB each)
 *
 * Response
 * ========
 * 201 Created with the customer-shape of the new return request.
 *
 * Error paths
 * ===========
 * 404 — order not found OR doesn't belong to the customer
 * 422 — validation failure (missing fields, oversized photos,
 *       eligibility rule failure with RETURN_* error code)
 * 500 — Flysystem write failure (rare)
 *
 * Audit
 * =====
 * No explicit AuditEmitter call from this path — customer actions
 * are intrinsically logged via the order timeline; the
 * OrderReturnRequest row itself IS the audit record (with
 * requested_at + customer_user_id stamped at construction).
 *
 * Transaction discipline
 * =====================
 * Photos are uploaded BEFORE the entity is persisted. If the
 * persist fails for any reason (DB error, eligibility check
 * surfacing a race condition, etc.), the photo blobs are
 * orphaned on disk. The operator playbook's orphan-cleanup
 * cron sweeps these out (X.18-I).
 */
final class SubmitReturnController
{
    use Responder;

    public function __construct(
        protected readonly ResponseFactoryInterface $responseFactory,
        private readonly RequestValidator $validator,
        private readonly EntityManagerInterface $em,
        private readonly ReturnRequestSerializer $serializer,
        private readonly ReturnRequestEligibilityService $eligibility,
        private readonly ReturnPhotoStorageService $photoStorage,
        private readonly LoggerInterface $logger,
        private readonly \Bayti\Api\Notification\OrderNotificationService $notifications,
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
        $user = $this->requireUser($request);

        $orderId = (int) ($args['id'] ?? 0);
        if ($orderId <= 0) {
            throw HttpException::notFound('Order not found.');
        }

        // Parse + normalize the form fields. Validator runs the
        // Symfony constraints. The cross-field reason='other'
        // check fires here as a fast-path 422.
        $input = $this->validator->parse($request, SubmitReturnInput::class);
        if ($input->requiresNotes()) {
            throw HttpException::validation([
                'customer_notes' => ["customer_notes is required when reason is 'other'."],
            ]);
        }

        // 404 — and only 404 — for cross-user attempts (Q-Authorization=A).
        /** @var OrderRepository $orderRepo */
        $orderRepo = $this->em->getRepository(Order::class);
        $order = $orderRepo->findForUser($orderId, $user);
        if ($order === null) {
            throw HttpException::notFound('Order not found.');
        }

        // Eligibility check (Rules 1, 2, 3 from X.18-C).
        $eligibility = $this->eligibility->evaluate($user, $order, $input->order_item_ids);
        if (!$eligibility->ok) {
            throw new HttpException(
                status: 422,
                errorCode: $eligibility->errorCode ?? 'RETURN_NOT_ELIGIBLE',
                publicMessage: $eligibility->errorMessage ?? 'Return request is not eligible.',
            );
        }

        // Photo validation BEFORE entity construction so we fail
        // fast on bad files without persisting half a return.
        $uploadedPhotos = $this->collectPhotos($request);
        if (count($uploadedPhotos) > OrderReturnRequestPhoto::MAX_PHOTOS_PER_REQUEST) {
            throw HttpException::validation([
                'photos' => [
                    'Maximum ' . OrderReturnRequestPhoto::MAX_PHOTOS_PER_REQUEST
                    . ' photos allowed; received ' . count($uploadedPhotos) . '.',
                ],
            ]);
        }

        // Construct + persist. Photos are uploaded first; if any
        // upload fails we return 422 before any DB writes. Then the
        // OrderReturnRequest + items + photos are persisted in a
        // single transaction.
        $returnRequest = new OrderReturnRequest(
            order: $order,
            customer: $user,
            reason: $input->reason,
            customerNotes: $input->customer_notes,
        );
        foreach ($eligibility->resolvedItems as $orderItem) {
            // v1 returns the FULL quantity of each item. Partial-qty
            // is a future enhancement (entity layer supports it).
            $returnRequest->addItem(new OrderReturnRequestItem(
                $orderItem,
                $orderItem->getQuantity(),
            ));
        }

        // Upload photos. Use returnRequestId=0 for the 'pending' subdir
        // since the parent isn't persisted yet — the path is stable
        // and captured into the photo entity.
        $storedPhotos = [];
        try {
            foreach ($uploadedPhotos as $upload) {
                $storedPhotos[] = $this->photoStorage->store($upload, returnRequestId: 0);
            }
        } catch (\InvalidArgumentException $e) {
            // Roll back any blobs already uploaded in this request.
            foreach ($storedPhotos as $stored) {
                $this->photoStorage->delete($stored->storagePath);
            }
            throw HttpException::validation([
                'photos' => [$e->getMessage()],
            ]);
        } catch (FilesystemException $e) {
            foreach ($storedPhotos as $stored) {
                $this->photoStorage->delete($stored->storagePath);
            }
            $this->logger->error('return.photo.write_failed', [
                'order_id' => $orderId,
                'user_id' => $user->getId(),
                'error' => $e->getMessage(),
            ]);
            throw new HttpException(
                status: 500,
                errorCode: 'RETURN_PHOTO_STORAGE_FAILED',
                publicMessage: 'Photo upload failed; please try again.',
            );
        }

        foreach ($storedPhotos as $stored) {
            $returnRequest->addPhoto(new OrderReturnRequestPhoto(
                storagePath: $stored->storagePath,
                mimeType: $stored->mimeType,
                sizeBytes: $stored->sizeBytes,
                originalFilename: $stored->originalFilename,
            ));
        }

        /** @var OrderReturnRequestRepository $returnRepo */
        $returnRepo = $this->em->getRepository(OrderReturnRequest::class);
        $returnRepo->save($returnRequest);

        // M3.2.X.18-G — Fan out submit notifications: customer +
        // affected vendors + admins. Wrapped in try/catch defense in
        // depth — notification failure must never block the response.
        try {
            $this->notifications->returnSubmitted($order, [
                'return_reference' => $this->formatReturnReference($returnRequest),
                'reason' => $returnRequest->getReason(),
                'customer_notes' => $returnRequest->getCustomerNotes(),
                'returned_items' => $this->extractItemNames($returnRequest),
                'vendor_ids' => $returnRequest->getVendorIds(),
            ]);
        } catch (\Throwable $e) {
            $this->logger->error('return.notification.submit_failed', [
                'return_id' => $returnRequest->getId(),
                'error' => $e->getMessage(),
            ]);
        }

        return $this->created([
            'data' => $this->serializer->customerShape($returnRequest),
        ]);
    }

    private function formatReturnReference(OrderReturnRequest $rr): string
    {
        return 'RET-' . ($rr->getId() ?? 0);
    }

    /**
     * @return list<string>
     */
    private function extractItemNames(OrderReturnRequest $rr): array
    {
        $out = [];
        foreach ($rr->getItems() as $item) {
            $out[] = $item->getOrderItem()->getProductNameSnapshot();
        }
        return $out;
    }

    private function requireUser(ServerRequestInterface $request): User
    {
        $user = $request->getAttribute(AuthMiddleware::ATTR_USER);
        if (!$user instanceof User) {
            throw HttpException::unauthorized(
                ErrorCodes::AUTH_INVALID_TOKEN,
                'Authentication required.',
            );
        }
        return $user;
    }

    /**
     * Extract photo uploads from the request. Accepts either the
     * single-name form ('photo' or 'photos' as a single file) or
     * the array form ('photos[]') which Slim parses into a list.
     *
     * @return list<UploadedFileInterface>
     */
    private function collectPhotos(ServerRequestInterface $request): array
    {
        $files = $request->getUploadedFiles();
        $candidates = $files['photos'] ?? $files['photo'] ?? [];
        if ($candidates instanceof UploadedFileInterface) {
            return [$candidates];
        }
        if (!is_array($candidates)) {
            return [];
        }
        $out = [];
        foreach ($candidates as $file) {
            if ($file instanceof UploadedFileInterface) {
                $out[] = $file;
            }
        }
        return $out;
    }
}
