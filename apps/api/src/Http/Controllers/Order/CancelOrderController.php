<?php

declare(strict_types=1);

namespace Bayti\Api\Http\Controllers\Order;

use Bayti\Api\Domain\Order\CancellationGatewayException;
use Bayti\Api\Domain\Order\CancellationNotAllowedException;
use Bayti\Api\Domain\Order\CancelOrderService;
use Bayti\Api\Domain\Order\Order;
use Bayti\Api\Domain\Order\OrderRepository;
use Bayti\Api\Domain\User\User;
use Bayti\Api\Http\Controllers\Order\Dto\CustomerCancelOrderInput;
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
 * POST /v3/orders/{id}/cancel
 *
 * Customer-self-serve cancellation. Only pending_payment orders can
 * be cancelled by the customer themselves; once paid, the customer
 * must contact support (admin path).
 *
 * Common scenarios:
 *   1. User opens checkout webview, then bails before completing.
 *      Order is pending_payment; we let them cancel cleanly so it
 *      doesn't sit in the reconciliation cron's view forever.
 *   2. User wants to cancel a paid order, they see a 422 with a
 *      message directing them to support. Mobile shows a "Contact
 *      support" CTA.
 *
 * Authorization:
 *   - Order must belong to the authenticated user (findForUser)
 *   - 404 (not 403) for cross-user attempts to prevent existence leak
 *
 * Audit: emits ACTION_OVERRIDDEN, actor_type='customer' for forensics.
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

        $input = $this->validator->parse($request, CustomerCancelOrderInput::class);

        /** @var OrderRepository $orderRepo */
        $orderRepo = $this->em->getRepository(Order::class);
        // Scope to the authenticated user, cross-user 404 (not 403)
        $order = $orderRepo->findForUser($orderId, $user);
        if ($order === null) {
            throw HttpException::notFound('Order not found.');
        }

        try {
            $result = $this->cancelService->cancel(
                order: $order,
                actor: $user,
                request: $request,
                reason: $input->reason ?? '',
                allowedFromAdmin: false, // customer path, only pending_payment is allowed
            );
        } catch (CancellationNotAllowedException $e) {
            throw new HttpException(
                status: 422,
                errorCode: 'cancellation_not_allowed',
                publicMessage: $e->getMessage(),
                details: ['current_status' => $e->currentStatus],
            );
        } catch (CancellationGatewayException $e) {
            // Shouldn't happen on customer path (no auto-refund) but
            // defensive, translate to 502 just in case.
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
