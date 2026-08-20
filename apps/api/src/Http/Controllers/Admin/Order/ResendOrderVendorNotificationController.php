<?php

declare(strict_types=1);

namespace Bayti\Api\Http\Controllers\Admin\Order;

use Bayti\Api\Domain\Notification\NotificationLog;
use Bayti\Api\Domain\Notification\NotificationLogRepository;
use Bayti\Api\Domain\Order\Order;
use Bayti\Api\Domain\Order\OrderRepository;
use Bayti\Api\Domain\User\User;
use Bayti\Api\Http\Errors\ErrorCodes;
use Bayti\Api\Http\Errors\HttpException;
use Bayti\Api\Http\Middleware\AuthMiddleware;
use Bayti\Api\Http\Responder;
use Bayti\Api\Notification\EmailTemplate;
use Bayti\Api\Notification\OrderNotificationService;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * POST /v3/admin/orders/{id}/resend-vendor-notification
 *
 * Re-dispatches the "new order" (ORDER_PLACED_VENDOR) email to the order's
 * vendor(s) — used to recover a delivery that failed the first time (e.g. the
 * vendor's contact email was malformed and has since been corrected). Optional
 * body { "vendor_id": N } targets a single vendor; omitted resends to every
 * vendor on the order.
 *
 * Gated on `notifications.send`. Returns the resulting notification-log outcome
 * (sent / failed / skipped) so the admin sees immediately whether it went out.
 */
final class ResendOrderVendorNotificationController
{
    use Responder;

    public function __construct(
        protected readonly ResponseFactoryInterface $responseFactory,
        private readonly EntityManagerInterface $em,
        private readonly OrderNotificationService $notifications,
        private readonly LoggerInterface $logger = new NullLogger(),
    ) {
    }

    protected function getResponseFactory(): ResponseFactoryInterface
    {
        return $this->responseFactory;
    }

    /**
     * @param array<string, string> $args
     */
    public function __invoke(ServerRequestInterface $request, ResponseInterface $_response, array $args): ResponseInterface
    {
        $admin = $request->getAttribute(AuthMiddleware::ATTR_USER);
        if (!$admin instanceof User) {
            throw HttpException::unauthorized(ErrorCodes::AUTH_INVALID_TOKEN, 'Authentication required.');
        }

        $orderId = (int) ($args['id'] ?? 0);
        if ($orderId <= 0) {
            throw HttpException::notFound('Order not found.');
        }

        /** @var OrderRepository $orders */
        $orders = $this->em->getRepository(Order::class);
        $order = $orders->findByIdForAdmin($orderId);
        if ($order === null) {
            throw HttpException::notFound('Order not found.');
        }

        $body = (array) ($request->getParsedBody() ?? []);
        $vendorId = isset($body['vendor_id']) && (int) $body['vendor_id'] > 0
            ? (int) $body['vendor_id']
            : null;

        // Re-dispatch. safeSend() persists a fresh notification_log row (sent /
        // failed / skipped) synchronously, so we can read the outcome right after.
        $this->notifications->resendPlacedToVendor($order, $vendorId);

        /** @var NotificationLogRepository $logs */
        $logs = $this->em->getRepository(NotificationLog::class);
        $recent = $logs->findFilteredPaginated([
            'orderId' => $orderId,
            'template' => EmailTemplate::ORDER_PLACED_VENDOR->value,
            'limit' => 5,
        ]);
        /** @var NotificationLog|null $latest */
        $latest = $recent['items'][0] ?? null;

        $this->logger->info('admin.order.vendor_notification_resent', [
            'admin_id' => $admin->getId(),
            'order_id' => $orderId,
            'vendor_id' => $vendorId,
            'result_status' => $latest?->getStatus(),
            'recipient' => $latest?->getRecipient(),
        ]);

        return $this->ok([
            'resent' => true,
            'result' => $latest === null ? null : [
                'status' => $latest->getStatus(),
                'recipient' => $latest->getRecipient(),
                'error_kind' => $latest->getErrorKind(),
                'error_message' => $latest->getErrorMessage(),
                'sent_at' => $latest->getSentAt()->format(\DateTimeInterface::ATOM),
            ],
        ]);
    }
}
