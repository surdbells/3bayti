<?php

declare(strict_types=1);

namespace Bayti\Api\Http\Controllers\Vendor\Order;

use Bayti\Api\Domain\Catalog\Vendor;
use Bayti\Api\Domain\Catalog\VendorRepository;
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
 * POST /v3/vendor/orders/{orderId}/ship
 *
 * The vendor books courier (OTO) delivery for their ready-to-ship items on an
 * order — a pickup from the store to the customer. Replaces the manual
 * "mark shipped" step: on success the items advance to `shipped`.
 *
 * Body (optional): { "delivery_option_id": "<carrier option>" } to force a
 * specific carrier; omitted lets the provider auto-assign.
 *
 * One owner may run more than one store; every owned store with ready items on
 * this order is booked (one shipment each). Cross-vendor isolation via the
 * repository. 422 when nothing is ready to ship, 409 if already booked, 502 if
 * the courier API fails.
 */
final class ShipVendorOrderController
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

        $orderId = (int) ($args['orderId'] ?? 0);
        if ($orderId <= 0) {
            throw HttpException::notFound('Order not found.');
        }

        $body = (array) ($request->getParsedBody() ?? []);
        $deliveryOptionId = isset($body['delivery_option_id']) && is_string($body['delivery_option_id'])
            && trim($body['delivery_option_id']) !== ''
            ? trim($body['delivery_option_id'])
            : null;

        /** @var VendorRepository $vendorRepo */
        $vendorRepo = $this->em->getRepository(Vendor::class);
        $vendors = $vendorRepo->findByOwnerUser($user);
        if ($vendors === []) {
            throw HttpException::notFound('Order not found.');
        }
        $vendorIds = array_map(static fn (Vendor $v): int => (int) $v->getId(), $vendors);

        /** @var OrderRepository $orders */
        $orders = $this->em->getRepository(Order::class);
        $order = $orders->findForVendorIds($orderId, $vendorIds);
        if ($order === null) {
            throw HttpException::notFound('Order not found.');
        }

        $shipments = [];
        $lastError = null;
        foreach ($vendors as $vendor) {
            try {
                $shipments[] = $this->booking->bookForVendor($order, $vendor, $deliveryOptionId, $user, $request);
            } catch (ShippingException $e) {
                // This store has nothing to ship on this order — skip it and try
                // the owner's other stores. Remember any real failure so we can
                // surface it if NOTHING ends up shipping.
                if ($e->kind !== ShippingException::KIND_NO_ITEMS) {
                    $lastError = $e;
                }
            }
        }

        if ($shipments === []) {
            if ($lastError !== null) {
                throw new HttpException(
                    ShippingException::httpStatusFor($lastError->kind),
                    $lastError->kind,
                    $lastError->getMessage(),
                );
            }
            throw new HttpException(422, 'no_shippable_items', 'No items are ready to ship on this order.');
        }

        $vendorIdSet = array_flip($vendorIds);
        return $this->ok([
            'shipments' => $this->shipmentSerializer->shapeMany($shipments),
            'order' => $this->orderSerializer->scopeToVendor(
                $this->orderSerializer->detailShape($order),
                $vendorIdSet,
            ),
        ]);
    }
}
