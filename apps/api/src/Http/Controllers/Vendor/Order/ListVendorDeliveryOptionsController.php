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
use Bayti\Api\Shipping\ShipmentBookingService;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * GET /v3/vendor/orders/{orderId}/delivery-options
 *
 * Carrier options (id + name + price) the vendor can choose from when booking
 * delivery for their ready-to-ship items on an order — the "choose a carrier"
 * flow. Grouped per owned store (usually one). Empty list when the provider is
 * disabled or can't quote (the vendor can still book with auto-assign).
 */
final class ListVendorDeliveryOptionsController
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
        if ($orderId <= 0) {
            throw HttpException::notFound('Order not found.');
        }

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

        $stores = [];
        foreach ($vendors as $vendor) {
            $options = $this->booking->listOptionsForVendor($order, $vendor);
            if ($options === []) {
                continue;
            }
            $stores[] = [
                'vendor_id' => $vendor->getId(),
                'vendor_name' => $vendor->getName(),
                'options' => $options,
            ];
        }

        return $this->ok([
            'enabled' => $this->booking->isEnabled(),
            'stores' => $stores,
        ]);
    }
}
