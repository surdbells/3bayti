<?php

declare(strict_types=1);

namespace Bayti\Api\Http\Controllers\Order;

use Bayti\Api\Domain\Order\Order;
use Bayti\Api\Domain\Order\OrderRepository;
use Bayti\Api\Domain\User\User;
use Bayti\Api\Http\Errors\ErrorCodes;
use Bayti\Api\Http\Errors\HttpException;
use Bayti\Api\Http\Middleware\AuthMiddleware;
use Bayti\Api\Http\Responder;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * DELETE /v3/orders/{id}
 *
 * Customer self-serve removal of a FAILED order from their own history. It's
 * a soft-delete (Order::softDelete → deleted_at): the row is kept for
 * records/audit but filtered out of the customer's list + detail
 * (OrderRepository).
 *
 * Only 'failed' orders can be removed — they never took payment, so there is
 * nothing financial to preserve for the customer. Any other status returns
 * 422 (a paid/delivered order can't be "deleted"; a pending one should be
 * cancelled or paid instead).
 *
 * Authorization:
 *   - Order must belong to the authenticated user (findForUser)
 *   - 404 (not 403) for cross-user / unknown / already-removed, to avoid
 *     leaking the existence of other users' orders.
 */
final class DeleteOrderController
{
    use Responder;

    public function __construct(
        protected readonly ResponseFactoryInterface $responseFactory,
        private readonly EntityManagerInterface $em,
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

        if ($order->getStatus() !== Order::STATUS_FAILED) {
            throw new HttpException(
                status: 422,
                errorCode: 'order_not_deletable',
                publicMessage: 'Only failed orders can be removed.',
                details: ['current_status' => $order->getStatus()],
            );
        }

        $order->softDelete();
        $this->em->flush();

        return $this->ok([
            'deleted' => true,
            'id' => $orderId,
        ]);
    }
}
