<?php

declare(strict_types=1);

namespace Bayti\Api\Tests\Http\Controllers\Checkout;

use Bayti\Api\Domain\Order\Order;
use Bayti\Api\Domain\Order\OrderRepository;
use Bayti\Api\Domain\Payment\PaymentTransaction;
use Bayti\Api\Domain\Payment\PaymentTransactionRepository;
use Bayti\Api\Domain\Payment\PaymentWebhookEvent;
use Bayti\Api\Domain\Payment\PaymentWebhookEventRepository;
use Bayti\Api\Domain\User\User;
use Bayti\Api\Domain\User\UserRepository;
use Bayti\Api\Http\Controllers\Checkout\NoonWebhookController;
use Bayti\Api\Payment\Noon\NoonWebhookSignatureVerifier;
use Bayti\Api\Payment\OrderStatusResponse;
use Bayti\Api\Payment\PaymentGatewayException;
use Bayti\Api\Payment\PaymentGatewayInterface;
use Bayti\Api\Tests\Http\HttpTestCase;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;

#[CoversClass(NoonWebhookController::class)]
final class NoonWebhookControllerTest extends HttpTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        // Default bindings — tests rebind their own when they care.
        $this->bind(PaymentGatewayInterface::class, $this->createMock(PaymentGatewayInterface::class));
    }

    #[Test]
    public function returns401WhenSignatureVerificationFails(): void
    {
        $verifier = $this->createMock(NoonWebhookSignatureVerifier::class);
        $verifier->method('verify')->willReturn(false);
        $this->bind(NoonWebhookSignatureVerifier::class, $verifier);

        $response = $this->handle(
            $this->jsonRequest('POST', '/v3/payment/webhook/noon', [
                'eventId' => 'evt-001',
            ])
        );

        self::assertSame(401, $response->getStatusCode());
    }

    #[Test]
    public function returns200AndDeduplicatesOnRepeatedEvent(): void
    {
        $verifier = $this->createMock(NoonWebhookSignatureVerifier::class);
        $verifier->method('verify')->willReturn(true);
        $this->bind(NoonWebhookSignatureVerifier::class, $verifier);

        $eventRepo = $this->createMock(PaymentWebhookEventRepository::class);
        // Simulate that we've seen this event before.
        $existing = (new \ReflectionClass(PaymentWebhookEvent::class))
            ->newInstanceWithoutConstructor();
        $eventRepo->method('findByIdempotencyKey')
            ->with('noon:evt-001')
            ->willReturn($existing);
        // ::save MUST NOT be called for duplicates.
        $eventRepo->expects(self::never())->method('save');

        $em = $this->stubEm(function ($em) use ($eventRepo) {
            $em->method('getRepository')->willReturnMap([
                [PaymentWebhookEvent::class, $eventRepo],
            ]);
        });
        $this->bind(EntityManagerInterface::class, $em);

        $response = $this->handle(
            $this->jsonRequest('POST', '/v3/payment/webhook/noon', [
                'eventId' => 'evt-001',
                'result' => ['order' => ['id' => '123456789012']],
            ])
        );

        self::assertSame(200, $response->getStatusCode());
        $body = $this->jsonBody($response);
        self::assertSame('duplicate', $body['status']);
    }

    #[Test]
    public function returns200AndNoMatchWhenOrderNotFound(): void
    {
        $verifier = $this->createMock(NoonWebhookSignatureVerifier::class);
        $verifier->method('verify')->willReturn(true);
        $this->bind(NoonWebhookSignatureVerifier::class, $verifier);

        $eventRepo = $this->createMock(PaymentWebhookEventRepository::class);
        $eventRepo->method('findByIdempotencyKey')->willReturn(null);
        $eventRepo->expects(self::once())->method('save');

        $txRepo = $this->createMock(PaymentTransactionRepository::class);
        $txRepo->method('findByProviderOrderRef')->willReturn(null);

        $orderRepo = $this->createMock(OrderRepository::class);
        $orderRepo->method('findByOrderReference')->willReturn(null);

        $em = $this->stubEm(function ($em) use ($eventRepo, $txRepo, $orderRepo) {
            $em->method('getRepository')->willReturnMap([
                [PaymentWebhookEvent::class, $eventRepo],
                [PaymentTransaction::class, $txRepo],
                [Order::class, $this->createMock(OrderRepository::class)],
                [Order::class, $orderRepo],
            ]);
        });
        $this->bind(EntityManagerInterface::class, $em);

        $response = $this->handle(
            $this->jsonRequest('POST', '/v3/payment/webhook/noon', [
                'eventId' => 'evt-orphan',
                'result' => [
                    'order' => [
                        'id' => '999999999999',
                        'reference' => 'V3-UNKNOWN',
                    ],
                ],
            ])
        );

        self::assertSame(200, $response->getStatusCode());
        $body = $this->jsonBody($response);
        self::assertSame('no_match', $body['status']);
    }

    #[Test]
    public function appliesPaidStatusWhenRetrieveOrderConfirmsCaptured(): void
    {
        $user = $this->makeUser(id: 7);
        $order = new Order(user: $user, orderReference: 'V3-PEND-001', subtotal: '299.00');
        $this->setEntityId($order, 100);

        $verifier = $this->createMock(NoonWebhookSignatureVerifier::class);
        $verifier->method('verify')->willReturn(true);
        $this->bind(NoonWebhookSignatureVerifier::class, $verifier);

        $gateway = $this->createMock(PaymentGatewayInterface::class);
        $gateway->expects(self::once())
            ->method('retrieveOrder')
            ->with('123456789012')
            ->willReturn(new OrderStatusResponse(
                providerOrderRef: '123456789012',
                status: 'CAPTURED',
                terminal: true,
                paid: true,
                amount: '299.00',
                currency: 'AED',
                rawResponse: [],
            ));
        $this->bind(PaymentGatewayInterface::class, $gateway);

        $eventRepo = $this->createMock(PaymentWebhookEventRepository::class);
        $eventRepo->method('findByIdempotencyKey')->willReturn(null);
        $eventRepo->method('save');

        $txRepo = $this->createMock(PaymentTransactionRepository::class);
        $tx = $this->createMock(PaymentTransaction::class);
        $tx->method('getOrder')->willReturn($order);
        $txRepo->method('findByProviderOrderRef')->with('123456789012')->willReturn($tx);

        $em = $this->stubEm(function ($em) use ($eventRepo, $txRepo) {
            $em->method('getRepository')->willReturnMap([
                [PaymentWebhookEvent::class, $eventRepo],
                [PaymentTransaction::class, $txRepo],
                [Order::class, $this->createMock(OrderRepository::class)],
            ]);
            $em->method('flush');
        });
        $this->bind(EntityManagerInterface::class, $em);

        $response = $this->handle(
            $this->jsonRequest('POST', '/v3/payment/webhook/noon', [
                'eventId' => 'evt-001',
                'result' => [
                    'order' => [
                        'id' => '123456789012',
                        'reference' => 'V3-PEND-001',
                        'status' => 'CAPTURED',
                    ],
                ],
            ])
        );

        self::assertSame(200, $response->getStatusCode());
        $body = $this->jsonBody($response);
        self::assertSame('processed', $body['status']);

        // Order should now be paid (via retrieve-order's authoritative response)
        self::assertSame(Order::STATUS_PAID, $order->getStatus());
    }

    #[Test]
    public function matchesOrderFromFlatWebhookShapeOrderId(): void
    {
        // Noon's real WEBHOOK notification is a FLAT payload with the order
        // id at the top level as `orderId` (+ merchant ref as `orderReference`)
        // — NOT the nested result.order.id shape the API RESPONSE uses. This
        // is the shape observed in production sandbox traffic. Regression
        // guard: an earlier version only read result.order.id, so every real
        // webhook resolved to no_match and orders were never confirmed.
        $user = $this->makeUser(id: 7);
        $order = new Order(user: $user, orderReference: 'V3-1785406275341-1ad4', subtotal: '1370.00');
        $this->setEntityId($order, 100);

        $verifier = $this->createMock(NoonWebhookSignatureVerifier::class);
        $verifier->method('verify')->willReturn(true);
        $this->bind(NoonWebhookSignatureVerifier::class, $verifier);

        // Drive a terminal FAILED outcome: the point of THIS test is to prove
        // the order was MATCHED from the flat orderId (reaching a transition at
        // all), not to exercise the paid-path side effects. retrieveOrder is
        // called with the string-coerced top-level orderId.
        $gateway = $this->createMock(PaymentGatewayInterface::class);
        $gateway->expects(self::once())
            ->method('retrieveOrder')
            ->with('886482671413')
            ->willReturn(new OrderStatusResponse(
                providerOrderRef: '886482671413',
                status: 'FAILED',
                terminal: true,
                paid: false,
                amount: '1370.00',
                currency: 'AED',
                rawResponse: [],
            ));
        $this->bind(PaymentGatewayInterface::class, $gateway);

        $eventRepo = $this->createMock(PaymentWebhookEventRepository::class);
        $eventRepo->method('findByIdempotencyKey')->willReturn(null);
        $eventRepo->method('save');

        $txRepo = $this->createMock(PaymentTransactionRepository::class);
        $tx = $this->createMock(PaymentTransaction::class);
        $tx->method('getOrder')->willReturn($order);
        // Match must be driven off the top-level orderId, coerced to string.
        $txRepo->method('findByProviderOrderRef')->with('886482671413')->willReturn($tx);

        $em = $this->stubEm(function ($em) use ($eventRepo, $txRepo) {
            $em->method('getRepository')->willReturnMap([
                [PaymentWebhookEvent::class, $eventRepo],
                [PaymentTransaction::class, $txRepo],
                [Order::class, $this->createMock(OrderRepository::class)],
            ]);
            $em->method('flush');
        });
        $this->bind(EntityManagerInterface::class, $em);

        $response = $this->handle(
            $this->jsonRequest('POST', '/v3/payment/webhook/noon', [
                'eventType' => 'SALE',
                // Numeric orderId (as Noon sends it) — must be coerced to the
                // string form stored in payment_transactions.provider_order_ref.
                'orderId' => 886482671413,
                'orderReference' => 'V3-1785406275341-1ad4',
            ])
        );

        self::assertSame(200, $response->getStatusCode());
        // Reaching a terminal transition proves the order was found from the
        // flat orderId — a miss would have returned no_match with status unchanged.
        self::assertSame(Order::STATUS_FAILED, $order->getStatus());
    }

    #[Test]
    public function appliesFailedStatusWhenRetrieveOrderConfirmsFailed(): void
    {
        $user = $this->makeUser(id: 7);
        $order = new Order(user: $user, orderReference: 'V3-PEND-002', subtotal: '299.00');
        $this->setEntityId($order, 100);

        $verifier = $this->createMock(NoonWebhookSignatureVerifier::class);
        $verifier->method('verify')->willReturn(true);
        $this->bind(NoonWebhookSignatureVerifier::class, $verifier);

        $gateway = $this->createMock(PaymentGatewayInterface::class);
        $gateway->method('retrieveOrder')->willReturn(new OrderStatusResponse(
            providerOrderRef: '123456789012',
            status: 'FAILED',
            terminal: true,
            paid: false,
            amount: '299.00',
            currency: 'AED',
            rawResponse: [],
        ));
        $this->bind(PaymentGatewayInterface::class, $gateway);

        $eventRepo = $this->createMock(PaymentWebhookEventRepository::class);
        $eventRepo->method('findByIdempotencyKey')->willReturn(null);
        $eventRepo->method('save');

        $txRepo = $this->createMock(PaymentTransactionRepository::class);
        $tx = $this->createMock(PaymentTransaction::class);
        $tx->method('getOrder')->willReturn($order);
        $txRepo->method('findByProviderOrderRef')->willReturn($tx);

        $em = $this->stubEm(function ($em) use ($eventRepo, $txRepo) {
            $em->method('getRepository')->willReturnMap([
                [PaymentWebhookEvent::class, $eventRepo],
                [PaymentTransaction::class, $txRepo],
                [Order::class, $this->createMock(OrderRepository::class)],
            ]);
            $em->method('flush');
        });
        $this->bind(EntityManagerInterface::class, $em);

        $response = $this->handle(
            $this->jsonRequest('POST', '/v3/payment/webhook/noon', [
                'eventId' => 'evt-fail-001',
                'result' => [
                    'order' => [
                        'id' => '123456789012',
                        'status' => 'FAILED',
                    ],
                ],
            ])
        );

        self::assertSame(200, $response->getStatusCode());
        self::assertSame(Order::STATUS_FAILED, $order->getStatus());
    }

    #[Test]
    public function defersWhenRetrieveOrderFails(): void
    {
        $user = $this->makeUser(id: 7);
        $order = new Order(user: $user, orderReference: 'V3-PEND-003', subtotal: '299.00');
        $this->setEntityId($order, 100);
        $originalStatus = $order->getStatus();

        $verifier = $this->createMock(NoonWebhookSignatureVerifier::class);
        $verifier->method('verify')->willReturn(true);
        $this->bind(NoonWebhookSignatureVerifier::class, $verifier);

        // Gateway throws on retrieveOrder.
        $gateway = $this->createMock(PaymentGatewayInterface::class);
        $gateway->method('retrieveOrder')->willThrowException(
            PaymentGatewayException::network('Connection refused')
        );
        $this->bind(PaymentGatewayInterface::class, $gateway);

        $eventRepo = $this->createMock(PaymentWebhookEventRepository::class);
        $eventRepo->method('findByIdempotencyKey')->willReturn(null);
        $eventRepo->method('save');

        $txRepo = $this->createMock(PaymentTransactionRepository::class);
        $tx = $this->createMock(PaymentTransaction::class);
        $tx->method('getOrder')->willReturn($order);
        $txRepo->method('findByProviderOrderRef')->willReturn($tx);

        $em = $this->stubEm(function ($em) use ($eventRepo, $txRepo) {
            $em->method('getRepository')->willReturnMap([
                [PaymentWebhookEvent::class, $eventRepo],
                [PaymentTransaction::class, $txRepo],
                [Order::class, $this->createMock(OrderRepository::class)],
            ]);
        });
        $this->bind(EntityManagerInterface::class, $em);

        $response = $this->handle(
            $this->jsonRequest('POST', '/v3/payment/webhook/noon', [
                'eventId' => 'evt-net-001',
                'result' => ['order' => ['id' => '123456789012']],
            ])
        );

        self::assertSame(200, $response->getStatusCode());
        $body = $this->jsonBody($response);
        self::assertSame('received_unconfirmed', $body['status']);

        // Critical: order status must NOT have changed.
        self::assertSame($originalStatus, $order->getStatus());
    }

    #[Test]
    public function noActionForTransientStatus(): void
    {
        $user = $this->makeUser(id: 7);
        $order = new Order(user: $user, orderReference: 'V3-PEND-004', subtotal: '299.00');
        $this->setEntityId($order, 100);
        $originalStatus = $order->getStatus();

        $verifier = $this->createMock(NoonWebhookSignatureVerifier::class);
        $verifier->method('verify')->willReturn(true);
        $this->bind(NoonWebhookSignatureVerifier::class, $verifier);

        // Gateway returns AUTHORIZED — terminal=false, paid=false
        $gateway = $this->createMock(PaymentGatewayInterface::class);
        $gateway->method('retrieveOrder')->willReturn(new OrderStatusResponse(
            providerOrderRef: '123456789012',
            status: 'AUTHORIZED',
            terminal: false,
            paid: false,
            amount: '299.00',
            currency: 'AED',
            rawResponse: [],
        ));
        $this->bind(PaymentGatewayInterface::class, $gateway);

        $eventRepo = $this->createMock(PaymentWebhookEventRepository::class);
        $eventRepo->method('findByIdempotencyKey')->willReturn(null);
        $eventRepo->method('save');

        $txRepo = $this->createMock(PaymentTransactionRepository::class);
        $tx = $this->createMock(PaymentTransaction::class);
        $tx->method('getOrder')->willReturn($order);
        $txRepo->method('findByProviderOrderRef')->willReturn($tx);

        $em = $this->stubEm(function ($em) use ($eventRepo, $txRepo) {
            $em->method('getRepository')->willReturnMap([
                [PaymentWebhookEvent::class, $eventRepo],
                [PaymentTransaction::class, $txRepo],
                [Order::class, $this->createMock(OrderRepository::class)],
            ]);
            $em->method('flush');
        });
        $this->bind(EntityManagerInterface::class, $em);

        $response = $this->handle(
            $this->jsonRequest('POST', '/v3/payment/webhook/noon', [
                'eventId' => 'evt-auth-001',
                'result' => ['order' => ['id' => '123456789012', 'status' => 'AUTHORIZED']],
            ])
        );

        self::assertSame(200, $response->getStatusCode());
        // Order status should NOT have moved — AUTHORIZED is transient.
        self::assertSame($originalStatus, $order->getStatus());
    }

    // ==================================================================
    // signature_verified audit flag — must reflect whether the bound
    // verifier actually performed a cryptographic check, not merely
    // that it accepted the event.
    // ==================================================================

    #[Test]
    public function recordsSignatureVerifiedFalseUnderPassthroughVerifier(): void
    {
        // LoggingOnlyVerifier-style: verify() accepts but isCryptographic()
        // is false → the persisted event must report signature_verified=false.
        $verifier = $this->createMock(NoonWebhookSignatureVerifier::class);
        $verifier->method('verify')->willReturn(true);
        $verifier->method('isCryptographic')->willReturn(false);
        $this->bind(NoonWebhookSignatureVerifier::class, $verifier);

        $captured = null;
        $eventRepo = $this->createMock(PaymentWebhookEventRepository::class);
        $eventRepo->method('findByIdempotencyKey')->willReturn(null);
        $eventRepo->method('save')->willReturnCallback(
            function (PaymentWebhookEvent $e) use (&$captured): void { $captured = $e; }
        );

        $txRepo = $this->createMock(PaymentTransactionRepository::class);
        $txRepo->method('findByProviderOrderRef')->willReturn(null);
        $orderRepo = $this->createMock(OrderRepository::class);
        $orderRepo->method('findByOrderReference')->willReturn(null);

        $em = $this->stubEm(function ($em) use ($eventRepo, $txRepo, $orderRepo) {
            $em->method('getRepository')->willReturnMap([
                [PaymentWebhookEvent::class, $eventRepo],
                [PaymentTransaction::class, $txRepo],
                [Order::class, $orderRepo],
            ]);
        });
        $this->bind(EntityManagerInterface::class, $em);

        $response = $this->handle(
            $this->jsonRequest('POST', '/v3/payment/webhook/noon', [
                'eventId' => 'evt-sig-false',
                'result' => ['order' => ['id' => '123456789012', 'reference' => 'V3-SIG-F']],
            ])
        );

        self::assertSame(200, $response->getStatusCode());
        self::assertInstanceOf(PaymentWebhookEvent::class, $captured);
        self::assertFalse($captured->isSignatureVerified());
    }

    #[Test]
    public function recordsSignatureVerifiedTrueUnderCryptographicVerifier(): void
    {
        // HmacSha256-style: verify() accepts AND isCryptographic() is true
        // → the persisted event must report signature_verified=true.
        $verifier = $this->createMock(NoonWebhookSignatureVerifier::class);
        $verifier->method('verify')->willReturn(true);
        $verifier->method('isCryptographic')->willReturn(true);
        $this->bind(NoonWebhookSignatureVerifier::class, $verifier);

        $captured = null;
        $eventRepo = $this->createMock(PaymentWebhookEventRepository::class);
        $eventRepo->method('findByIdempotencyKey')->willReturn(null);
        $eventRepo->method('save')->willReturnCallback(
            function (PaymentWebhookEvent $e) use (&$captured): void { $captured = $e; }
        );

        $txRepo = $this->createMock(PaymentTransactionRepository::class);
        $txRepo->method('findByProviderOrderRef')->willReturn(null);
        $orderRepo = $this->createMock(OrderRepository::class);
        $orderRepo->method('findByOrderReference')->willReturn(null);

        $em = $this->stubEm(function ($em) use ($eventRepo, $txRepo, $orderRepo) {
            $em->method('getRepository')->willReturnMap([
                [PaymentWebhookEvent::class, $eventRepo],
                [PaymentTransaction::class, $txRepo],
                [Order::class, $orderRepo],
            ]);
        });
        $this->bind(EntityManagerInterface::class, $em);

        $response = $this->handle(
            $this->jsonRequest('POST', '/v3/payment/webhook/noon', [
                'eventId' => 'evt-sig-true',
                'result' => ['order' => ['id' => '123456789012', 'reference' => 'V3-SIG-T']],
            ])
        );

        self::assertSame(200, $response->getStatusCode());
        self::assertInstanceOf(PaymentWebhookEvent::class, $captured);
        self::assertTrue($captured->isSignatureVerified());
    }

    // ==================================================================
    // M3.2.X.5-A — Observability hook for unknown dispute-shaped events
    // ==================================================================

    #[Test]
    public function emitsWarningForUnknownDisputeShapedEventType(): void
    {
        // An eventType containing 'dispute' (case-insensitive) that
        // isn't in DISPUTE_EVENT_TYPES should produce a warning log
        // line so the unknown string surfaces in production logs.
        $logger = $this->makeCapturingLogger();
        $this->bind(\Psr\Log\LoggerInterface::class, $logger);

        $verifier = $this->createMock(NoonWebhookSignatureVerifier::class);
        $verifier->method('verify')->willReturn(true);
        $this->bind(NoonWebhookSignatureVerifier::class, $verifier);

        $eventRepo = $this->createMock(PaymentWebhookEventRepository::class);
        $eventRepo->method('findByIdempotencyKey')->willReturn(null);
        $eventRepo->method('save');

        $em = $this->stubEm(function ($em) use ($eventRepo) {
            $em->method('getRepository')->willReturnMap([
                [PaymentWebhookEvent::class, $eventRepo],
                [PaymentTransaction::class, $this->createMock(PaymentTransactionRepository::class)],
                [Order::class, $this->createMock(OrderRepository::class)],
            ]);
            $em->method('flush');
        });
        $this->bind(EntityManagerInterface::class, $em);

        $response = $this->handle(
            $this->jsonRequest('POST', '/v3/payment/webhook/noon', [
                'eventId' => 'evt-unknown-dispute-001',
                'eventType' => 'payment.dispute.created',  // hypothetical Noon string NOT in constant
                'result' => ['order' => ['id' => '123456789012']],
            ])
        );

        self::assertSame(200, $response->getStatusCode());

        // Find the warning entry.
        $warnings = array_filter(
            $logger->captured,
            static fn (array $r): bool => $r['level'] === 'warning'
                && $r['message'] === 'noon.webhook.unknown_dispute_event_type',
        );
        self::assertCount(1, $warnings, 'expected exactly one unknown-dispute warning');
        $warning = array_values($warnings)[0];
        self::assertSame('payment.dispute.created', $warning['context']['event_type']);
        self::assertArrayHasKey('idempotency_key', $warning['context']);
        self::assertArrayHasKey('recognized_types', $warning['context']);
    }

    #[Test]
    public function emitsWarningForUnknownChargebackShapedEventType(): void
    {
        // Same hook — 'chargeback' substring should also trigger.
        $logger = $this->makeCapturingLogger();
        $this->bind(\Psr\Log\LoggerInterface::class, $logger);

        $verifier = $this->createMock(NoonWebhookSignatureVerifier::class);
        $verifier->method('verify')->willReturn(true);
        $this->bind(NoonWebhookSignatureVerifier::class, $verifier);

        $eventRepo = $this->createMock(PaymentWebhookEventRepository::class);
        $eventRepo->method('findByIdempotencyKey')->willReturn(null);
        $eventRepo->method('save');

        $em = $this->stubEm(function ($em) use ($eventRepo) {
            $em->method('getRepository')->willReturnMap([
                [PaymentWebhookEvent::class, $eventRepo],
                [PaymentTransaction::class, $this->createMock(PaymentTransactionRepository::class)],
                [Order::class, $this->createMock(OrderRepository::class)],
            ]);
            $em->method('flush');
        });
        $this->bind(EntityManagerInterface::class, $em);

        $response = $this->handle(
            $this->jsonRequest('POST', '/v3/payment/webhook/noon', [
                'eventId' => 'evt-unknown-cb-001',
                'eventType' => 'Order.Chargeback.Pending',  // mixed-case test
                'result' => ['order' => ['id' => '123456789012']],
            ])
        );

        self::assertSame(200, $response->getStatusCode());
        $warnings = array_filter(
            $logger->captured,
            static fn (array $r): bool => $r['level'] === 'warning'
                && $r['message'] === 'noon.webhook.unknown_dispute_event_type',
        );
        self::assertCount(1, $warnings);
        self::assertSame('Order.Chargeback.Pending', array_values($warnings)[0]['context']['event_type']);
    }

    #[Test]
    public function doesNotWarnForKnownDisputeEventType(): void
    {
        // A known dispute eventType (in DISPUTE_EVENT_TYPES) must NOT
        // emit the unknown-dispute warning — that would be noise.
        $logger = $this->makeCapturingLogger();
        $this->bind(\Psr\Log\LoggerInterface::class, $logger);

        $verifier = $this->createMock(NoonWebhookSignatureVerifier::class);
        $verifier->method('verify')->willReturn(true);
        $this->bind(NoonWebhookSignatureVerifier::class, $verifier);

        $eventRepo = $this->createMock(PaymentWebhookEventRepository::class);
        $eventRepo->method('findByIdempotencyKey')->willReturn(null);
        $eventRepo->method('save');

        $em = $this->stubEm(function ($em) use ($eventRepo) {
            $em->method('getRepository')->willReturnMap([
                [PaymentWebhookEvent::class, $eventRepo],
                [PaymentTransaction::class, $this->createMock(PaymentTransactionRepository::class)],
                [Order::class, $this->createMock(OrderRepository::class)],
                [\Bayti\Api\Domain\Order\OrderDispute::class, $this->createMock(\Bayti\Api\Domain\Order\OrderDisputeRepository::class)],
            ]);
            $em->method('flush');
            $em->method('persist');
        });
        $this->bind(EntityManagerInterface::class, $em);

        $response = $this->handle(
            $this->jsonRequest('POST', '/v3/payment/webhook/noon', [
                'eventId' => 'evt-known-dispute-001',
                'eventType' => 'CHARGEBACK_OPENED',  // IS in DISPUTE_EVENT_TYPES
                'result' => ['order' => ['id' => '123456789012']],
            ])
        );

        self::assertSame(200, $response->getStatusCode());
        $warnings = array_filter(
            $logger->captured,
            static fn (array $r): bool => $r['level'] === 'warning'
                && $r['message'] === 'noon.webhook.unknown_dispute_event_type',
        );
        self::assertCount(0, $warnings, 'known dispute type must not emit the unknown warning');
    }

    #[Test]
    public function doesNotWarnForUnrelatedEventType(): void
    {
        // Standard non-dispute eventType (e.g. 'order.captured') must
        // NOT emit the warning either — the hook only triggers on
        // 'dispute' or 'chargeback' substring.
        $logger = $this->makeCapturingLogger();
        $this->bind(\Psr\Log\LoggerInterface::class, $logger);

        $verifier = $this->createMock(NoonWebhookSignatureVerifier::class);
        $verifier->method('verify')->willReturn(true);
        $this->bind(NoonWebhookSignatureVerifier::class, $verifier);

        $eventRepo = $this->createMock(PaymentWebhookEventRepository::class);
        $eventRepo->method('findByIdempotencyKey')->willReturn(null);
        $eventRepo->method('save');

        $em = $this->stubEm(function ($em) use ($eventRepo) {
            $em->method('getRepository')->willReturnMap([
                [PaymentWebhookEvent::class, $eventRepo],
                [PaymentTransaction::class, $this->createMock(PaymentTransactionRepository::class)],
                [Order::class, $this->createMock(OrderRepository::class)],
            ]);
            $em->method('flush');
        });
        $this->bind(EntityManagerInterface::class, $em);

        $response = $this->handle(
            $this->jsonRequest('POST', '/v3/payment/webhook/noon', [
                'eventId' => 'evt-captured-001',
                'eventType' => 'order.captured',
                'result' => ['order' => ['id' => '123456789012']],
            ])
        );

        self::assertSame(200, $response->getStatusCode());
        $warnings = array_filter(
            $logger->captured,
            static fn (array $r): bool => $r['level'] === 'warning'
                && $r['message'] === 'noon.webhook.unknown_dispute_event_type',
        );
        self::assertCount(0, $warnings);
    }

    /**
     * Anonymous logger that captures every call as a record array.
     * Public `$captured` so tests can assert against it directly.
     */
    private function makeCapturingLogger(): \Psr\Log\LoggerInterface
    {
        return new class implements \Psr\Log\LoggerInterface {
            /** @var list<array{level: string, message: string, context: array<string, mixed>}> */
            public array $captured = [];
            public function emergency(string|\Stringable $message, array $context = []): void { $this->log('emergency', $message, $context); }
            public function alert(string|\Stringable $message, array $context = []): void { $this->log('alert', $message, $context); }
            public function critical(string|\Stringable $message, array $context = []): void { $this->log('critical', $message, $context); }
            public function error(string|\Stringable $message, array $context = []): void { $this->log('error', $message, $context); }
            public function warning(string|\Stringable $message, array $context = []): void { $this->log('warning', $message, $context); }
            public function notice(string|\Stringable $message, array $context = []): void { $this->log('notice', $message, $context); }
            public function info(string|\Stringable $message, array $context = []): void { $this->log('info', $message, $context); }
            public function debug(string|\Stringable $message, array $context = []): void { $this->log('debug', $message, $context); }
            public function log($level, string|\Stringable $message, array $context = []): void
            {
                $this->captured[] = [
                    'level' => (string) $level,
                    'message' => (string) $message,
                    'context' => $context,
                ];
            }
        };
    }

    private function setEntityId(object $entity, int $id): void
    {
        $ref = new \ReflectionProperty($entity::class, 'id');
        $ref->setAccessible(true);
        $ref->setValue($entity, $id);
    }
}
