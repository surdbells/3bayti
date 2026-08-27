<?php

declare(strict_types=1);

namespace Bayti\Api\Http\Controllers\Order;

use Bayti\Api\Domain\Order\Order;
use Bayti\Api\Domain\Order\OrderRepository;
use Bayti\Api\Domain\Order\OrderReturnRequest;
use Bayti\Api\Domain\Order\OrderReturnRequestRepository;
use Bayti\Api\Domain\User\User;
use Bayti\Api\Http\Errors\ErrorCodes;
use Bayti\Api\Http\Errors\HttpException;
use Bayti\Api\Http\Middleware\AuthMiddleware;
use Bayti\Api\Http\Responder;
use Bayti\Api\Http\Serializers\ReturnRequestSerializer;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * GET /v3/orders/{id}/returns
 *
 * Returns the customer's return requests for one of their orders.
 * Newest first. Not paginated, a customer's per-order return
 * count is bounded (most orders → zero returns; rare to see > 5
 * for a single order).
 *
 * Authorization: customer must own the order. 404 on cross-user.
 *
 * Response shape:
 *   { data: [ ...customer-shape return ], meta: { total: N } }
 *
 * No filtering args, this is a simple "show me my returns for
 * THIS order" view. The admin list endpoint (X.18-F) handles the
 * filter set for cross-order admin queries.
 */
final class ListCustomerReturnsController
{
    use Responder;

    public function __construct(
        protected readonly ResponseFactoryInterface $responseFactory,
        private readonly EntityManagerInterface $em,
        private readonly ReturnRequestSerializer $serializer,
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

        /** @var OrderRepository $orderRepo */
        $orderRepo = $this->em->getRepository(Order::class);
        $order = $orderRepo->findForUser($orderId, $user);
        if ($order === null) {
            throw HttpException::notFound('Order not found.');
        }

        /** @var OrderReturnRequestRepository $returnRepo */
        $returnRepo = $this->em->getRepository(OrderReturnRequest::class);
        $returns = $returnRepo->findForCustomerByOrder($user, $orderId);

        return $this->ok([
            'data' => $this->serializer->customerShapeMany($returns),
            'meta' => ['total' => count($returns)],
        ]);
    }
}
