<?php

declare(strict_types=1);

namespace Bayti\Api\Http\Controllers\Admin\Order;

use Bayti\Api\Domain\Audit\AuditEmitter;
use Bayti\Api\Domain\Order\Order;
use Bayti\Api\Domain\Order\OrderItem;
use Bayti\Api\Domain\Order\OrderRepository;
use Bayti\Api\Domain\User\User;
use Bayti\Api\Http\Controllers\Admin\Order\Dto\OverrideOrderItemStatusInput;
use Bayti\Api\Http\Errors\ErrorCodes;
use Bayti\Api\Http\Errors\HttpException;
use Bayti\Api\Http\Middleware\AuthMiddleware;
use Bayti\Api\Http\Responder;
use Bayti\Api\Http\Serializers\OrderSerializer;
use Bayti\Api\Http\Validator\RequestValidator;
use Bayti\Api\Notification\OrderNotificationService;
use Bayti\Api\Notification\Push\PushNotificationService;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * PATCH /v3/admin/orders/{orderId}/items/{itemId}/status
 *
 * Body: { "status": "<OrderItem::ITEM_STATUS_*>", "reason": "..." }
 *
 * Admin-driven item status override. Bypasses transition validation
 * via OrderItem::setItemStatus($status, adminOverride: true). Admins
 * can force any state including ones vendors can't (cancelled,
 * returned, refunded).
 *
 * After the item override, Order::recomputeStatusFromItems() rolls
 * the order status up. The order's own status is NOT directly
 * overridden by this endpoint, use OverrideOrderStatusController
 * for that.
 */
final class OverrideOrderItemStatusController
{
    use Responder;

    public function __construct(
        protected readonly ResponseFactoryInterface $responseFactory,
        private readonly RequestValidator $validator,
        private readonly EntityManagerInterface $em,
        private readonly OrderSerializer $serializer,
        private readonly AuditEmitter $audit,
        private readonly OrderNotificationService $notifications,
        private readonly PushNotificationService $pushNotifications,
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

        $orderId = (int) ($args['orderId'] ?? 0);
        $itemId = (int) ($args['itemId'] ?? 0);
        if ($orderId <= 0 || $itemId <= 0) {
            throw HttpException::notFound('Order item not found.');
        }

        $input = $this->validator->parse($request, OverrideOrderItemStatusInput::class);
        $newStatus = $input->status ?? '';
        $reason = $input->reason ?? '';

        /** @var OrderRepository $orders */
        $orders = $this->em->getRepository(Order::class);
        $order = $orders->findByIdForAdmin($orderId);
        if ($order === null) {
            throw HttpException::notFound('Order item not found.');
        }

        $item = null;
        foreach ($order->getItems() as $candidate) {
            if ($candidate->getId() === $itemId) {
                $item = $candidate;
                break;
            }
        }
        if ($item === null) {
            throw HttpException::notFound('Order item not found.');
        }

        $previousItemStatus = $item->getItemStatus();
        $previousOrderStatus = $order->getStatus();

        // adminOverride bypasses transition validation; enum is still
        // checked. Admin can force pending → delivered directly.
        $item->setItemStatus($newStatus, adminOverride: true);

        // Roll order up automatically.
        $order->recomputeStatusFromItems();

        $this->em->flush();

        $this->audit->recordOverride(
            request: $request,
            actor: $user,
            subject: $item,
            changes: [
                'before' => [
                    'item_status' => $previousItemStatus,
                    'order_status' => $previousOrderStatus,
                ],
                'after' => [
                    'item_status' => $newStatus,
                    'order_status' => $order->getStatus(),
                ],
                'reason' => $reason,
            ],
        );

        // Notify the customer of the per-item state change (email + push),
        // mirroring TransitionVendorOrderItemController's dispatch. All
        // sends are fire-and-forget, a notification failure must never
        // break the override (the status change is already persisted).
        if ($newStatus === OrderItem::ITEM_STATUS_ACCEPTED) {
            $this->notifications->itemAccepted($order, $item);
            $this->pushNotifications->itemAccepted($order);
        } elseif ($newStatus === OrderItem::ITEM_STATUS_PREPARING) {
            $this->notifications->itemPreparing($order, $item);
            $this->pushNotifications->itemPreparing($order);
        } elseif ($newStatus === OrderItem::ITEM_STATUS_REJECTED) {
            $this->notifications->itemRejected($order, $item);
            $this->pushNotifications->itemRejected($order);
        } elseif ($newStatus === OrderItem::ITEM_STATUS_SHIPPED) {
            $this->notifications->itemShipped($order, $item);
            $this->pushNotifications->itemShipped($order);
        } elseif ($newStatus === OrderItem::ITEM_STATUS_DELIVERED) {
            $this->notifications->itemDelivered($order, $item);
            $this->pushNotifications->itemDelivered($order);
        }

        // Notify staff/admins that an admin overrode an item status. Each
        // recipient gets an email + a NotificationLog row so the change
        // surfaces in the admin notification bell. Degrades cleanly when
        // ADMIN_NOTIFICATION_EMAILS is empty.
        $this->notifications->adminOrderStatusChanged(
            order: $order,
            newStatus: $newStatus,
            actor: $user->getEmail(),
            details: [
                'scope' => 'item',
                'item_name' => $item->getProductNameSnapshot(),
                'old_status' => $previousItemStatus,
                'reason' => $reason,
            ],
        );

        return $this->ok(['order' => $this->serializer->detailShape($order)]);
    }
}
