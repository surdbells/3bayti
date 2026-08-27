<?php

declare(strict_types=1);

namespace Bayti\Api\Tests\Http\Controllers\Order;

use Bayti\Api\Domain\Audit\AuditEmitter;
use Bayti\Api\Domain\Audit\AuditLog;
use Bayti\Api\Domain\Order\Order;
use Bayti\Api\Domain\Order\OrderRepository;
use Bayti\Api\Domain\Payment\PaymentTransaction;
use Bayti\Api\Domain\Payment\PaymentTransactionRepository;
use Bayti\Api\Domain\User\User;
use Bayti\Api\Domain\User\UserRepository;
use Bayti\Api\Http\Controllers\Order\CancelOrderController;
use Bayti\Api\Infrastructure\Auth\JwtService;
use Bayti\Api\Payment\PaymentGatewayInterface;
use Bayti\Api\Tests\Http\HttpTestCase;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use Psr\Http\Message\ResponseInterface;
use Psr\Log\NullLogger;

#[CoversClass(CancelOrderController::class)]
final class CancelOrderControllerTest extends HttpTestCase
{
    /** @var array<int, AuditLog> */
    private array $recordedAuditLogs = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->recordedAuditLogs = [];
    }

    #[Test]
    public function customerCancelsOwnPendingPaymentOrder(): void
    {
        $customer = $this->makeUser(id: 42);
        $order = $this->makeOrder($customer, id: 100, reference: 'V3-001', subtotal: '299.00');
        $this->setEntityProp($order, 'status', Order::STATUS_PENDING_PAYMENT);

        $gateway = $this->createMock(PaymentGatewayInterface::class);
        $gateway->expects(self::never())->method('refund');

        $this->bindEnvironment($customer, $order, $gateway);

        $response = $this->makePost($customer, '/v3/orders/100/cancel', [
            'reason' => 'changed my mind',
        ]);

        self::assertSame(200, $response->getStatusCode());
        self::assertSame(Order::STATUS_CANCELLED, $order->getStatus());

        $body = $this->jsonBody($response);
        self::assertFalse($body['cancellation']['refund_issued']);
    }

    #[Test]
    public function customerCancellingPaidOrderReturns422(): void
    {
        // Customer can't cancel a paid order, must contact support.
        $customer = $this->makeUser(id: 42);
        $order = $this->makeOrder($customer, id: 100, reference: 'V3-001', subtotal: '299.00');
        $this->setEntityProp($order, 'status', Order::STATUS_PAID);

        $gateway = $this->createMock(PaymentGatewayInterface::class);
        $gateway->expects(self::never())->method('refund');

        $this->bindEnvironment($customer, $order, $gateway);

        $response = $this->makePost($customer, '/v3/orders/100/cancel', [
            'reason' => 'too late',
        ]);

        self::assertSame(422, $response->getStatusCode());
        $body = $this->jsonBody($response);
        self::assertSame('cancellation_not_allowed', $body['error']['code']);
        self::assertStringContainsString('contact support', $body['error']['message']);
        // Order unchanged
        self::assertSame(Order::STATUS_PAID, $order->getStatus());
    }

    #[Test]
    public function crossUserAccessReturns404Not403(): void
    {
        // User A authenticates, tries to cancel User B's order
        $userA = $this->makeUser(id: 42);

        $userRepo = $this->createMock(UserRepository::class);
        $userRepo->method('findById')->willReturn($userA);

        $orderRepo = $this->createMock(OrderRepository::class);
        // findForUser scopes to the authenticated user; null = either
        // doesn't exist OR belongs to someone else
        $orderRepo->method('findForUser')->willReturn(null);

        $em = $this->stubEm(function ($em) use ($userRepo, $orderRepo) {
            $em->method('getRepository')->willReturnMap([
                [User::class, $userRepo],
                [Order::class, $orderRepo],
            ]);
        });
        $this->bind(EntityManagerInterface::class, $em);
        $this->bind(PaymentGatewayInterface::class, $this->createMock(PaymentGatewayInterface::class));

        $response = $this->makePost($userA, '/v3/orders/100/cancel', ['reason' => 'whatever']);

        self::assertSame(404, $response->getStatusCode(), 'Cross-user must be 404, not 403');
    }

    #[Test]
    public function reasonIsOptionalForCustomer(): void
    {
        $customer = $this->makeUser(id: 42);
        $order = $this->makeOrder($customer, id: 100, reference: 'V3-001', subtotal: '299.00');
        $this->setEntityProp($order, 'status', Order::STATUS_PENDING_PAYMENT);
        $gateway = $this->createMock(PaymentGatewayInterface::class);

        $this->bindEnvironment($customer, $order, $gateway);

        // Empty body, no reason
        $response = $this->makePost($customer, '/v3/orders/100/cancel', []);

        self::assertSame(200, $response->getStatusCode(),
            'Customer cancellation should not require a reason');
        self::assertSame(Order::STATUS_CANCELLED, $order->getStatus());
    }

    #[Test]
    public function idempotentRecancel(): void
    {
        $customer = $this->makeUser(id: 42);
        $order = $this->makeOrder($customer, id: 100, reference: 'V3-001', subtotal: '299.00');
        $this->setEntityProp($order, 'status', Order::STATUS_CANCELLED);

        $gateway = $this->createMock(PaymentGatewayInterface::class);
        $this->bindEnvironment($customer, $order, $gateway);

        $response = $this->makePost($customer, '/v3/orders/100/cancel', ['reason' => 'retry']);
        self::assertSame(200, $response->getStatusCode());
        $body = $this->jsonBody($response);
        self::assertTrue($body['cancellation']['was_already_cancelled']);
    }

    // ===== Helpers =====

    private function bindEnvironment(
        User $user,
        Order $order,
        PaymentGatewayInterface $gateway,
    ): void {
        $userRepo = $this->createMock(UserRepository::class);
        $userRepo->method('findById')->willReturn($user);

        $orderRepo = $this->createMock(OrderRepository::class);
        $orderRepo->method('findForUser')->willReturn($order);

        $txnRepo = $this->createMock(PaymentTransactionRepository::class);
        $txnRepo->method('sumRefundsForOrder')->willReturn('0.00');
        $txnRepo->method('findLatestInitiateForOrder')->willReturn(null);

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

    private function makeOrder(User $user, int $id, string $reference, string $subtotal): Order
    {
        $order = new Order(user: $user, orderReference: $reference, subtotal: $subtotal);
        $this->setEntityId($order, $id);
        return $order;
    }
}
