<?php

declare(strict_types=1);

namespace Bayti\Api\Domain\Order;

use Bayti\Api\Domain\Audit\AuditEmitter;
use Bayti\Api\Domain\Payment\PaymentTransaction;
use Bayti\Api\Domain\Payment\PaymentTransactionRepository;
use Bayti\Api\Domain\User\User;
use Bayti\Api\Payment\PaymentGatewayException;
use Bayti\Api\Payment\PaymentGatewayInterface;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Log\LoggerInterface;

/**
 * Cancellation policy + execution for orders.
 *
 * Two callers use this service:
 *   - admin cancel:    POST /v3/admin/orders/{id}/cancel
 *   - customer cancel: POST /v3/orders/{id}/cancel (pending_payment only)
 *
 * Why a service (vs. inline in each controller):
 * The cancel logic is non-trivial: state-machine validation, optional
 * auto-refund, audit emission, transactional persist. Duplicating it
 * across two controllers would invite drift between admin and
 * customer paths. The service owns the policy; controllers just
 * marshal the request.
 *
 * State machine (cancellation policy)
 * -----------------------------------
 *   pending_payment   → cancel locally, NO Noon call (money never moved)
 *   paid / fulfilling → cancel + auto-refund through Noon
 *   shipped / delivered → REJECTED (must use returns/refunds flow)
 *   cancelled         → idempotent no-op (return current state)
 *   refunded          → REJECTED (already terminal)
 *   failed            → REJECTED (never paid, no need to cancel)
 *
 * Auto-refund failure handling
 * ----------------------------
 * If the gateway refund call fails during a paid/fulfilling cancel,
 * the order is LEFT in its original state — we don't half-cancel.
 * The failed refund attempt is still recorded for forensics (same as
 * the standalone refund controller does). Caller sees a 502 with
 * the gateway error; can retry.
 *
 * Idempotency
 * -----------
 * Cancelling an already-cancelled order returns the same response
 * shape as a fresh cancel — no error. This keeps mobile retry-on-
 * timeout safe.
 */
final class CancelOrderService
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly PaymentGatewayInterface $gateway,
        private readonly AuditEmitter $audit,
        private readonly \Bayti\Api\Notification\OrderNotificationService $notifications,
        private readonly \Bayti\Api\Notification\Push\PushNotificationService $pushNotifications,
        private readonly LoggerInterface $logger,
    ) {
    }

    /**
     * Cancel the given order. Returns a result describing what happened.
     *
     * @param Order $order The order to cancel.
     * @param User $actor The user initiating the cancel (admin or customer).
     * @param ServerRequestInterface $request For IP/UA/request-id capture.
     * @param string $reason Human-readable reason (audit + refund metadata).
     * @param bool $allowedFromAdmin When true, paid/fulfilling cancels trigger
     *                              auto-refund. When false (customer self-serve),
     *                              only pending_payment is allowed; other states
     *                              throw a CancellationNotAllowedException for
     *                              the caller to translate to 422.
     *
     * @throws CancellationNotAllowedException When the order's status doesn't
     *         permit cancellation (e.g. shipped/delivered, or paid when actor
     *         isn't admin).
     * @throws CancellationGatewayException When the auto-refund call to Noon
     *         fails. The order is left in its original state.
     */
    public function cancel(
        Order $order,
        User $actor,
        ServerRequestInterface $request,
        string $reason,
        bool $allowedFromAdmin,
    ): CancelOrderResult {
        $currentStatus = $order->getStatus();

        // Idempotency: already cancelled → return current state
        if ($currentStatus === Order::STATUS_CANCELLED) {
            return new CancelOrderResult(
                order: $order,
                wasAlreadyCancelled: true,
                refundIssued: false,
                refundAmount: null,
            );
        }

        // Terminal states that aren't cancellable
        if (in_array($currentStatus, [
            Order::STATUS_REFUNDED,
            Order::STATUS_FAILED,
        ], true)) {
            throw new CancellationNotAllowedException(
                "Order in status '{$currentStatus}' cannot be cancelled.",
                $currentStatus,
            );
        }

        // Post-shipment: returns/refunds flow only
        if (in_array($currentStatus, [
            Order::STATUS_SHIPPED,
            Order::STATUS_DELIVERED,
        ], true)) {
            throw new CancellationNotAllowedException(
                "Order in status '{$currentStatus}' has shipped; use the refund flow instead of cancellation.",
                $currentStatus,
            );
        }

        // Pending payment: simple local cancel, no Noon call.
        if ($currentStatus === Order::STATUS_PENDING_PAYMENT) {
            $order->overrideStatus(Order::STATUS_CANCELLED);

            $this->audit->recordOverride(
                request: $request,
                actor: $actor,
                subject: $order,
                changes: [
                    'before' => ['status' => $currentStatus],
                    'after' => ['status' => Order::STATUS_CANCELLED],
                    'reason' => $reason,
                    'auto_refund' => false,
                    'actor_type' => $allowedFromAdmin ? 'admin' : 'customer',
                ],
            );

            $this->em->flush();

            $this->logger->info('order.cancelled', [
                'order_id' => $order->getId(),
                'order_reference' => $order->getOrderReference(),
                'previous_status' => $currentStatus,
                'actor_user_id' => $actor->getId(),
                'auto_refund' => false,
            ]);

            // M3.1.7-H — notify customer + vendors. Fire-and-forget.
            $this->notifications->orderCancelled($order, [
                'refund_issued' => false,
                'refund_amount' => null,
                'reason' => $reason,
            ]);
            $this->pushNotifications->orderCancelled($order);

            return new CancelOrderResult(
                order: $order,
                wasAlreadyCancelled: false,
                refundIssued: false,
                refundAmount: null,
            );
        }

        // Paid or fulfilling — admin only, with auto-refund
        if (!$allowedFromAdmin) {
            throw new CancellationNotAllowedException(
                "Order in status '{$currentStatus}' has been paid; contact support to cancel.",
                $currentStatus,
            );
        }

        // Auto-refund path: requires admin authority + provider_order_ref
        /** @var PaymentTransactionRepository $txnRepo */
        $txnRepo = $this->em->getRepository(PaymentTransaction::class);
        $initiate = $txnRepo->findLatestInitiateForOrder($order);
        if ($initiate === null) {
            throw new CancellationNotAllowedException(
                'Order has no payment record; cannot auto-refund for cancellation.',
                $currentStatus,
            );
        }
        $providerOrderRef = $initiate->getProviderOrderRef();
        if ($providerOrderRef === null || $providerOrderRef === '') {
            throw new CancellationNotAllowedException(
                'Order has no provider reference; cannot auto-refund.',
                $currentStatus,
            );
        }

        // Compute remaining refundable balance (defensive — a partial
        // refund may have happened previously)
        $alreadyRefunded = $txnRepo->sumRefundsForOrder($order);
        $orderTotal = $order->getTotal();
        $refundable = bcsub($orderTotal, $alreadyRefunded, 2);

        $idempotencyKey = bin2hex(random_bytes(16));
        $refundIssued = false;
        $refundAmount = null;

        if (bccomp($refundable, '0.00', 2) > 0) {
            // Try the auto-refund
            try {
                $gatewayResponse = $this->gateway->refund(
                    providerOrderRef: $providerOrderRef,
                    amount: $refundable,
                    currency: $order->getCurrency(),
                    reason: "Cancellation: {$reason}",
                );
            } catch (PaymentGatewayException $e) {
                // Record failed refund attempt + bail (do NOT cancel
                // the order — leave it in its original state)
                $failedTxn = new PaymentTransaction(
                    order: $order,
                    operation: PaymentTransaction::OPERATION_REFUND,
                    status: 'Failed',
                    amount: $refundable,
                    idempotencyKey: $idempotencyKey,
                    providerOrderRef: $providerOrderRef,
                    currency: $order->getCurrency(),
                    responsePayload: [
                        'error' => $e->getMessage(),
                        'kind' => $e->kind,
                        'context' => 'cancellation_auto_refund',
                    ],
                );
                $txnRepo->save($failedTxn);

                $this->logger->error('order.cancel.auto_refund_failed', [
                    'order_id' => $order->getId(),
                    'amount' => $refundable,
                    'error' => $e->getMessage(),
                    'kind' => $e->kind,
                ]);

                throw new CancellationGatewayException(
                    "Auto-refund failed during cancellation: {$e->getMessage()}",
                    $e,
                );
            }

            // Record successful refund transaction
            $refundTxn = new PaymentTransaction(
                order: $order,
                operation: PaymentTransaction::OPERATION_REFUND,
                status: 'Refunded',
                amount: $refundable,
                idempotencyKey: $idempotencyKey,
                providerOrderRef: $providerOrderRef,
                currency: $order->getCurrency(),
                responsePayload: $gatewayResponse->rawResponse,
            );
            $txnRepo->save($refundTxn);

            $refundIssued = true;
            $refundAmount = $refundable;
        }

        // Move order to CANCELLED (use overrideStatus to bypass any
        // transition validation — we already validated via this method)
        $order->overrideStatus(Order::STATUS_CANCELLED);

        $this->audit->recordOverride(
            request: $request,
            actor: $actor,
            subject: $order,
            changes: [
                'before' => [
                    'status' => $currentStatus,
                    'already_refunded' => $alreadyRefunded,
                ],
                'after' => [
                    'status' => Order::STATUS_CANCELLED,
                    'refund_issued' => $refundIssued,
                    'refund_amount' => $refundAmount,
                ],
                'reason' => $reason,
                'auto_refund' => $refundIssued,
                'actor_type' => 'admin',
                'idempotency_key' => $idempotencyKey,
            ],
        );

        $this->em->flush();

        $this->logger->info('order.cancelled', [
            'order_id' => $order->getId(),
            'order_reference' => $order->getOrderReference(),
            'previous_status' => $currentStatus,
            'actor_user_id' => $actor->getId(),
            'auto_refund' => $refundIssued,
            'refund_amount' => $refundAmount,
        ]);

        // M3.1.7-H — notify customer + vendors of the cancellation.
        // Customer gets refund details if applicable. Fire-and-forget.
        $this->notifications->orderCancelled($order, [
            'refund_issued' => $refundIssued,
            'refund_amount' => $refundAmount,
            'reason' => $reason,
        ]);
        $this->pushNotifications->orderCancelled($order);

        return new CancelOrderResult(
            order: $order,
            wasAlreadyCancelled: false,
            refundIssued: $refundIssued,
            refundAmount: $refundAmount,
        );
    }
}
