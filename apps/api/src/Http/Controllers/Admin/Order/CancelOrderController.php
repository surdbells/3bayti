<?php

declare(strict_types=1);

namespace Bayti\Api\Http\Controllers\Admin\Order;

use Bayti\Api\Domain\Order\CancellationGatewayException;
use Bayti\Api\Domain\Order\CancellationNotAllowedException;
use Bayti\Api\Domain\Order\CancelOrderService;
use Bayti\Api\Domain\Order\Order;
use Bayti\Api\Domain\Order\OrderRepository;
use Bayti\Api\Domain\User\User;
use Bayti\Api\Http\Controllers\Admin\Order\Dto\CancelOrderInput;
use Bayti\Api\Http\Errors\ErrorCodes;
use Bayti\Api\Http\Errors\HttpException;
use Bayti\Api\Http\Middleware\AuthMiddleware;
use Bayti\Api\Http\Responder;
use Bayti\Api\Http\Serializers\OrderSerializer;
use Bayti\Api\Http\Validator\RequestValidator;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * POST /v3/admin/orders/{id}/cancel
 *
 * Body: { "reason": "..." }
 *
 * Admin-driven cancel. Behavior depends on order's current status:
 *
 *   pending_payment  → cancel locally, no Noon call
 *   paid / fulfilling → cancel + auto-refund any remaining balance
 *   shipped / delivered → 422 (must use returns/refunds flow)
 *   cancelled        → 200 idempotent (returns current state)
 *   refunded / failed → 422 (already terminal)
 *
 * Mounted under AdminAuthMiddleware → caller is guaranteed admin.
 *
 * Audit: emits ACTION_OVERRIDDEN with full diff (previous status,
 * refund issued flag + amount, reason). Q5=A locks ALL admin
 * actions for forensic capture.
 *
 * Response shape:
 *   {
 *     "order": { ...detail shape... },
 *     "cancellation": {
 *       "was_already_cancelled": false,
 *       "refund_issued": true,
 *       "refund_amount": "299.00" | null
 *     }
 *   }
 */
final class CancelOrderController
{
    use Responder;

    public function __construct(
        protected readonly ResponseFactoryInterface $responseFactory,
        private readonly RequestValidator $validator,
        private readonly EntityManagerInterface $em,
        private readonly OrderSerializer $serializer,
        private readonly CancelOrderService $cancelService,
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

        $input = $this->validator->parse($request, CancelOrderInput::class);

        /** @var OrderRepository $orderRepo */
        $orderRepo = $this->em->getRepository(Order::class);
        $order = $orderRepo->findByIdForAdmin($orderId);
        if ($order === null) {
            throw HttpException::notFound('Order not found.');
        }

        try {
            $result = $this->cancelService->cancel(
                order: $order,
                actor: $user,
                request: $request,
                reason: $input->reason ?? '',
                allowedFromAdmin: true,
            );
        } catch (CancellationNotAllowedException $e) {
            throw new HttpException(
                status: 422,
                errorCode: 'cancellation_not_allowed',
                publicMessage: $e->getMessage(),
                details: ['current_status' => $e->currentStatus],
            );
        } catch (CancellationGatewayException $e) {
            throw new HttpException(
                status: 502,
                errorCode: 'cancellation_gateway_failed',
                publicMessage: $e->getMessage(),
            );
        }

        return $this->ok([
            'order' => $this->serializer->detailShape($result->order),
            'cancellation' => [
                'was_already_cancelled' => $result->wasAlreadyCancelled,
                'refund_issued' => $result->refundIssued,
                'refund_amount' => $result->refundAmount,
            ],
        ]);
    }
}
