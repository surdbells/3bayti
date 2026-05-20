<?php

declare(strict_types=1);

namespace Bayti\Api\Http\Controllers\Admin\Order;

use Bayti\Api\Domain\Audit\AuditEmitter;
use Bayti\Api\Domain\Order\Order;
use Bayti\Api\Domain\Order\OrderRepository;
use Bayti\Api\Domain\Payment\PaymentTransaction;
use Bayti\Api\Domain\Payment\PaymentTransactionRepository;
use Bayti\Api\Domain\User\User;
use Bayti\Api\Http\Controllers\Admin\Order\Dto\RefundOrderInput;
use Bayti\Api\Http\Errors\ErrorCodes;
use Bayti\Api\Http\Errors\HttpException;
use Bayti\Api\Http\Middleware\AuthMiddleware;
use Bayti\Api\Http\Responder;
use Bayti\Api\Http\Serializers\OrderSerializer;
use Bayti\Api\Http\Validator\RequestValidator;
use Bayti\Api\Payment\PaymentGatewayException;
use Bayti\Api\Payment\PaymentGatewayInterface;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Log\LoggerInterface;

/**
 * POST /v3/admin/orders/{id}/refund
 *
 * Issue a full or partial refund against an existing paid order.
 *
 * Body: { "amount": "29.99"?, "reason": "..." }
 *   - amount: optional decimal string. If null/omitted, refunds the
 *     full remaining amount (paid - already_refunded).
 *   - reason: required; recorded in the audit + transaction log.
 *
 * Flow:
 *   1. Find order via findByIdForAdmin (404 if not found)
 *   2. Refundable balance = order.total - sum(prior refunds)
 *   3. Validate amount > 0 AND amount <= refundable balance
 *      (422 with allowed_max if over)
 *   4. Look up provider_order_ref from INITIATE transaction
 *   5. Call gateway.refund(provider_ref, amount, currency, reason)
 *   6. On gateway success: persist PaymentTransaction(REFUND)
 *      with the gateway's response. Mark order REFUNDED if
 *      cumulative refunds now equal the total.
 *   7. On gateway failure: persist PaymentTransaction with failed
 *      status (forensic record) + return 502 to caller
 *   8. Audit ACTION_OVERRIDDEN with amount + reason
 *
 * Idempotency: each refund request generates a unique idempotency_key.
 * Re-issuing the same body produces a new refund (not deduped at HTTP
 * layer) — admins should be careful. The gateway itself may dedupe.
 */
final class RefundOrderController
{
    use Responder;

    public function __construct(
        protected readonly ResponseFactoryInterface $responseFactory,
        private readonly RequestValidator $validator,
        private readonly EntityManagerInterface $em,
        private readonly OrderSerializer $serializer,
        private readonly AuditEmitter $audit,
        private readonly PaymentGatewayInterface $gateway,
        private readonly \Bayti\Api\Notification\OrderNotificationService $notifications,
        private readonly \Bayti\Api\Notification\Push\PushNotificationService $pushNotifications,
        private readonly LoggerInterface $logger,
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

        $input = $this->validator->parse($request, RefundOrderInput::class);
        $reason = $input->reason ?? '';

        /** @var OrderRepository $orderRepo */
        $orderRepo = $this->em->getRepository(Order::class);
        $order = $orderRepo->findByIdForAdmin($orderId);
        if ($order === null) {
            throw HttpException::notFound('Order not found.');
        }

        // Order must be in a state where refund is meaningful.
        if (!$this->isRefundable($order)) {
            throw new HttpException(
                status: 422,
                errorCode: 'order_not_refundable',
                publicMessage: "Order in status '{$order->getStatus()}' cannot be refunded.",
                details: ['current_status' => $order->getStatus()],
            );
        }

        /** @var PaymentTransactionRepository $txnRepo */
        $txnRepo = $this->em->getRepository(PaymentTransaction::class);

        // Compute refundable balance
        $alreadyRefunded = $txnRepo->sumRefundsForOrder($order);
        $orderTotal = $order->getTotal();
        $refundable = bcsub($orderTotal, $alreadyRefunded, 2);

        if (bccomp($refundable, '0.00', 2) <= 0) {
            throw new HttpException(
                status: 422,
                errorCode: 'no_refundable_balance',
                publicMessage: 'Order has already been fully refunded.',
                details: [
                    'order_total' => $orderTotal,
                    'already_refunded' => $alreadyRefunded,
                ],
            );
        }

        // Determine refund amount: null body amount means full remaining
        $requestedAmount = $input->amount;
        if ($requestedAmount === null || $requestedAmount === '') {
            $amount = $refundable;
        } else {
            // Normalize to 2dp string (bcadd ensures consistent format)
            $amount = bcadd($requestedAmount, '0.00', 2);

            if (bccomp($amount, '0.00', 2) <= 0) {
                throw new HttpException(
                    status: 422,
                    errorCode: 'invalid_refund_amount',
                    publicMessage: 'Refund amount must be greater than zero.',
                    details: ['amount' => $amount],
                );
            }

            if (bccomp($amount, $refundable, 2) > 0) {
                throw new HttpException(
                    status: 422,
                    errorCode: 'refund_exceeds_balance',
                    publicMessage: 'Refund amount exceeds the refundable balance.',
                    details: [
                        'requested' => $amount,
                        'refundable' => $refundable,
                        'order_total' => $orderTotal,
                        'already_refunded' => $alreadyRefunded,
                    ],
                );
            }
        }

        // Look up provider_order_ref from the INITIATE transaction
        $initiate = $txnRepo->findLatestInitiateForOrder($order);
        if ($initiate === null) {
            throw new HttpException(
                status: 422,
                errorCode: 'no_initiate_transaction',
                publicMessage: 'Order has no payment record; cannot refund.',
            );
        }
        $providerOrderRef = $initiate->getProviderOrderRef();
        if ($providerOrderRef === null || $providerOrderRef === '') {
            throw new HttpException(
                status: 422,
                errorCode: 'no_provider_order_ref',
                publicMessage: 'Order has no provider reference; cannot refund.',
            );
        }

        // Generate idempotency key for this refund attempt
        $idempotencyKey = bin2hex(random_bytes(16));

        $previousOrderStatus = $order->getStatus();

        // Call the gateway
        try {
            $gatewayResponse = $this->gateway->refund(
                providerOrderRef: $providerOrderRef,
                amount: $amount,
                currency: $order->getCurrency(),
                reason: $reason,
            );
        } catch (PaymentGatewayException $e) {
            // Record the failed attempt for forensics
            $failedTxn = new PaymentTransaction(
                order: $order,
                operation: PaymentTransaction::OPERATION_REFUND,
                status: 'Failed',
                amount: $amount,
                idempotencyKey: $idempotencyKey,
                providerOrderRef: $providerOrderRef,
                currency: $order->getCurrency(),
                responsePayload: ['error' => $e->getMessage(), 'kind' => $e->kind],
            );
            $txnRepo->save($failedTxn);

            $this->logger->error('admin.refund.gateway_failed', [
                'order_id' => $order->getId(),
                'amount' => $amount,
                'error' => $e->getMessage(),
                'kind' => $e->kind,
            ]);

            throw new HttpException(
                status: 502,
                errorCode: 'gateway_refund_failed',
                publicMessage: 'Payment gateway rejected the refund.',
                details: ['gateway_error' => $e->getMessage()],
            );
        }

        // Persist successful refund transaction
        $refundTxn = new PaymentTransaction(
            order: $order,
            operation: PaymentTransaction::OPERATION_REFUND,
            status: 'Refunded',
            amount: $amount,
            idempotencyKey: $idempotencyKey,
            providerOrderRef: $providerOrderRef,
            currency: $order->getCurrency(),
            responsePayload: $gatewayResponse->rawResponse,
        );
        $txnRepo->save($refundTxn);

        // Roll order to REFUNDED if cumulative refunds now equal total
        $newTotalRefunded = bcadd($alreadyRefunded, $amount, 2);
        $isFullRefund = bccomp($newTotalRefunded, $orderTotal, 2) >= 0;

        if ($isFullRefund && $order->getStatus() !== Order::STATUS_REFUNDED) {
            $order->overrideStatus(Order::STATUS_REFUNDED);
            $this->em->flush();
        }

        // Audit with full context
        $this->audit->recordOverride(
            request: $request,
            actor: $user,
            subject: $order,
            changes: [
                'before' => [
                    'status' => $previousOrderStatus,
                    'already_refunded' => $alreadyRefunded,
                ],
                'after' => [
                    'status' => $order->getStatus(),
                    'total_refunded' => $newTotalRefunded,
                ],
                'refund_amount' => $amount,
                'is_full_refund' => $isFullRefund,
                'reason' => $reason,
                'idempotency_key' => $idempotencyKey,
            ],
        );

        $this->logger->info('admin.refund.success', [
            'order_id' => $order->getId(),
            'amount' => $amount,
            'is_full_refund' => $isFullRefund,
            'actor_user_id' => $user->getId(),
        ]);

        // M3.1.7-H — notify customer of the refund. Fire-and-forget;
        // email failure must not block the response or the audit row.
        $this->notifications->orderRefunded($order, [
            'refund_amount' => $amount,
            'is_full_refund' => $isFullRefund,
        ]);
        $this->pushNotifications->orderRefunded($order);

        return $this->ok([
            'order' => $this->serializer->detailShape($order),
            'refund' => [
                'amount' => $amount,
                'currency' => $order->getCurrency(),
                'total_refunded' => $newTotalRefunded,
                'order_total' => $orderTotal,
                'is_full_refund' => $isFullRefund,
            ],
        ]);
    }

    /**
     * Whether an order is in a state where refund makes sense.
     * Refundable: PAID, FULFILLING, SHIPPED, DELIVERED, REFUNDED
     *   (REFUNDED allowed for partial-refund top-ups; balance check
     *    will reject if no remaining balance)
     * Not refundable: PENDING_PAYMENT, CANCELLED, FAILED
     *   (no money moved)
     */
    private function isRefundable(Order $order): bool
    {
        return in_array($order->getStatus(), [
            Order::STATUS_PAID,
            Order::STATUS_FULFILLING,
            Order::STATUS_SHIPPED,
            Order::STATUS_DELIVERED,
            Order::STATUS_REFUNDED,
        ], true);
    }
}
