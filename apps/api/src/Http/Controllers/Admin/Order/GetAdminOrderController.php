<?php

declare(strict_types=1);

namespace Bayti\Api\Http\Controllers\Admin\Order;

use Bayti\Api\Domain\Audit\AuditEmitter;
use Bayti\Api\Domain\GiftCard\GiftCard;
use Bayti\Api\Domain\GiftCard\GiftCardRepository;
use Bayti\Api\Domain\Order\Order;
use Bayti\Api\Domain\Order\OrderRepository;
use Bayti\Api\Domain\Order\OrderReturnRequest;
use Bayti\Api\Domain\Order\OrderReturnRequestRepository;
use Bayti\Api\Domain\User\Measurement;
use Bayti\Api\Domain\User\MeasurementRepository;
use Bayti\Api\Domain\User\User;
use Bayti\Api\Http\Errors\ErrorCodes;
use Bayti\Api\Http\Errors\HttpException;
use Bayti\Api\Http\Middleware\AuthMiddleware;
use Bayti\Api\Http\Responder;
use Bayti\Api\Http\Serializers\MeasurementSerializer;
use Bayti\Api\Http\Serializers\OrderSerializer;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * GET /v3/admin/orders/{id}
 *
 * Admin order detail. Full unfiltered shape (all items from all
 * vendors, all addresses). Q5=A audit: ACTION_VIEWED on the
 * specific order.
 */
final class GetAdminOrderController
{
    use Responder;

    public function __construct(
        protected readonly ResponseFactoryInterface $responseFactory,
        private readonly EntityManagerInterface $em,
        private readonly OrderSerializer $serializer,
        private readonly AuditEmitter $audit,
        private readonly MeasurementSerializer $measurementSerializer,
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

        $this->audit->recordView(
            request: $request,
            actor: $user,
            subject: $order,
            context: ['context' => 'admin_order_detail'],
        );

        // M3.2.X.18-H, Embed return summaries inline. Admin sees
        // every return on the order, not customer-scoped.
        $returns = [];
        try {
            /** @var OrderReturnRequestRepository $returnRepo */
            $returnRepo = $this->em->getRepository(OrderReturnRequest::class);
            $returns = $returnRepo->findAllByOrder($orderId);
        } catch (\Throwable) {
            $returns = [];
        }

        // Gift-card purchase orders carry no real items; resolve the linked
        // card (one lookup, only when there are no real items) so the serializer
        // synthesizes the "Gift Card" line, matching the orders list + the
        // customer order detail.
        $giftCard = null;
        if ($order->getItems()->isEmpty()) {
            /** @var GiftCardRepository $giftCards */
            $giftCards = $this->em->getRepository(GiftCard::class);
            $giftCard = $giftCards->findByPurchaseOrderReference($order->getOrderReference());
        }

        $shape = $this->serializer->adminDetailShape($order, $returns, $giftCard);

        // The customer's saved body measurements (profile) so admins see the
        // same authoritative set the vendor fulfils against, independent of the
        // per-item `measurement`/`extra_measurement` snapshot, which is only
        // captured for custom-size lines (and absent on legacy-migrated orders).
        /** @var MeasurementRepository $measurementRepo */
        $measurementRepo = $this->em->getRepository(Measurement::class);
        $shape['customer_measurements'] = $this->measurementSerializer->publicShapeMany(
            $measurementRepo->findAllForUser($order->getUser()),
        );

        return $this->ok(['order' => $shape]);
    }
}
