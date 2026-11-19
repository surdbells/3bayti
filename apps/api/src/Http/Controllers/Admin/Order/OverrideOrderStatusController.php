<?php

declare(strict_types=1);

namespace Bayti\Api\Http\Controllers\Admin\Order;

use Bayti\Api\Domain\Audit\AuditEmitter;
use Bayti\Api\Domain\GiftCard\GiftCardOrderReversal;
use Bayti\Api\Domain\Order\Order;
use Bayti\Api\Domain\Order\OrderRepository;
use Bayti\Api\Domain\User\User;
use Bayti\Api\Http\Controllers\Admin\Order\Dto\OverrideOrderStatusInput;
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
 * PATCH /v3/admin/orders/{id}/status
 *
 * Body: { "status": "<Order::STATUS_*>", "reason": "..." }
 *
 * Admin-driven order status override. Bypasses normal state-machine
 * transition validation, used by safety overrides (unsticking a
 * stuck order, manually marking refunded, etc.).
 *
 * Q5=A audit: emits ACTION_OVERRIDDEN with before/after snapshot
 * + admin's reason.
 */
final class OverrideOrderStatusController
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
        private readonly GiftCardOrderReversal $giftCardReversal,
    ) {
    }

    /** Statuses in which the order no longer holds the customer's gift-card money. */
    private const RELEASING_STATUSES = [
        Order::STATUS_CANCELLED,
        Order::STATUS_FAILED,
        Order::STATUS_REFUNDED,
    ];

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

        $orderId = (int) ($args['id'] ?? 0);
        if ($orderId <= 0) {
            throw HttpException::notFound('Order not found.');
        }

        $input = $this->validator->parse($request, OverrideOrderStatusInput::class);

        /** @var OrderRepository $orders */
        $orders = $this->em->getRepository(Order::class);
        $order = $orders->findByIdForAdmin($orderId);
        if ($order === null) {
            throw HttpException::notFound('Order not found.');
        }

        $newStatus = $input->status ?? '';
        $reason = $input->reason ?? '';

        // Once an order's gift-card spend has been given back, moving it
        // back to an active status would leave it "paid" with money the
        // customer already has again. Refuse; the customer can re-order.
        if (!in_array($newStatus, self::RELEASING_STATUSES, true)
            && $this->giftCardReversal->hasRestoredForOrder($order)) {
            throw new HttpException(
                status: 422,
                errorCode: 'gift_card_balance_restored',
                publicMessage: 'This order\'s gift card payment was already returned to the customer, so it cannot be reactivated.',
                details: ['current_status' => $order->getStatus()],
            );
        }

        $previousStatus = $order->overrideStatus($newStatus);

        // Cancelled / failed / refunded: the order no longer holds the
        // customer's gift-card money, so give it back. The restore commits
        // the new status with it (same transaction). Any card refund is
        // the admin's separate refund action, as before.
        $giftCardRestored = '0.00';
        if (in_array($newStatus, self::RELEASING_STATUSES, true)) {
            $giftCardRestored = $this->giftCardReversal->restoreForOrder(
                $order,
                'Order ' . $newStatus . ' by admin: ' . $reason,
            );
        }

        $this->em->flush();

        $this->audit->recordOverride(
            request: $request,
            actor: $user,
            subject: $order,
            changes: [
                'before' => ['status' => $previousStatus],
                'after' => ['status' => $newStatus],
                'reason' => $reason,
                'gift_card_restored' => $giftCardRestored,
            ],
        );

        // Notify the customer of the order-status change (email + push).
        // Reuse the dedicated lifecycle methods where one exists
        // (cancelled / refunded); otherwise fall back to the generic
        // status-changed notice. All sends are fire-and-forget, a
        // notification failure must never break the override.
        if ($newStatus === Order::STATUS_CANCELLED) {
            $this->notifications->orderCancelled($order, ['reason' => $reason]);
            $this->pushNotifications->orderCancelled($order);
        } elseif ($newStatus === Order::STATUS_REFUNDED) {
            $this->notifications->orderRefunded($order, [
                'refund_amount' => $order->getTotal(),
                'is_full_refund' => true,
            ]);
            $this->pushNotifications->orderRefunded($order);
        } else {
            $this->notifications->orderStatusChanged($order, $newStatus);
            $this->pushNotifications->orderStatusChanged($order);
        }

        // Notify staff/admins that an admin overrode the order status.
        // Each recipient gets an email + a NotificationLog row so the
        // change surfaces in the admin notification bell. Degrades
        // cleanly when ADMIN_NOTIFICATION_EMAILS is empty.
        $this->notifications->adminOrderStatusChanged(
            order: $order,
            newStatus: $newStatus,
            actor: $user->getEmail(),
            details: [
                'scope' => 'order',
                'old_status' => $previousStatus,
                'reason' => $reason,
            ],
        );

        return $this->ok(['order' => $this->serializer->detailShape($order)]);
    }
}
