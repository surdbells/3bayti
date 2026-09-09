<?php

declare(strict_types=1);

namespace Bayti\Api\Http\Controllers\Admin\Order;

use Bayti\Api\Domain\Catalog\Vendor;
use Bayti\Api\Domain\Order\Order;
use Bayti\Api\Domain\Order\OrderRepository;
use Bayti\Api\Domain\User\User;
use Bayti\Api\Http\Errors\ErrorCodes;
use Bayti\Api\Http\Errors\HttpException;
use Bayti\Api\Http\Middleware\AuthMiddleware;
use Bayti\Api\Http\Responder;
use Bayti\Api\Shipping\ShipmentBookingService;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * GET /v3/admin/orders/{orderId}/vendors/{vendorId}/delivery-options
 *
 * Carrier options for a specific store's ready items on an order, for the admin
 * "choose a carrier" flow. Empty when the provider is disabled or can't quote.
 * Gated by orders.view_detail.
 */
final class ListAdminDeliveryOptionsController
{
    use Responder;

    public function __construct(
        protected readonly ResponseFactoryInterface $responseFactory,
        private readonly EntityManagerInterface $em,
        private readonly ShipmentBookingService $booking,
    ) {
    }

    protected function getResponseFactory(): ResponseFactoryInterface
    {
        return $this->responseFactory;
    }

    /** @param array<string, string> $args */
    public function __invoke(ServerRequestInterface $request, ResponseInterface $_response, array $args): ResponseInterface
    {
        $user = $request->getAttribute(AuthMiddleware::ATTR_USER);
        if (!$user instanceof User) {
            throw HttpException::unauthorized(ErrorCodes::AUTH_INVALID_TOKEN, 'Authentication required.');
        }

        $orderId = (int) ($args['orderId'] ?? 0);
        $vendorId = (int) ($args['vendorId'] ?? 0);
        if ($orderId <= 0 || $vendorId <= 0) {
            throw HttpException::notFound('Order not found.');
        }

        /** @var OrderRepository $orders */
        $orders = $this->em->getRepository(Order::class);
        $order = $orders->findByIdForAdmin($orderId);
        if ($order === null) {
            throw HttpException::notFound('Order not found.');
        }

        $vendor = $this->em->getRepository(Vendor::class)->find($vendorId);
        if (!$vendor instanceof Vendor) {
            throw HttpException::notFound('Store not found.');
        }

        return $this->ok([
            'enabled' => $this->booking->isEnabled(),
            'options' => $this->booking->listOptionsForVendor($order, $vendor),
        ]);
    }
}
