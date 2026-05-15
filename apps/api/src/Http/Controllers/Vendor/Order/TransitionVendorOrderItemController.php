<?php

declare(strict_types=1);

namespace Bayti\Api\Http\Controllers\Vendor\Order;

use Bayti\Api\Domain\Catalog\Vendor;
use Bayti\Api\Domain\Catalog\VendorRepository;
use Bayti\Api\Domain\Order\Order;
use Bayti\Api\Domain\Order\OrderItem;
use Bayti\Api\Domain\Order\OrderRepository;
use Bayti\Api\Domain\User\User;
use Bayti\Api\Http\Controllers\Vendor\Order\Dto\TransitionOrderItemInput;
use Bayti\Api\Http\Errors\ErrorCodes;
use Bayti\Api\Http\Errors\HttpException;
use Bayti\Api\Http\Middleware\AuthMiddleware;
use Bayti\Api\Http\Responder;
use Bayti\Api\Http\Serializers\OrderSerializer;
use Bayti\Api\Http\Validator\RequestValidator;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Log\LoggerInterface;

/**
 * PATCH /v3/vendor/orders/{orderId}/items/{itemId}/status
 *
 * Vendor-driven line-item state transition.
 *
 * Body: { "status": "preparing", "note": "optional" }
 *
 * Authorization
 * -------------
 * Vendor must own the line item (i.e. item.vendor_id is in the
 * vendor ids owned by the authenticated user). Cross-vendor attempts
 * return 404 to avoid leaking item existence.
 *
 * State transitions
 * -----------------
 * Validated by OrderItem::setItemStatus per the legalTransitions()
 * map. Illegal transitions return 422 with the allowed-list, NOT 500.
 *
 * Order-level rollup
 * ------------------
 * After the item transition, Order::recomputeStatusFromItems() rolls
 * the order's status up (e.g. all items delivered → order delivered).
 * The rollup is idempotent — re-running doesn't drift state.
 *
 * Response
 * --------
 * Returns the updated order with items filtered to the vendor's
 * scope (same shape as GetVendorOrderController).
 */
final class TransitionVendorOrderItemController
{
    use Responder;

    public function __construct(
        protected readonly ResponseFactoryInterface $responseFactory,
        private readonly RequestValidator $validator,
        private readonly EntityManagerInterface $em,
        private readonly OrderSerializer $serializer,
        private readonly \Bayti\Api\Notification\OrderNotificationService $notifications,
        private readonly LoggerInterface $logger,
    ) {
    }

    protected function getResponseFactory(): ResponseFactoryInterface
    {
        return $this->responseFactory;
    }

    /**
     * @param array<string, string> $args Slim route params:
     *   ['orderId' => '42', 'itemId' => '7']
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

        $orderId = (int) ($args['orderId'] ?? 0);
        $itemId = (int) ($args['itemId'] ?? 0);
        if ($orderId <= 0 || $itemId <= 0) {
            throw HttpException::notFound('Order item not found.');
        }

        $input = $this->validator->parse($request, TransitionOrderItemInput::class);
        $newStatus = $input->status;
        if ($newStatus === null) {
            // Defensive — validator should already have rejected this.
            throw HttpException::businessRuleViolation(
                'invalid_status',
                'status is required.',
            );
        }

        /** @var VendorRepository $vendors */
        $vendors = $this->em->getRepository(Vendor::class);
        $vendorIds = $vendors->findIdsByOwnerUser($user);
        if ($vendorIds === []) {
            throw HttpException::notFound('Order item not found.');
        }

        /** @var OrderRepository $orders */
        $orders = $this->em->getRepository(Order::class);
        $order = $orders->findForVendorIds($orderId, $vendorIds);
        if ($order === null) {
            throw HttpException::notFound('Order item not found.');
        }

        // Locate the item AND verify it belongs to the vendor's scope.
        // Cross-vendor item id in this order → 404 (don't reveal that
        // another vendor's item exists at this position).
        $vendorIdSet = array_flip($vendorIds);
        $item = null;
        foreach ($order->getItems() as $candidate) {
            if ($candidate->getId() === $itemId) {
                if (!isset($vendorIdSet[$candidate->getVendor()->getId() ?? -1])) {
                    // Wrong vendor — return 404 not 403
                    throw HttpException::notFound('Order item not found.');
                }
                $item = $candidate;
                break;
            }
        }
        if ($item === null) {
            throw HttpException::notFound('Order item not found.');
        }

        // Validate transition + apply.
        try {
            $item->setItemStatus($newStatus);
        } catch (\InvalidArgumentException $e) {
            // Surface the allowed-list to the caller so they can show
            // the right UI state. Pre-compute from the legal-transition
            // map; matches what the entity would have allowed.
            $allowed = OrderItem::legalTransitions()[$item->getItemStatus()] ?? [];
            throw new HttpException(
                status: 422,
                errorCode: 'illegal_status_transition',
                publicMessage: $e->getMessage(),
                details: [
                    'current_status' => $item->getItemStatus(),
                    'requested_status' => $newStatus,
                    'allowed_transitions' => $allowed,
                ],
            );
        }

        // Note is captured in the structured log for audit; we don't
        // persist it on OrderItem in M3.1.7 (would require schema
        // change). Phase D adds OrderAuditLog which captures notes
        // server-side for admin actions; vendor notes for now flow
        // through structured logging only.

        // Roll order status up from item statuses.
        $order->recomputeStatusFromItems();

        $this->em->flush();

        $this->logger->info('vendor.order_item.transitioned', [
            'user_id' => $user->getId(),
            'order_id' => $order->getId(),
            'order_reference' => $order->getOrderReference(),
            'item_id' => $item->getId(),
            'new_status' => $newStatus,
            'order_status_after_rollup' => $order->getStatus(),
            'vendor_note' => $input->note,
        ]);

        // M3.1.7-H — notify customer of shipping/delivery milestones.
        // Per-item notifications: customer wants to know as each piece
        // moves, not just when the whole order completes.
        if ($newStatus === OrderItem::ITEM_STATUS_SHIPPED) {
            $this->notifications->itemShipped($order, $item);
        } elseif ($newStatus === OrderItem::ITEM_STATUS_DELIVERED) {
            $this->notifications->itemDelivered($order, $item);
        }

        // Return the updated order, filtered to vendor's items.
        $shape = $this->serializer->detailShape($order);
        $shape['items'] = array_values(array_filter(
            $shape['items'],
            static function (array $itemShape) use ($vendorIdSet): bool {
                $vid = $itemShape['vendor_id'] ?? null;
                return is_int($vid) && isset($vendorIdSet[$vid]);
            },
        ));

        return $this->ok(['order' => $shape]);
    }
}
