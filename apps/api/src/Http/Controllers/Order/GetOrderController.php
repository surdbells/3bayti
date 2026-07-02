<?php

declare(strict_types=1);

namespace Bayti\Api\Http\Controllers\Order;

use Bayti\Api\Domain\GiftCard\GiftCard;
use Bayti\Api\Domain\GiftCard\GiftCardRepository;
use Bayti\Api\Domain\Order\Order;
use Bayti\Api\Domain\Order\OrderRepository;
use Bayti\Api\Domain\Order\OrderReturnRequest;
use Bayti\Api\Domain\Order\OrderReturnRequestRepository;
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
 * GET /v3/orders/{id}
 *
 * Returns a single order's full detail (items + billing + shipping
 * addresses). Replaces the legacy customer/read-order-details
 * endpoint.
 *
 * Authorization: cross-tenant access returns 404 (not 403) to
 * avoid leaking order existence. OrderRepository::findForUser
 * enforces the user_id filter at the query level.
 */
final class GetOrderController
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
     * @param array<string, string> $args
     */
    public function __invoke(
        ServerRequestInterface $request,
        ResponseInterface $response,
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
        if ($orderId < 1) {
            throw HttpException::notFound('Order not found.');
        }

        /** @var OrderRepository $orders */
        $orders = $this->em->getRepository(Order::class);
        $order = $orders->findForUser($orderId, $user);
        if ($order === null) {
            throw HttpException::notFound('Order not found.');
        }

        // M3.2.X.18-H — Embed return summaries inline so the mobile
        // order-detail screen can show a "Returns" section without
        // a follow-up API call. Customer-scoped query (own returns
        // only); falls back to empty list if the table doesn't yet
        // exist (test setups without the X.18 migration).
        $returns = [];
        try {
            /** @var OrderReturnRequestRepository $returnRepo */
            $returnRepo = $this->em->getRepository(OrderReturnRequest::class);
            $returns = $returnRepo->findForCustomerByOrder($user, $orderId);
        } catch (\Throwable) {
            // Defensive: never let returns lookup block the primary
            // order-detail response. The mobile UI degrades to
            // "no Returns section" on an empty list, which is the
            // right call here.
            $returns = [];
        }

        // Gift-card purchase orders carry no real items; resolve the
        // linked card (one lookup) so the serializer can synthesize the
        // "Gift Card" line. Only worth looking up when there are no real
        // items — a normal order never has a linked purchase card.
        $giftCard = null;
        if ($order->getItems()->isEmpty()) {
            /** @var GiftCardRepository $giftCards */
            $giftCards = $this->em->getRepository(GiftCard::class);
            $giftCard = $giftCards->findByPurchaseOrderReference($order->getOrderReference());
        }

        return $this->ok([
            'order' => $this->serializer->detailShape($order, $returns, $giftCard),
        ]);
    }
}
