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

    private function setEntityId(object $entity, int $id): void
    {
        $ref = new \ReflectionProperty($entity::class, 'id');
        $ref->setAccessible(true);
        $ref->setValue($entity, $id);
    }
}
