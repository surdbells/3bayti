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
use Bayti\Api\Http\Serializers\OrderSerializer;
use Bayti\Api\Http\Serializers\ShipmentSerializer;
use Bayti\Api\Shipping\ShipmentBookingService;
use Bayti\Api\Shipping\ShippingException;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * POST /v3/admin/orders/{orderId}/vendors/{vendorId}/ship
 *
 * Admin books courier (OTO) delivery for a SPECIFIC store's ready items on an
 * order — the same engine as the vendor's own ship action, for support/ops.
 * Body (optional): { "delivery_option_id": "<carrier>" }; omitted auto-assigns.
 * Gated by orders.update_item_status.
 */
final class ShipAdminOrderVendorController
{
    use Responder;

    public function __construct(
        protected readonly ResponseFactoryInterface $responseFactory,
        private readonly EntityManagerInterface $em,
        private readonly ShipmentBookingService $booking,
        private readonly ShipmentSerializer $shipmentSerializer,
        private readonly OrderSerializer $orderSerializer,
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

        [$order, $vendor] = $this->resolve($args);

        $body = (array) ($request->getParsedBody() ?? []);
        $deliveryOptionId = isset($body['delivery_option_id']) && is_string($body['delivery_option_id'])
            && trim($body['delivery_option_id']) !== ''
            ? trim($body['delivery_option_id'])
            : null;

        try {
            $shipment = $this->booking->bookForVendor($order, $vendor, $deliveryOptionId, $user, $request);
        } catch (ShippingException $e) {
            throw new HttpException(
                ShippingException::httpStatusFor($e->kind),
                $e->kind,
                $e->getMessage(),
            );
        }

        return $this->ok([
            'shipment' => $this->shipmentSerializer->shape($shipment),
            'order' => $this->orderSerializer->detailShape($order),
        ]);
    }

    /**
     * @param array<string, string> $args
     * @return array{0: Order, 1: Vendor}
     */
    private function resolve(array $args): array
    {
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

        return [$order, $vendor];
    }
}
