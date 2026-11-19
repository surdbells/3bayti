<?php

declare(strict_types=1);

namespace Bayti\Api\Tests\Domain\Order;

use Bayti\Api\Domain\Audit\AuditEmitter;
use Bayti\Api\Domain\Audit\AuditLog;
use Bayti\Api\Domain\GiftCard\GiftCardOrderReversal;
use Bayti\Api\Domain\Order\CancelOrderService;
use Bayti\Api\Payment\OrderStatusResponse;
use Bayti\Api\Domain\Order\Order;
use Bayti\Api\Domain\Payment\PaymentTransaction;
use Bayti\Api\Domain\Payment\PaymentTransactionRepository;
use Bayti\Api\Domain\User\User;
use Bayti\Api\Notification\LocaleResolver;
use Bayti\Api\Notification\MailerInterface;
use Bayti\Api\Notification\OrderEmailTemplateRenderer;
use Bayti\Api\Notification\OrderNotificationService;
use Bayti\Api\Notification\Push\PushNotificationService;
use Bayti\Api\Notification\Push\PushSenderInterface;
use Bayti\Api\Payment\PaymentGatewayInterface;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Log\NullLogger;
use Slim\Psr7\Factory\ServerRequestFactory;

/**
 * Regression coverage for issue #7: cancelling a pending_payment order
 * returned "An unexpected error occurred" even though the cancel had
 * already committed. Root cause: the post-commit notification fan-out
 * could throw an uncaught \Throwable, which ApiErrorMiddleware turned
 * into a generic 500.
 *
 * The fix wraps both fan-outs in try/catch(\Throwable). These tests pin
 * that behavior: a notification dependency that throws must NOT prevent
 * cancel() from returning a success result with the order flipped to
 * CANCELLED.
 */
#[CoversClass(CancelOrderService::class)]
final class CancelOrderServiceTest extends TestCase
{
    #[Test]
    public function pendingPaymentCancelSucceedsWhenNotificationFanOutThrows(): void
    {
        $user = $this->makeUser();
        $order = $this->makeOrder($user);
        $this->setProp($order, 'status', Order::STATUS_PENDING_PAYMENT);

        // The push fan-out (second call in the post-commit fan-out) throws
        // an uncaught \Throwable, simulating the issue-#7 fault that
        // ApiErrorMiddleware turned into a generic 500.
        $service = $this->makeService(
            order: $order,
            notifications: $this->noopEmailService(),
            push: $this->throwingPushService(),
        );

        $result = $service->cancel(
            order: $order,
            actor: $user,
            request: $this->makeRequest(),
            reason: 'changed my mind',
            allowedFromAdmin: false,
        );

        // The whole point: a throwing notification fan-out must not turn a
        // committed cancel into an error. cancel() returns success and the
        // order is flipped to CANCELLED.
        self::assertFalse($result->wasAlreadyCancelled);
        self::assertFalse($result->refundIssued);
        self::assertSame(Order::STATUS_CANCELLED, $order->getStatus());
    }

    #[Test]
    public function pendingPaymentCancelSucceedsWhenNotificationsAllSucceed(): void
    {
        // Baseline: nothing throws, cancel succeeds. Guards against the
        // try/catch accidentally swallowing the success path.
        $user = $this->makeUser();
        $order = $this->makeOrder($user);
        $this->setProp($order, 'status', Order::STATUS_PENDING_PAYMENT);

        $service = $this->makeService(
            order: $order,
            notifications: $this->noopEmailService(),
            push: $this->noopPushService(),
        );

        $result = $service->cancel(
            order: $order,
            actor: $user,
            request: $this->makeRequest(),
            reason: 'oops',
            allowedFromAdmin: false,
        );

        self::assertFalse($result->wasAlreadyCancelled);
        self::assertSame(Order::STATUS_CANCELLED, $order->getStatus());
    }

    // ===== Gift-card balance goes back on cancel =====

    #[Test]
    public function pendingPaymentCancelGivesTheGiftCardBalanceBack(): void
    {
        $user = $this->makeUser();
        $order = $this->makeOrder($user);
        $this->setProp($order, 'status', Order::STATUS_PENDING_PAYMENT);
        $order->applyGiftCard('40.00', 'ABCD1234ABCD1234');

        $reversal = $this->createMock(GiftCardOrderReversal::class);
        $reversal->expects(self::once())
            ->method('restoreForOrder')
            ->with($order, self::stringContains('changed my mind'))
            ->willReturn('40.00');

        $service = $this->makeService(
            order: $order,
            notifications: $this->noopEmailService(),
            push: $this->noopPushService(),
            reversal: $reversal,
        );

        $result = $service->cancel($order, $user, $this->makeRequest(), 'changed my mind', false);

        self::assertSame(Order::STATUS_CANCELLED, $order->getStatus());
        self::assertSame('40.00', $result->giftCardRestored);
        self::assertFalse($result->refundIssued);
    }

    #[Test]
    public function paidOrderCoveredEntirelyByGiftCardCancelsWithoutAGatewayRefund(): void
    {
        // Previously threw "Order has no payment record": a gift-card-only
        // order never went to Noon. It must cancel and restore the card.
        $admin = $this->makeUser(1);
        $order = $this->makeOrder($this->makeUser());
        $order->applyGiftCard('99.00', 'ABCD1234ABCD1234');
        $this->setProp($order, 'status', Order::STATUS_PAID);

        $gateway = $this->createMock(PaymentGatewayInterface::class);
        $gateway->expects(self::never())->method('refund');

        $reversal = $this->createMock(GiftCardOrderReversal::class);
        $reversal->expects(self::once())->method('restoreForOrder')->willReturn('99.00');

        $service = $this->makeService(
            order: $order,
            notifications: $this->noopEmailService(),
            push: $this->noopPushService(),
            reversal: $reversal,
            gateway: $gateway,
        );

        $result = $service->cancel($order, $admin, $this->makeRequest(), 'out of stock', true);

        self::assertSame(Order::STATUS_CANCELLED, $order->getStatus());
        self::assertFalse($result->refundIssued);
        self::assertSame('99.00', $result->giftCardRestored);
    }

    #[Test]
    public function paidSplitOrderRefundsOnlyTheCardPartThroughNoon(): void
    {
        // 99.00 total, 40.00 paid by gift card → only 59.00 was charged to
        // the card, so only 59.00 may go back through Noon (not 99.00).
        $admin = $this->makeUser(1);
        $order = $this->makeOrder($this->makeUser());
        $order->applyGiftCard('40.00', 'ABCD1234ABCD1234');
        $this->setProp($order, 'status', Order::STATUS_PAID);

        $initiate = $this->createMock(PaymentTransaction::class);
        $initiate->method('getProviderOrderRef')->willReturn('NOON-1');

        $gateway = $this->createMock(PaymentGatewayInterface::class);
        $gateway->expects(self::once())
            ->method('refund')
            ->with('NOON-1', '59.00', 'AED', self::anything())
            ->willReturn(new OrderStatusResponse(
                providerOrderRef: 'NOON-1',
                status: 'Refunded',
                terminal: true,
                paid: false,
                amount: '59.00',
                currency: 'AED',
                rawResponse: [],
            ));

        $reversal = $this->createMock(GiftCardOrderReversal::class);
        $reversal->expects(self::once())->method('restoreForOrder')->willReturn('40.00');

        $service = $this->makeService(
            order: $order,
            notifications: $this->noopEmailService(),
            push: $this->noopPushService(),
            reversal: $reversal,
            gateway: $gateway,
            initiate: $initiate,
        );

        $result = $service->cancel($order, $admin, $this->makeRequest(), 'vendor rejected', true);

        self::assertSame(Order::STATUS_CANCELLED, $order->getStatus());
        self::assertTrue($result->refundIssued);
        self::assertSame('59.00', $result->refundAmount);
        self::assertSame('40.00', $result->giftCardRestored);
    }

    // ===== Builders =====

    private function makeService(
        Order $order,
        OrderNotificationService $notifications,
        PushNotificationService $push,
        ?GiftCardOrderReversal $reversal = null,
        ?PaymentGatewayInterface $gateway = null,
        ?PaymentTransaction $initiate = null,
    ): CancelOrderService {
        $txnRepo = $this->createMock(PaymentTransactionRepository::class);
        $txnRepo->method('sumRefundsForOrder')->willReturn('0.00');
        $txnRepo->method('findLatestInitiateForOrder')->willReturn($initiate);
        $txnRepo->method('save');

        if ($reversal === null) {
            $reversal = $this->createMock(GiftCardOrderReversal::class);
            $reversal->method('restoreForOrder')->willReturn('0.00');
        }

        $auditRepo = new class extends \Doctrine\ORM\EntityRepository {
            public function __construct()
            {
            }

            public function save(AuditLog $log): void
            {
            }

            public function getClassName(): string
            {
                return AuditLog::class;
            }
        };

        $em = $this->createMock(EntityManagerInterface::class);
        $em->method('getRepository')->willReturnMap([
            [PaymentTransaction::class, $txnRepo],
            [AuditLog::class, $auditRepo],
        ]);
        $em->method('flush');

        return new CancelOrderService(
            em: $em,
            gateway: $gateway ?? $this->createMock(PaymentGatewayInterface::class),
            audit: new AuditEmitter($em, new NullLogger()),
            notifications: $notifications,
            pushNotifications: $push,
            logger: new NullLogger(),
            giftCardReversal: $reversal,
        );
    }

    /**
     * A real OrderNotificationService that performs no actual sends for our
     * test order: the order carries no items (vendor fan-out is empty) and
     * the customer email-send path is exercised but harmless because the
     * mailer is a no-op mock and em=null disables log persistence.
     *
     * OrderEmailTemplateRenderer is final (un-mockable); we never reach a
     * render() call that matters here, but the constructor needs an
     * instance, so we build one without invoking its constructor.
     */
    private function noopEmailService(): OrderNotificationService
    {
        $renderer = (new \ReflectionClass(OrderEmailTemplateRenderer::class))
            ->newInstanceWithoutConstructor();

        return new OrderNotificationService(
            mailer: $this->createMock(MailerInterface::class),
            renderer: $renderer,
            adminRecipients: [],
            logger: new NullLogger(),
            em: null,
        );
    }

    private function noopPushService(): PushNotificationService
    {
        // Real service with no EM → activeTokensFor returns [] → no sends.
        return new PushNotificationService(
            sender: $this->createMock(PushSenderInterface::class),
            localeResolver: new LocaleResolver(),
            logger: new NullLogger(),
            em: null,
        );
    }

    /**
     * A PushNotificationService double whose orderCancelled() throws an
     * uncaught \Throwable from outside the service's own per-token guards -
     * standing in for any dependency fault (e.g. a null-deref on a
     * never-paid order) that escapes the fan-out. CancelOrderService must
     * catch it. PushNotificationService is not final, so we subclass it.
     */
    private function throwingPushService(): PushNotificationService
    {
        return new class ($this->createMock(PushSenderInterface::class), new LocaleResolver()) extends PushNotificationService {
            public function orderCancelled(Order $order): void
            {
                throw new \RuntimeException('push fan-out exploded');
            }
        };
    }

    private function makeRequest(): ServerRequestInterface
    {
        return (new ServerRequestFactory())->createServerRequest('POST', '/v3/orders/100/cancel');
    }

    private function makeUser(int $id = 42): User
    {
        $user = new User('customer@example.com', '+971501234567', password_hash('p', PASSWORD_BCRYPT), 'AE');
        $this->setProp($user, 'id', $id);
        return $user;
    }

    private function makeOrder(User $user, string $reference = 'V3-CANCEL-1'): Order
    {
        $order = new Order(user: $user, orderReference: $reference, subtotal: '99.00');
        $this->setProp($order, 'id', 100);
        return $order;
    }

    private function setProp(object $obj, string $prop, mixed $value): void
    {
        $ref = new \ReflectionProperty($obj::class, $prop);
        $ref->setAccessible(true);
        $ref->setValue($obj, $value);
    }
}
