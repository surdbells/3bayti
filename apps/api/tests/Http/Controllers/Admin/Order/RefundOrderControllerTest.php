<?php

declare(strict_types=1);

namespace Bayti\Api\Tests\Http\Controllers\Admin\Order;

use Bayti\Api\Domain\Audit\AuditEmitter;
use Bayti\Api\Domain\Audit\AuditLog;
use Bayti\Api\Domain\Order\Order;
use Bayti\Api\Domain\Order\OrderRepository;
use Bayti\Api\Domain\Payment\PaymentTransaction;
use Bayti\Api\Domain\Payment\PaymentTransactionRepository;
use Bayti\Api\Domain\User\User;
use Bayti\Api\Domain\User\UserRepository;
use Bayti\Api\Http\Controllers\Admin\Order\RefundOrderController;
use Bayti\Api\Infrastructure\Auth\JwtService;
use Bayti\Api\Payment\OrderStatusResponse;
use Bayti\Api\Payment\PaymentGatewayException;
use Bayti\Api\Payment\PaymentGatewayInterface;
use Bayti\Api\Tests\Http\HttpTestCase;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use Psr\Http\Message\ResponseInterface;
use Psr\Log\NullLogger;

#[CoversClass(RefundOrderController::class)]
final class RefundOrderControllerTest extends HttpTestCase
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
    public function fullRefundOfPaidOrder(): void
    {
        $admin = $this->makeAdminUser(99);
        $customer = $this->makeUser(id: 42);
        $order = $this->makeOrder($customer, id: 100, reference: 'V3-001', subtotal: '299.00');
        $this->setEntityProp($order, 'status', Order::STATUS_DELIVERED);

        $initiateTxn = $this->makeTxn($order, 'INITIATE', 'Initiated', '299.00', 'NOON-REF-1');

        $gateway = $this->createMock(PaymentGatewayInterface::class);
        $gateway->expects(self::once())
            ->method('refund')
            ->with('NOON-REF-1', '299.00', 'AED', self::stringContains('customer requested'))
            ->willReturn(new OrderStatusResponse(
                providerOrderRef: 'NOON-REF-1',
                status: 'Refunded',
                terminal: true,
                paid: false,
                amount: '299.00',
                currency: 'AED',
                rawResponse: ['result' => ['transactionId' => 'REFUND-001']],
            ));

        $this->bindEnvironment($admin, $order, [$initiateTxn], '0.00', $gateway);

        $response = $this->makePost($admin, '/v3/admin/orders/100/refund', [
            'reason' => 'customer requested',
        ]);

        self::assertSame(200, $response->getStatusCode());
        $body = $this->jsonBody($response);
        self::assertSame('299.00', $body['refund']['amount']);
        self::assertTrue($body['refund']['is_full_refund']);
        // Order rolled to REFUNDED
        self::assertSame(Order::STATUS_REFUNDED, $order->getStatus());

        // REFUND transaction saved
        $refundTxns = array_filter(
            $this->savedTransactions,
            static fn (PaymentTransaction $t) => $t->getOperation() === 'REFUND',
        );
        self::assertCount(1, $refundTxns);

        // Audit recorded
        self::assertGreaterThan(0, count($this->recordedAuditLogs));
        $lastAudit = end($this->recordedAuditLogs);
        self::assertSame(AuditLog::ACTION_OVERRIDDEN, $lastAudit->getAction());
        $changes = $lastAudit->getChanges();
        self::assertSame('299.00', $changes['refund_amount']);
        self::assertTrue($changes['is_full_refund']);
    }

    #[Test]
    public function partialRefundDoesNotMarkOrderRefunded(): void
    {
        $admin = $this->makeAdminUser(99);
        $customer = $this->makeUser(id: 42);
        $order = $this->makeOrder($customer, id: 100, reference: 'V3-001', subtotal: '299.00');
        $this->setEntityProp($order, 'status', Order::STATUS_DELIVERED);

        $initiateTxn = $this->makeTxn($order, 'INITIATE', 'Initiated', '299.00', 'NOON-REF-1');

        $gateway = $this->createMock(PaymentGatewayInterface::class);
        $gateway->expects(self::once())
            ->method('refund')
            ->with('NOON-REF-1', '50.00', 'AED', self::anything())
            ->willReturn(new OrderStatusResponse(
                providerOrderRef: 'NOON-REF-1',
                status: 'PartiallyRefunded',
                terminal: false,
                paid: true,
                amount: '50.00',
                currency: 'AED',
                rawResponse: [],
            ));

        $this->bindEnvironment($admin, $order, [$initiateTxn], '0.00', $gateway);

        $response = $this->makePost($admin, '/v3/admin/orders/100/refund', [
            'amount' => '50.00',
            'reason' => 'partial refund for damaged item',
        ]);

        self::assertSame(200, $response->getStatusCode());
        $body = $this->jsonBody($response);
        self::assertSame('50.00', $body['refund']['amount']);
        self::assertFalse($body['refund']['is_full_refund']);
        // Order stays DELIVERED (not REFUNDED)
        self::assertSame(Order::STATUS_DELIVERED, $order->getStatus());
    }

    #[Test]
    public function refundExceedingBalanceReturns422(): void
    {
        $admin = $this->makeAdminUser(99);
        $customer = $this->makeUser(id: 42);
        $order = $this->makeOrder($customer, id: 100, reference: 'V3-001', subtotal: '299.00');
        $this->setEntityProp($order, 'status', Order::STATUS_DELIVERED);

        $initiateTxn = $this->makeTxn($order, 'INITIATE', 'Initiated', '299.00', 'NOON-REF-1');

        // Already refunded 200, so remaining is 99. Asking for 200 should fail.
        $gateway = $this->createMock(PaymentGatewayInterface::class);
        $gateway->expects(self::never())->method('refund');

        $this->bindEnvironment($admin, $order, [$initiateTxn], '200.00', $gateway);

        $response = $this->makePost($admin, '/v3/admin/orders/100/refund', [
            'amount' => '200.00',
            'reason' => 'attempted over-refund',
        ]);

        self::assertSame(422, $response->getStatusCode());
        $body = $this->jsonBody($response);
        self::assertSame('refund_exceeds_balance', $body['error']['code']);
        self::assertSame('99.00', $body['error']['details']['refundable']);
        self::assertSame('200.00', $body['error']['details']['already_refunded']);
    }

    #[Test]
    public function refundOnFullyRefundedOrderReturns422(): void
    {
        $admin = $this->makeAdminUser(99);
        $customer = $this->makeUser(id: 42);
        $order = $this->makeOrder($customer, id: 100, reference: 'V3-001', subtotal: '299.00');
        $this->setEntityProp($order, 'status', Order::STATUS_REFUNDED);

        $initiateTxn = $this->makeTxn($order, 'INITIATE', 'Initiated', '299.00', 'NOON-REF-1');

        $gateway = $this->createMock(PaymentGatewayInterface::class);
        $gateway->expects(self::never())->method('refund');

        // Already refunded full amount
        $this->bindEnvironment($admin, $order, [$initiateTxn], '299.00', $gateway);

        $response = $this->makePost($admin, '/v3/admin/orders/100/refund', [
            'reason' => 'second refund attempt',
        ]);

        self::assertSame(422, $response->getStatusCode());
        $body = $this->jsonBody($response);
        self::assertSame('no_refundable_balance', $body['error']['code']);
    }

    #[Test]
    public function refundOnNonRefundableStatusReturns422(): void
    {
        $admin = $this->makeAdminUser(99);
        $customer = $this->makeUser(id: 42);
        $order = $this->makeOrder($customer, id: 100, reference: 'V3-001', subtotal: '299.00');
        // pending_payment is not refundable
        self::assertSame(Order::STATUS_PENDING_PAYMENT, $order->getStatus());

        $gateway = $this->createMock(PaymentGatewayInterface::class);
        $gateway->expects(self::never())->method('refund');

        $this->bindEnvironment($admin, $order, [], '0.00', $gateway);

        $response = $this->makePost($admin, '/v3/admin/orders/100/refund', [
            'reason' => 'attempted refund on unpaid order',
        ]);

        self::assertSame(422, $response->getStatusCode());
        $body = $this->jsonBody($response);
        self::assertSame('order_not_refundable', $body['error']['code']);
    }

    #[Test]
    public function gatewayFailureReturns502AndPersistsFailedTransaction(): void
    {
        $admin = $this->makeAdminUser(99);
        $customer = $this->makeUser(id: 42);
        $order = $this->makeOrder($customer, id: 100, reference: 'V3-001', subtotal: '299.00');
        $this->setEntityProp($order, 'status', Order::STATUS_DELIVERED);

        $initiateTxn = $this->makeTxn($order, 'INITIATE', 'Initiated', '299.00', 'NOON-REF-1');

        $gateway = $this->createMock(PaymentGatewayInterface::class);
        $gateway->method('refund')->willThrowException(
            new PaymentGatewayException(
                kind: PaymentGatewayException::KIND_UPSTREAM,
                message: 'Noon rejected refund: card closed',
            ),
        );

        $this->bindEnvironment($admin, $order, [$initiateTxn], '0.00', $gateway);

        $response = $this->makePost($admin, '/v3/admin/orders/100/refund', [
            'reason' => 'customer requested',
        ]);

        self::assertSame(502, $response->getStatusCode());
        $body = $this->jsonBody($response);
        self::assertSame('gateway_refund_failed', $body['error']['code']);

        // Failed transaction persisted for forensics
        $failedTxns = array_filter(
            $this->savedTransactions,
            static fn (PaymentTransaction $t) => $t->getOperation() === 'REFUND' && $t->getStatus() === 'Failed',
        );
        self::assertCount(1, $failedTxns);

        // Order NOT marked refunded
        self::assertSame(Order::STATUS_DELIVERED, $order->getStatus());
    }

    #[Test]
    public function missingReasonReturns422(): void
    {
        $admin = $this->makeAdminUser(99);
        $customer = $this->makeUser(id: 42);
        $order = $this->makeOrder($customer, id: 100, reference: 'V3-001', subtotal: '299.00');

        $gateway = $this->createMock(PaymentGatewayInterface::class);
        $this->bindEnvironment($admin, $order, [], '0.00', $gateway);

        $response = $this->makePost($admin, '/v3/admin/orders/100/refund', []);
        self::assertSame(422, $response->getStatusCode());
    }

    #[Test]
    public function invalidAmountFormatReturns422(): void
    {
        $admin = $this->makeAdminUser(99);
        $customer = $this->makeUser(id: 42);
        $order = $this->makeOrder($customer, id: 100, reference: 'V3-001', subtotal: '299.00');

        $gateway = $this->createMock(PaymentGatewayInterface::class);
        $this->bindEnvironment($admin, $order, [], '0.00', $gateway);

        $response = $this->makePost($admin, '/v3/admin/orders/100/refund', [
            'amount' => 'not-a-decimal',
            'reason' => 'test',
        ]);
        self::assertSame(422, $response->getStatusCode());
    }

    // ===== Helpers =====

    private function makeAdminUser(int $id): User
    {
        $user = $this->makeUser(id: $id);
        $user->setRoles(admin: true);
        return $user;
    }

    private function bindEnvironment(
        User $admin,
        Order $order,
        array $initialTxns,
        string $sumRefunds,
        PaymentGatewayInterface $gateway,
    ): EntityManagerInterface {
        $userRepo = $this->createMock(UserRepository::class);
        $userRepo->method('findById')->willReturn($admin);

        $orderRepo = $this->createMock(OrderRepository::class);
        $orderRepo->method('findByIdForAdmin')->willReturn($order);

        // Mock txn repository: returns initial transactions + collects new ones
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

        // Capturing audit repo
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
        return $em;
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
