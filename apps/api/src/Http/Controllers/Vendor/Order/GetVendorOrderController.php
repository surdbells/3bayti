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
use Doctrine\ORM\EntityManagerInterface;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * GET /v3/vendor/orders/{id}
 *
 * Single order detail, with items filtered to those belonging to the
 * requesting vendor's stores.
 *
 * Cross-vendor isolation
 * ----------------------
 * Returns 404 if:
 *   - Order doesn't exist
 *   - Order exists but contains NO items from any of the requesting
 *     vendor's stores
 *
 * 404 (not 403) is intentional — returning 403 would reveal the
 * existence of orders the vendor isn't part of.
 *
 * Returns:
 *   {
 *     "order": { id, reference, status, total, ...,
 *                items: [ /* only this vendor's items *\/ ] }
 *   }
 *
 * The same order may also include other vendors' items in the
 * server-side entity, but those are stripped during serialisation
 * so the vendor never sees them.
 */
final class GetVendorOrderController
{
    use Responder;

    public function __construct(
        protected readonly ResponseFactoryInterface $responseFactory,
        private readonly EntityManagerInterface $em,
        private readonly OrderSerializer $serializer,
    ) {
    }

    protected function getResponseFactory(): ResponseFactoryInterface
    {
        return $this->responseFactory;
    }

    /**
     * @param array<string, string> $args Slim route params: ['id' => '42']
     */
    public function __invoke(ServerRequestInterface $request, ResponseInterface $_response, array $args): ResponseInterface
    {
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

        /** @var VendorRepository $vendors */
        $vendors = $this->em->getRepository(Vendor::class);
        $vendorIds = $vendors->findIdsByOwnerUser($user);

        /** @var OrderRepository $orders */
        $orders = $this->em->getRepository(Order::class);
        $order = $orders->findForVendorIds($orderId, $vendorIds);
        if ($order === null) {
            throw HttpException::notFound('Order not found.');
        }

        $vendorIdSet = array_flip($vendorIds);
        $shape = $this->serializer->detailShape($order);

        // Filter items[] to caller's vendor.
        $shape['items'] = array_values(array_filter(
            $shape['items'],
            static function (array $item) use ($vendorIdSet): bool {
                $vid = $item['vendor_id'] ?? null;
                return is_int($vid) && isset($vendorIdSet[$vid]);
            },
        ));

        return $this->ok(['order' => $shape]);
    }
}
