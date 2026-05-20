<?php

declare(strict_types=1);

namespace Bayti\Api\Http\Controllers\Checkout;

use Bayti\Api\Domain\Order\Order;
use Bayti\Api\Domain\Order\OrderDispute;
use Bayti\Api\Domain\Order\OrderDisputeRepository;
use Bayti\Api\Domain\Order\OrderRepository;
use Bayti\Api\Domain\Payment\PaymentTransaction;
use Bayti\Api\Domain\Payment\PaymentTransactionRepository;
use Bayti\Api\Domain\Payment\PaymentWebhookEvent;
use Bayti\Api\Domain\Payment\PaymentWebhookEventRepository;
use Bayti\Api\Http\Responder;
use Bayti\Api\Payment\Noon\NoonWebhookSignatureVerifier;
use Bayti\Api\Payment\OrderStatusResponse;
use Bayti\Api\Payment\PaymentGatewayException;
use Bayti\Api\Payment\PaymentGatewayInterface;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Log\LoggerInterface;

/**
 * POST /v3/payment/webhook/noon
 *
 * Receives Noon's server-to-server callbacks. Unauthenticated by JWT
 * (Noon doesn't have one of our user tokens); instead authenticated by:
 *
 *   1. Signature header verification (M3.1.6: LoggingOnlyVerifier
 *      accepts everything + logs hashes; M3.1.7: real algo)
 *   2. ** retrieve-order-before-acting ** — the load-bearing safety
 *      mechanism. Even with no signature check, a spoofed webhook
 *      cannot make us mark an order paid: we call gateway.retrieveOrder
 *      (server-to-server, authenticated with OUR merchant credentials)
 *      to ASK Noon what the true state is. The webhook is just a
 *      "look at order X again" poke; Noon's GET_ORDER response is
 *      the authority.
 *
 *      This pattern is explicitly endorsed by Noon's own docs
 *      (docs.noonpayments.com/test/evaluating-api).
 *
 * Idempotency
 * -----------
 * Noon retries failed deliveries — possibly with the same event_id
 * or with a fresh one but identical content. We dedupe via the
 * payment_webhook_events table's UNIQUE constraint on
 * (provider, idempotency_key). The idempotency_key is derived from
 * a hash of the body + signature header.
 *
 * Response
 * --------
 * Always 200 OK on successful receipt + processing, even if the
 * payload was malformed or the order wasn't found. We don't want
 * Noon to keep retrying because of OUR bug — log and accept.
 *
 * Only 401 is returned (when signature verification fails in
 * M3.1.7); 500 for our own crashes (Noon will retry, which is
 * fine — we'll hopefully be fixed by then).
 */
final class NoonWebhookController
{
    use Responder;

    public function __construct(
        protected readonly ResponseFactoryInterface $responseFactory,
        private readonly EntityManagerInterface $em,
        private readonly PaymentGatewayInterface $gateway,
        private readonly NoonWebhookSignatureVerifier $signatureVerifier,
        private readonly \Bayti\Api\Notification\OrderNotificationService $notifications,
        private readonly \Bayti\Api\Notification\Push\PushNotificationService $pushNotifications,
        // M3.2.X.5-A: required (no `= new NullLogger()` default) so PHP-DI's
        // autowire injects the bound LoggerInterface. The same pattern as
        // M3.2.X.8-D's PromoCodeResolverService: `useAttributes=false` means
        // nullable+default parameters are not auto-injected from bindings.
        private readonly LoggerInterface $logger,
    ) {
    }

    protected function getResponseFactory(): ResponseFactoryInterface
    {
        return $this->responseFactory;
    }

    public function __invoke(ServerRequestInterface $request): ResponseInterface
    {
        // Read the RAW body (signature is over the raw bytes, not
        // parsed JSON).
        $rawBody = (string) $request->getBody();

        $signatureHeader = $request->getHeaderLine('X-Noon-Signature');
        if ($signatureHeader === '') {
            // Try common alternates seen in similar providers.
            $signatureHeader = $request->getHeaderLine('Signature');
        }
        $signatureHeader = $signatureHeader !== '' ? $signatureHeader : null;

        // Signature check. M3.1.6 LoggingOnlyVerifier accepts everything;
        // M3.1.7 replaces with a strict verifier.
        if (!$this->signatureVerifier->verify($rawBody, $signatureHeader)) {
            $this->logger->warning('noon.webhook: signature verification failed', [
                'has_signature_header' => $signatureHeader !== null,
            ]);
            // 401 per webhook conventions. M3.1.6 won't reach this branch
            // (LoggingOnlyVerifier always returns true).
            return $this->responseFactory->createResponse(401);
        }

        $payload = $this->parsePayload($rawBody);

        // Derive idempotency key. Prefer Noon's eventId if present;
        // fall back to sha256(body) (covers retries that send identical
        // bodies but different event IDs).
        $idempotencyKey = $this->deriveIdempotencyKey($payload, $rawBody);

        /** @var PaymentWebhookEventRepository $events */
        $events = $this->em->getRepository(PaymentWebhookEvent::class);
        $existing = $events->findByIdempotencyKey($idempotencyKey);
        if ($existing !== null) {
            $this->logger->info('noon.webhook: duplicate (idempotency hit)', [
                'idempotency_key' => $idempotencyKey,
                'event_id' => $existing->getId(),
            ]);
            return $this->ok(['status' => 'duplicate']);
        }

        // Extract provider's order id + merchant reference from payload.
        $providerOrderRef = $this->extractProviderOrderRef($payload);
        $merchantReference = $this->extractMerchantReference($payload);
        $eventType = $this->extractEventType($payload);

        // Find the matching Order. Try provider_order_ref via
        // PaymentTransaction first (most reliable), fall back to
        // merchant_reference (order_reference column).
        $order = $this->findOrder($providerOrderRef, $merchantReference);

        // Persist the raw event for forensics. We do this BEFORE
        // calling Noon's GET_ORDER so a crash in the retrieve step
        // still leaves an audit trail.
        $event = new PaymentWebhookEvent(
            idempotencyKey: $idempotencyKey,
            payload: $payload,
            providerOrderRef: $providerOrderRef,
            eventType: $eventType,
            signatureHeader: $signatureHeader,
            signatureVerified: true, // LoggingOnlyVerifier returned true
            order: $order,
        );

        $events->save($event);

        // ------------------------------------------------------------------
        // M3.2.X.5-A — OBSERVABILITY: catch unknown dispute-shaped events.
        //
        // Our DISPUTE_EVENT_TYPES constant is based on Noon's API contract
        // docs, not empirical observation. If Noon ever sends a real
        // dispute eventType that DOESN'T match our list, the current code
        // would silently classify it as a non-dispute and skip dispute
        // persistence + admin notification.
        //
        // Defence: emit a warning log line for any eventType that
        // CONTAINS the substring 'dispute' or 'chargeback' (case-
        // insensitive) but isn't in our recognized list. This makes
        // future unknown dispute strings self-surface in production logs
        // and Sentry without breaking anything.
        //
        // Operator can run `apps/api/bin/audit-dispute-event-types.php`
        // against the production DB to retroactively check
        // payment_webhook_events for any dispute-shaped strings we
        // haven't yet added to the constant.
        // ------------------------------------------------------------------
        $this->emitDisputeShapedWarning($eventType, $idempotencyKey);

        // ------------------------------------------------------------------
        // DISPUTE DETECTION (M3.1.7-G)
        //
        // If the event looks like a chargeback/dispute, persist an
        // OrderDispute row. We do this BEFORE the order-null early-exit
        // so orphan disputes (event references an unknown provider
        // order ref) are still captured for manual operator review.
        //
        // Idempotency: indexed UNIQUE on provider_dispute_id. Webhook
        // retries find the existing row and no-op.
        //
        // NOTE: The exact eventType strings Noon emits for disputes
        // are NOT empirically confirmed at M3.1.7-G ship time. We
        // detect on the patterns currently documented in Noon's API
        // contract (CHARGEBACK_OPENED, DISPUTE_OPENED, CHARGEBACK_RECEIVED).
        // If sandbox or production observation reveals different
        // strings, update DISPUTE_EVENT_TYPES below.
        // ------------------------------------------------------------------
        if ($this->isDisputeEvent($eventType)) {
            $dispute = $this->persistDispute(
                eventType: $eventType ?? 'UNKNOWN_DISPUTE',
                providerOrderRef: $providerOrderRef,
                order: $order,
                payload: $payload,
            );
            // M3.1.7-H — notify admins. Only fires on FIRST sight of a
            // given dispute id (persistDispute returns null on
            // idempotent re-delivery). $order may be null for orphan
            // disputes; OrderNotificationService::disputeOpened
            // requires an Order, so we skip in that case.
            if ($dispute !== null && $order !== null) {
                $this->notifications->disputeOpened($order, [
                    'event_type' => $eventType ?? 'UNKNOWN_DISPUTE',
                    'amount' => $dispute->getAmount(),
                    'currency' => $dispute->getCurrency(),
                    'reason' => $dispute->getReason(),
                ]);
            }
        }

        if ($order === null) {
            $this->logger->warning('noon.webhook: no matching order', [
                'provider_order_ref' => $providerOrderRef,
                'merchant_reference' => $merchantReference,
                'idempotency_key' => $idempotencyKey,
            ]);
            // Always 200 — we don't want Noon to retry on our missing data
            return $this->ok(['status' => 'no_match']);
        }

        // ------------------------------------------------------------------
        // RETRIEVE-ORDER-BEFORE-ACTING — the load-bearing safety mechanism.
        //
        // We call gateway.retrieveOrder server-to-server (authenticated
        // with our merchant credentials) to confirm the true state with
        // Noon directly. The webhook body is informational, not
        // authoritative.
        // ------------------------------------------------------------------
        $authoritative = $this->retrieveAuthoritativeStatus($providerOrderRef, $merchantReference);
        if ($authoritative === null) {
            $this->logger->error('noon.webhook: retrieve-order failed; deferring action', [
                'order_id' => $order->getId(),
                'provider_order_ref' => $providerOrderRef,
            ]);
            // Persist the event without applying state. The polling
            // GetCheckoutStatusController will pick this up.
            return $this->ok(['status' => 'received_unconfirmed']);
        }

        // Apply state transition based on Noon's authoritative response.
        $transition = $this->applyAuthoritativeStatus($order, $authoritative);

        $event->markProcessed();
        $this->em->flush();

        // M3.1.7-H — fire lifecycle notifications AFTER the flush so
        // we never email about state that isn't committed. Idempotent
        // re-deliveries find $transition='' and skip notifying.
        if ($transition === 'paid') {
            $this->notifications->orderPaid($order);
            $this->pushNotifications->orderPaid($order);
        } elseif ($transition === 'failed') {
            $this->notifications->orderPaymentFailed($order);
            $this->pushNotifications->orderPaymentFailed($order);
        }

        return $this->ok([
            'status' => 'processed',
            'order_status' => $order->getStatus(),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function parsePayload(string $rawBody): array
    {
        if ($rawBody === '') {
            return [];
        }
        try {
            /** @var mixed $parsed */
            $parsed = json_decode($rawBody, true, 64, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return [];
        }
        if (!is_array($parsed)) {
            return [];
        }
        /** @var array<string, mixed> $parsed */
        return $parsed;
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function deriveIdempotencyKey(array $payload, string $rawBody): string
    {
        // Prefer Noon's eventId if present at top-level
        if (isset($payload['eventId']) && (is_string($payload['eventId']) || is_int($payload['eventId']))) {
            return 'noon:' . substr((string) $payload['eventId'], 0, 100);
        }
        // Fall back to sha256 of the raw body — collision-free for our purposes
        return 'noon:body:' . hash('sha256', $rawBody);
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function extractProviderOrderRef(array $payload): ?string
    {
        // Noon webhook payloads place the order id at result.order.id
        // (same path as the API response shape).
        $result = $payload['result'] ?? $payload;
        if (!is_array($result)) {
            return null;
        }
        $order = $result['order'] ?? null;
        if (!is_array($order)) {
            return null;
        }
        $id = $order['id'] ?? null;
        if (is_string($id) && $id !== '') {
            return $id;
        }
        if (is_int($id)) {
            return (string) $id;
        }
        return null;
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function extractMerchantReference(array $payload): ?string
    {
        $result = $payload['result'] ?? $payload;
        if (!is_array($result)) {
            return null;
        }
        $order = $result['order'] ?? null;
        if (!is_array($order)) {
            return null;
        }
        $ref = $order['reference'] ?? null;
        return is_string($ref) && $ref !== '' ? $ref : null;
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function extractEventType(array $payload): ?string
    {
        $type = $payload['eventType'] ?? ($payload['type'] ?? null);
        return is_string($type) && $type !== '' ? $type : null;
    }

    private function findOrder(?string $providerOrderRef, ?string $merchantReference): ?Order
    {
        /** @var OrderRepository $orders */
        $orders = $this->em->getRepository(Order::class);

        // First try via provider_order_ref → PaymentTransaction → Order
        if ($providerOrderRef !== null) {
            /** @var PaymentTransactionRepository $txs */
            $txs = $this->em->getRepository(PaymentTransaction::class);
            $tx = $txs->findByProviderOrderRef($providerOrderRef);
            if ($tx !== null) {
                return $tx->getOrder();
            }
        }

        // Fall back to merchant_reference (Order.order_reference)
        if ($merchantReference !== null) {
            return $orders->findByOrderReference($merchantReference);
        }

        return null;
    }

    private function retrieveAuthoritativeStatus(
        ?string $providerOrderRef,
        ?string $merchantReference,
    ): ?OrderStatusResponse {
        try {
            if ($providerOrderRef !== null) {
                return $this->gateway->retrieveOrder($providerOrderRef);
            }
            if ($merchantReference !== null) {
                return $this->gateway->retrieveOrderByReference($merchantReference);
            }
        } catch (PaymentGatewayException $e) {
            $this->logger->error('noon.webhook: retrieve-order gateway error', [
                'kind' => $e->kind,
                'provider_code' => $e->providerCode,
                'message' => $e->getMessage(),
            ]);
        }
        return null;
    }

    /**
     * Return values:
     *   'paid'   — order just transitioned to paid (newly)
     *   'failed' — order just transitioned to failed (newly)
     *   ''       — no transition (already paid, already terminal, or non-terminal)
     */
    private function applyAuthoritativeStatus(Order $order, OrderStatusResponse $status): string
    {
        if (!$status->terminal) {
            // Still in flight — nothing to do at this point. The next
            // webhook or the polling endpoint will pick up the change.
            return '';
        }

        if ($status->paid) {
            $previous = $order->getStatus();
            // markPaid() is idempotent — safe to call even if already
            // marked paid. Don't double-stamp.
            $order->markPaid();
            // Only notify on the FIRST paid transition; idempotent
            // re-deliveries find the order already paid.
            return $previous === Order::STATUS_PAID
                || $previous === Order::STATUS_FULFILLING
                || $previous === Order::STATUS_SHIPPED
                || $previous === Order::STATUS_DELIVERED
                ? ''
                : 'paid';
        }

        // Terminal but not paid: FAILED / EXPIRED / CANCELLED
        // → mark Order as failed unless already terminal.
        if (!$order->isTerminal()) {
            try {
                $previous = $order->getStatus();
                $order->markFailed();
                return $previous === Order::STATUS_FAILED ? '' : 'failed';
            } catch (\DomainException $e) {
                // Race: another instance already moved it terminal.
                // Safe to ignore.
                $this->logger->info('noon.webhook: order already terminal', [
                    'order_id' => $order->getId(),
                    'current_status' => $order->getStatus(),
                ]);
            }
        }
        return '';
    }

    /**
     * Noon eventType strings that correspond to dispute/chargeback
     * lifecycle events. The exact strings here are PLACEHOLDERS based
     * on Noon's API contract documentation; empirical sandbox observation
     * may reveal different values that should be merged in.
     *
     * Updating this list:
     *   1. Observe a real dispute event in sandbox or production
     *   2. Capture the actual eventType from payment_webhook_events.payload
     *   3. Add the new value to this array
     *   4. Backfill any existing order_disputes rows if needed
     */
    private const DISPUTE_EVENT_TYPES = [
        'CHARGEBACK_OPENED',
        'CHARGEBACK_RECEIVED',
        'DISPUTE_OPENED',
        'DISPUTE_RECEIVED',
    ];

    private function isDisputeEvent(?string $eventType): bool
    {
        if ($eventType === null || $eventType === '') {
            return false;
        }
        return in_array($eventType, self::DISPUTE_EVENT_TYPES, true);
    }

    /**
     * M3.2.X.5-A — Observability hook for unknown dispute-shaped events.
     *
     * Emits a warning log line when an eventType contains 'dispute' or
     * 'chargeback' (case-insensitive) but isn't in our recognized
     * DISPUTE_EVENT_TYPES list. Without this, an unrecognized real
     * dispute eventType would be silently classified as a non-dispute
     * and no OrderDispute row + no admin email would result.
     *
     * The warning includes the idempotency_key so operators can pull
     * the full payload from payment_webhook_events for manual triage.
     */
    private function emitDisputeShapedWarning(?string $eventType, string $idempotencyKey): void
    {
        if ($eventType === null || $eventType === '') {
            return;
        }
        if ($this->isDisputeEvent($eventType)) {
            return; // Already handled by the known list.
        }
        $lower = strtolower($eventType);
        if (str_contains($lower, 'dispute') || str_contains($lower, 'chargeback')) {
            $this->logger->warning('noon.webhook.unknown_dispute_event_type', [
                'event_type' => $eventType,
                'idempotency_key' => $idempotencyKey,
                'recognized_types' => self::DISPUTE_EVENT_TYPES,
                'action' => 'add this event_type to DISPUTE_EVENT_TYPES constant in NoonWebhookController if it is a real dispute event',
            ]);
        }
    }

    /**
     * Persist (or idempotently update) an OrderDispute row from a
     * webhook dispute event. Looks up by provider_dispute_id; creates
     * a new row if none exists.
     *
     * @param array<string, mixed> $payload
     */
    /**
     * Return value: the newly-created dispute, or NULL if the event
     * was a duplicate (idempotent re-delivery; no notification fires).
     *
     * @param array<string, mixed> $payload Full webhook payload
     */
    private function persistDispute(
        string $eventType,
        ?string $providerOrderRef,
        ?Order $order,
        array $payload,
    ): ?OrderDispute {
        $providerDisputeId = $this->extractProviderDisputeId($payload);

        /** @var OrderDisputeRepository $disputes */
        $disputes = $this->em->getRepository(OrderDispute::class);

        // Idempotency: re-delivered webhook with same dispute id → no-op.
        if ($providerDisputeId !== null) {
            $existing = $disputes->findByProviderDisputeId($providerDisputeId);
            if ($existing !== null) {
                $this->logger->info('noon.webhook: dispute already persisted', [
                    'dispute_id' => $existing->getId(),
                    'provider_dispute_id' => $providerDisputeId,
                    'event_type' => $eventType,
                ]);
                return null;
            }
        }

        $amount = $this->extractDisputeAmount($payload);
        $currency = $this->extractDisputeCurrency($payload);
        $reason = $this->extractDisputeReason($payload);

        $dispute = new OrderDispute(
            providerOrderRef: $providerOrderRef ?? '(unknown)',
            eventType: $eventType,
            rawEvent: $payload,
            order: $order,
            providerDisputeId: $providerDisputeId,
            amount: $amount,
            currency: $currency,
            reason: $reason,
        );
        $disputes->save($dispute);

        $this->logger->info('noon.webhook: dispute persisted', [
            'dispute_id' => $dispute->getId(),
            'order_id' => $order?->getId(),
            'event_type' => $eventType,
            'amount' => $amount,
            'provider_dispute_id' => $providerDisputeId,
        ]);

        return $dispute;
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function extractProviderDisputeId(array $payload): ?string
    {
        // Common Noon payload shapes for dispute id:
        //   payload.disputeId / payload.dispute_id
        //   payload.order.disputeId
        //   payload.dispute.id
        $candidates = [
            $payload['disputeId'] ?? null,
            $payload['dispute_id'] ?? null,
        ];
        $order = $payload['order'] ?? null;
        if (is_array($order)) {
            $candidates[] = $order['disputeId'] ?? null;
        }
        $dispute = $payload['dispute'] ?? null;
        if (is_array($dispute)) {
            $candidates[] = $dispute['id'] ?? null;
        }

        foreach ($candidates as $c) {
            if (is_string($c) && $c !== '') {
                return substr($c, 0, 128); // truncate to column length
            }
        }
        return null;
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function extractDisputeAmount(array $payload): ?string
    {
        // Payload shapes:
        //   payload.dispute.amount
        //   payload.order.amount.value
        //   payload.amount
        $dispute = $payload['dispute'] ?? null;
        if (is_array($dispute)) {
            $a = $dispute['amount'] ?? null;
            if (is_numeric($a)) {
                return bcadd((string) $a, '0.00', 2);
            }
        }
        $order = $payload['order'] ?? null;
        if (is_array($order)) {
            $amt = $order['amount'] ?? null;
            if (is_array($amt) && isset($amt['value']) && is_numeric($amt['value'])) {
                return bcadd((string) $amt['value'], '0.00', 2);
            }
        }
        if (isset($payload['amount']) && is_numeric($payload['amount'])) {
            return bcadd((string) $payload['amount'], '0.00', 2);
        }
        return null;
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function extractDisputeCurrency(array $payload): ?string
    {
        $order = $payload['order'] ?? null;
        if (is_array($order)) {
            $amt = $order['amount'] ?? null;
            if (is_array($amt) && isset($amt['currency']) && is_string($amt['currency'])) {
                return substr($amt['currency'], 0, 3);
            }
            if (isset($order['currency']) && is_string($order['currency'])) {
                return substr($order['currency'], 0, 3);
            }
        }
        $dispute = $payload['dispute'] ?? null;
        if (is_array($dispute) && isset($dispute['currency']) && is_string($dispute['currency'])) {
            return substr($dispute['currency'], 0, 3);
        }
        return null;
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function extractDisputeReason(array $payload): ?string
    {
        $dispute = $payload['dispute'] ?? null;
        if (is_array($dispute)) {
            $r = $dispute['reason'] ?? ($dispute['description'] ?? null);
            if (is_string($r) && $r !== '') {
                return $r;
            }
        }
        if (isset($payload['reason']) && is_string($payload['reason'])) {
            return $payload['reason'];
        }
        return null;
    }
}
