<?php

declare(strict_types=1);

namespace Bayti\Api\Tests\Http\Controllers\Admin\Order;

use Bayti\Api\Domain\Audit\AuditEmitter;
use Bayti\Api\Domain\Audit\AuditLog;
use Bayti\Api\Domain\Notification\DeviceToken;
use Bayti\Api\Domain\Notification\DeviceTokenRepository;
use Bayti\Api\Domain\Order\CancelOrderService;
use Bayti\Api\Domain\Order\Order;
use Bayti\Api\Domain\Order\OrderRepository;
use Bayti\Api\Domain\Payment\PaymentTransaction;
use Bayti\Api\Domain\Payment\PaymentTransactionRepository;
use Bayti\Api\Domain\User\User;
use Bayti\Api\Domain\User\UserRepository;
use Bayti\Api\Http\Controllers\Admin\Order\CancelOrderController;
use Bayti\Api\Infrastructure\Auth\JwtService;
use Bayti\Api\Notification\Push\InMemoryPushSender;
use Bayti\Api\Notification\Push\PushSenderInterface;
use Bayti\Api\Payment\OrderStatusResponse;
use Bayti\Api\Payment\PaymentGatewayException;
use Bayti\Api\Payment\PaymentGatewayInterface;
use Bayti\Api\Tests\Http\HttpTestCase;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use Psr\Http\Message\ResponseInterface;
use Psr\Log\NullLogger;

#[CoversClass(CancelOrderController::class)]
#[CoversClass(CancelOrderService::class)]
final class CancelOrderControllerTest extends HttpTestCase
{
    /** @var array<int, AuditLog> */
    private array $recordedAuditLogs = [];
    /** @var array<int, PaymentTransaction> */
    private array $savedTransactions = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->recordedAuditLogs = [];
        $this->savedTransactions = [];
    }

    #[Test]
    public function cancelsPendingPaymentLocallyWithoutGateway(): void
    {
        $admin = $this->makeAdminUser(99);
        $customer = $this->makeUser(id: 42);
        $order = $this->makeOrder($customer, id: 100, reference: 'V3-001', subtotal: '299.00');
        $this->setEntityProp($order, 'status', Order::STATUS_PENDING_PAYMENT);

        $gateway = $this->createMock(PaymentGatewayInterface::class);
        // Critical: refund must NEVER be called for pending_payment
        $gateway->expects(self::never())->method('refund');

        $this->bindEnvironment($admin, $order, [], '0.00', $gateway);

        $response = $this->makePost($admin, '/v3/admin/orders/100/cancel', [
            'reason' => 'customer never completed checkout',
        ]);

        self::assertSame(200, $response->getStatusCode());
        self::assertSame(Order::STATUS_CANCELLED, $order->getStatus());
        $body = $this->jsonBody($response);
        self::assertFalse($body['cancellation']['refund_issued']);
        self::assertNull($body['cancellation']['refund_amount']);
        self::assertFalse($body['cancellation']['was_already_cancelled']);

        // No payment transactions saved (no gateway call)
        self::assertCount(0, $this->savedTransactions);
        // Audit emitted
        self::assertCount(1, $this->recordedAuditLogs);
    }

    /**
     * M3.2.Z.6 — lifecycle push wiring. Cancelling an order must fan a
     * push out to the customer's active device tokens beside the email.
     * Drives the real controller → CancelOrderService → push seam, with
     * an InMemoryPushSender capturing the send and a DeviceTokenRepository
     * supplying one active token for the customer.
     */
    #[Test]
    public function cancellationFansOutPushToCustomerDevice(): void
    {
        $admin = $this->makeAdminUser(99);
        $customer = $this->makeUser(id: 42);
        $order = $this->makeOrder($customer, id: 100, reference: 'V3-PUSH-CANCEL', subtotal: '299.00');
        $this->setEntityProp($order, 'status', Order::STATUS_PENDING_PAYMENT);

        $gateway = $this->createMock(PaymentGatewayInterface::class);
        $gateway->expects(self::never())->method('refund');

        // Capture pushes.
        $pushSender = new InMemoryPushSender();
        $this->bind(PushSenderInterface::class, $pushSender);

        // EM with a DeviceToken repo returning one active token for the
        // customer (in addition to the usual cancel-flow repos).
        $userRepo = $this->createMock(UserRepository::class);
        $userRepo->method('findById')->willReturn($admin);

        $orderRepo = $this->createMock(OrderRepository::class);
        $orderRepo->method('findByIdForAdmin')->willReturn($order);

        $txnRepo = $this->createMock(PaymentTransactionRepository::class);
        $txnRepo->method('sumRefundsForOrder')->willReturn('0.00');
        $txnRepo->method('findLatestInitiateForOrder')->willReturn(null);

        $auditRepo = new class($this->recordedAuditLogs) extends \Doctrine\ORM\EntityRepository {
            /** @param array<int,AuditLog> $sink */
            public function __construct(private array &$sink)
            {
            }
            public function save(AuditLog $log): void
            {
                $this->sink[] = $log;
            }
            public function getClassName(): string
            {
                return AuditLog::class;
            }
        };

        $deviceTokenRepo = $this->createMock(DeviceTokenRepository::class);
        $deviceTokenRepo->method('findActiveForUser')->willReturn([
            new DeviceToken($customer, 'fcm-customer-device', DeviceToken::PLATFORM_IOS),
        ]);

        $em = $this->stubEm(function ($em) use ($userRepo, $orderRepo, $txnRepo, $auditRepo, $deviceTokenRepo): void {
            $em->method('getRepository')->willReturnMap([
                [User::class, $userRepo],
                [Order::class, $orderRepo],
                [PaymentTransaction::class, $txnRepo],
                [AuditLog::class, $auditRepo],
                [DeviceToken::class, $deviceTokenRepo],
            ]);
        });
        $this->bind(EntityManagerInterface::class, $em);
        $this->bind(AuditEmitter::class, new AuditEmitter($em, new NullLogger()));
        $this->bind(PaymentGatewayInterface::class, $gateway);

        $response = $this->makePost($admin, '/v3/admin/orders/100/cancel', [
            'reason' => 'customer never completed checkout',
        ]);

        self::assertSame(200, $response->getStatusCode());

        // A push fanned out to the customer's device with the cancel type.
        self::assertSame(['fcm-customer-device'], $pushSender->tokensSent());
        $msg = $pushSender->sent()[0]['message'];
        self::assertSame('order.cancelled', $msg->data['type']);
        self::assertSame('V3-PUSH-CANCEL', $msg->data['order_reference']);
    }

    #[Test]
    public function cancelsPaidOrderWithAutoRefund(): void
    {
        $admin = $this->makeAdminUser(99);
        $customer = $this->makeUser(id: 42);
        $order = $this->makeOrder($customer, id: 100, reference: 'V3-001', subtotal: '299.00');
        $this->setEntityProp($order, 'status', Order::STATUS_PAID);

        $initiateTxn = $this->makeTxn($order, 'INITIATE', 'Initiated', '299.00', 'NOON-REF-1');

        $gateway = $this->createMock(PaymentGatewayInterface::class);
        $gateway->expects(self::once())
            ->method('refund')
            ->with('NOON-REF-1', '299.00', 'AED', self::stringContains('Cancellation'))
            ->willReturn(new OrderStatusResponse(
                providerOrderRef: 'NOON-REF-1',
                status: 'Refunded',
                terminal: true,
                paid: false,
                amount: '299.00',
                currency: 'AED',
                rawResponse: ['result' => ['ok' => true]],
            ));

        $this->bindEnvironment($admin, $order, [$initiateTxn], '0.00', $gateway);

        $response = $this->makePost($admin, '/v3/admin/orders/100/cancel', [
            'reason' => 'customer changed mind',
        ]);

        self::assertSame(200, $response->getStatusCode());
        self::assertSame(Order::STATUS_CANCELLED, $order->getStatus());

        $body = $this->jsonBody($response);
        self::assertTrue($body['cancellation']['refund_issued']);
        self::assertSame('299.00', $body['cancellation']['refund_amount']);

        // REFUND transaction recorded
        $refundTxns = array_filter(
            $this->savedTransactions,
            fn (PaymentTransaction $t) => $t->getOperation() === 'REFUND',
        );
        self::assertCount(1, $refundTxns);

        // Audit emitted with full diff
        self::assertCount(1, $this->recordedAuditLogs);
        $audit = $this->recordedAuditLogs[0];
        $changes = $audit->getChanges();
        self::assertSame(Order::STATUS_PAID, $changes['before']['status']);
        self::assertSame(Order::STATUS_CANCELLED, $changes['after']['status']);
        self::assertTrue($changes['auto_refund']);
    }

    #[Test]
    public function cancelOnAlreadyCancelledIsIdempotent(): void
    {
        $admin = $this->makeAdminUser(99);
        $customer = $this->makeUser(id: 42);
        $order = $this->makeOrder($customer, id: 100, reference: 'V3-001', subtotal: '299.00');
        $this->setEntityProp($order, 'status', Order::STATUS_CANCELLED);

        $gateway = $this->createMock(PaymentGatewayInterface::class);
        $gateway->expects(self::never())->method('refund');

        $this->bindEnvironment($admin, $order, [], '0.00', $gateway);

        $response = $this->makePost($admin, '/v3/admin/orders/100/cancel', [
            'reason' => 'duplicate request',
        ]);

        self::assertSame(200, $response->getStatusCode());
        $body = $this->jsonBody($response);
        self::assertTrue($body['cancellation']['was_already_cancelled']);
        self::assertFalse($body['cancellation']['refund_issued']);
        // No audit emitted on idempotent return
        self::assertCount(0, $this->recordedAuditLogs);
    }

    #[Test]
    public function rejectsShippedOrderCancellation(): void
    {
        $admin = $this->makeAdminUser(99);
        $customer = $this->makeUser(id: 42);
        $order = $this->makeOrder($customer, id: 100, reference: 'V3-001', subtotal: '299.00');
        $this->setEntityProp($order, 'status', Order::STATUS_SHIPPED);

        $gateway = $this->createMock(PaymentGatewayInterface::class);
        $gateway->expects(self::never())->method('refund');

        $this->bindEnvironment($admin, $order, [], '0.00', $gateway);

        $response = $this->makePost($admin, '/v3/admin/orders/100/cancel', [
            'reason' => 'too late',
        ]);

        self::assertSame(422, $response->getStatusCode());
        $body = $this->jsonBody($response);
        self::assertSame('cancellation_not_allowed', $body['error']['code']);
        self::assertSame(Order::STATUS_SHIPPED, $body['error']['details']['current_status']);
        // Order unchanged
        self::assertSame(Order::STATUS_SHIPPED, $order->getStatus());
    }

    #[Test]
    public function rejectsRefundedOrderCancellation(): void
    {
        $admin = $this->makeAdminUser(99);
        $customer = $this->makeUser(id: 42);
        $order = $this->makeOrder($customer, id: 100, reference: 'V3-001', subtotal: '299.00');
        $this->setEntityProp($order, 'status', Order::STATUS_REFUNDED);

        $gateway = $this->createMock(PaymentGatewayInterface::class);
        $this->bindEnvironment($admin, $order, [], '0.00', $gateway);

        $response = $this->makePost($admin, '/v3/admin/orders/100/cancel', [
            'reason' => 'too late',
        ]);

        self::assertSame(422, $response->getStatusCode());
    }

    #[Test]
    public function gatewayRefundFailureLeavesOrderInOriginalState(): void
    {
        $admin = $this->makeAdminUser(99);
        $customer = $this->makeUser(id: 42);
        $order = $this->makeOrder($customer, id: 100, reference: 'V3-001', subtotal: '299.00');
        $this->setEntityProp($order, 'status', Order::STATUS_PAID);

        $initiateTxn = $this->makeTxn($order, 'INITIATE', 'Initiated', '299.00', 'NOON-REF-1');

        $gateway = $this->createMock(PaymentGatewayInterface::class);
        $gateway->method('refund')->willThrowException(
            new PaymentGatewayException(
                kind: PaymentGatewayException::KIND_NETWORK,
                message: 'connection refused',
            ),
        );

        $this->bindEnvironment($admin, $order, [$initiateTxn], '0.00', $gateway);

        $response = $this->makePost($admin, '/v3/admin/orders/100/cancel', [
            'reason' => 'customer changed mind',
        ]);

        self::assertSame(502, $response->getStatusCode());
        $body = $this->jsonBody($response);
        self::assertSame('cancellation_gateway_failed', $body['error']['code']);

        // CRITICAL: order is NOT cancelled (left in PAID state for retry)
        self::assertSame(Order::STATUS_PAID, $order->getStatus());

        // Failed refund attempt IS recorded (for forensics)
        $failedRefunds = array_filter(
            $this->savedTransactions,
            fn (PaymentTransaction $t) => $t->getOperation() === 'REFUND' && $t->getStatus() === 'Failed',
        );
        self::assertCount(1, $failedRefunds);

        // No audit on failed cancel (the order didn't actually change state)
        self::assertCount(0, $this->recordedAuditLogs);
    }

    #[Test]
    public function returns404ForNonexistentOrder(): void
    {
        $admin = $this->makeAdminUser(99);
        $gateway = $this->createMock(PaymentGatewayInterface::class);

        $userRepo = $this->createMock(UserRepository::class);
        $userRepo->method('findById')->willReturn($admin);

        $orderRepo = $this->createMock(OrderRepository::class);
        $orderRepo->method('findByIdForAdmin')->willReturn(null);

        $em = $this->stubEm(function ($em) use ($userRepo, $orderRepo) {
            $em->method('getRepository')->willReturnMap([
                [User::class, $userRepo],
                [Order::class, $orderRepo],
            ]);
        });
        $this->bind(EntityManagerInterface::class, $em);
        $this->bind(PaymentGatewayInterface::class, $gateway);

        $response = $this->makePost($admin, '/v3/admin/orders/9999/cancel', [
            'reason' => 'whatever',
        ]);

        self::assertSame(404, $response->getStatusCode());
    }

    #[Test]
    public function missingReasonReturns422(): void
    {
        $admin = $this->makeAdminUser(99);
        $customer = $this->makeUser(id: 42);
        $order = $this->makeOrder($customer, id: 100, reference: 'V3-001', subtotal: '299.00');
        $this->setEntityProp($order, 'status', Order::STATUS_PENDING_PAYMENT);
        $gateway = $this->createMock(PaymentGatewayInterface::class);

        $this->bindEnvironment($admin, $order, [], '0.00', $gateway);

        $response = $this->makePost($admin, '/v3/admin/orders/100/cancel', []);
        self::assertSame(422, $response->getStatusCode());
    }

    // ===== Helpers =====

    private function bindEnvironment(
        User $admin,
        Order $order,
        array $initialTxns,
        string $sumRefunds,
        PaymentGatewayInterface $gateway,
    ): void {
        $userRepo = $this->createMock(UserRepository::class);
        $userRepo->method('findById')->willReturn($admin);

        $orderRepo = $this->createMock(OrderRepository::class);
        $orderRepo->method('findByIdForAdmin')->willReturn($order);

        $sink = &$this->savedTransactions;
        $txnRepo = $this->createMock(PaymentTransactionRepository::class);
        $txnRepo->method('sumRefundsForOrder')->willReturn($sumRefunds);
        $txnRepo->method('findLatestInitiateForOrder')->willReturn(
            $initialTxns[0] ?? null,
        );
        $txnRepo->method('save')->willReturnCallback(
            function (PaymentTransaction $t) use (&$sink): void {
                $sink[] = $t;
            },
        );

        $auditRepo = new class($this->recordedAuditLogs) extends \Doctrine\ORM\EntityRepository {
            public function __construct(private array &$sink) {}
            public function save(AuditLog $log): void { $this->sink[] = $log; }
            public function getClassName(): string { return AuditLog::class; }
        };

        $em = $this->stubEm(function ($em) use ($userRepo, $orderRepo, $txnRepo, $auditRepo) {
            $em->method('getRepository')->willReturnMap([
                [User::class, $userRepo],
                [Order::class, $orderRepo],
                [PaymentTransaction::class, $txnRepo],
                [AuditLog::class, $auditRepo],
            ]);
        });

        $this->bind(EntityManagerInterface::class, $em);
        $this->bind(AuditEmitter::class, new AuditEmitter($em, new NullLogger()));
        $this->bind(PaymentGatewayInterface::class, $gateway);
    }

    private function makePost(User $user, string $uri, array $body): ResponseInterface
    {
        $jwt = $this->app->getContainer()->get(JwtService::class);
        $pair = $jwt->issueTokenPair($user);
        return $this->handle($this->jsonRequest('POST', $uri, $body, [
            'Authorization' => 'Bearer ' . $pair->accessToken,
        ]));
    }

    private function setEntityId(object $entity, int $id): void
    {
        $ref = new \ReflectionProperty($entity::class, 'id');
        $ref->setAccessible(true);
        $ref->setValue($entity, $id);
    }

    private function setEntityProp(object $entity, string $prop, mixed $value): void
    {
        $ref = new \ReflectionProperty($entity::class, $prop);
        $ref->setAccessible(true);
        $ref->setValue($entity, $value);
    }

    private function makeAdminUser(int $id): User
    {
        $user = $this->makeUser(id: $id);
        $user->setRoles(admin: true);
        return $user;
    }

    private function makeOrder(User $user, int $id, string $reference, string $subtotal): Order
    {
        $order = new Order(user: $user, orderReference: $reference, subtotal: $subtotal);
        $this->setEntityId($order, $id);
        return $order;
    }

    private function makeTxn(
        Order $order,
        string $operation,
        string $status,
        string $amount,
        string $providerOrderRef,
    ): PaymentTransaction {
        return new PaymentTransaction(
            order: $order,
            operation: $operation,
            status: $status,
            amount: $amount,
            idempotencyKey: bin2hex(random_bytes(8)),
            providerOrderRef: $providerOrderRef,
        );
    }
}
