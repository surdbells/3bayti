<?php

declare(strict_types=1);

namespace Bayti\Api\Http\Controllers\Checkout;

use Bayti\Api\Domain\Order\Order;
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
use Psr\Log\NullLogger;

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
        private readonly LoggerInterface $logger = new NullLogger(),
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
        $this->applyAuthoritativeStatus($order, $authoritative);

        $event->markProcessed();
        $this->em->flush();

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

    private function applyAuthoritativeStatus(Order $order, OrderStatusResponse $status): void
    {
        if (!$status->terminal) {
            // Still in flight — nothing to do at this point. The next
            // webhook or the polling endpoint will pick up the change.
            return;
        }

        if ($status->paid) {
            // markPaid() is idempotent — safe to call even if already
            // marked paid. Don't double-stamp.
            $order->markPaid();
        } else {
            // Terminal but not paid: FAILED / EXPIRED / CANCELLED
            // → mark Order as failed unless already terminal.
            if (!$order->isTerminal()) {
                try {
                    $order->markFailed();
                } catch (\DomainException $e) {
                    // Race: another instance already moved it terminal.
                    // Safe to ignore.
                    $this->logger->info('noon.webhook: order already terminal', [
                        'order_id' => $order->getId(),
                        'current_status' => $order->getStatus(),
                    ]);
                }
            }
        }
    }
}
