<?php

declare(strict_types=1);

namespace Bayti\Api\Tests\Notification\Push;

use Bayti\Api\Domain\Notification\DeviceToken;
use Bayti\Api\Domain\Notification\DeviceTokenRepository;
use Bayti\Api\Domain\Order\Order;
use Bayti\Api\Domain\User\User;
use Bayti\Api\Notification\Push\InMemoryPushSender;
use Bayti\Api\Notification\Push\PushException;
use Bayti\Api\Notification\Push\PushNotificationService;
use Bayti\Api\Notification\Push\PushSenderInterface;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

#[CoversClass(PushNotificationService::class)]
final class PushNotificationServiceTest extends TestCase
{
    private function setProp(object $obj, string $prop, mixed $value): void
    {
        $ref = new \ReflectionProperty($obj::class, $prop);
        $ref->setAccessible(true);
        $ref->setValue($obj, $value);
    }

    private function makeUser(int $id = 42): User
    {
        $user = new User('customer@example.com', '+971501234567', password_hash('p', PASSWORD_BCRYPT), 'AE');
        $this->setProp($user, 'id', $id);
        return $user;
    }

    private function makeOrder(User $user, string $reference = 'V3-PUSH-1'): Order
    {
        $order = new Order(user: $user, orderReference: $reference, subtotal: '99.00');
        $this->setProp($order, 'id', 100);
        return $order;
    }

    private function makeToken(User $user, string $token, string $platform = DeviceToken::PLATFORM_IOS): DeviceToken
    {
        return new DeviceToken($user, $token, $platform);
    }

    /**
     * Wire a service with a mocked EM whose DeviceToken repository
     * returns the supplied active tokens.
     *
     * @param list<DeviceToken> $tokens
     */
    private function serviceWith(array $tokens, PushSenderInterface $sender, ?int &$flushCount = null): PushNotificationService
    {
        $repo = $this->createMock(DeviceTokenRepository::class);
        $repo->method('findActiveForUser')->willReturn($tokens);

        $em = $this->createMock(EntityManagerInterface::class);
        $em->method('getRepository')->willReturn($repo);
        if ($flushCount !== null) {
            $flushCount = 0;
            $em->method('flush')->willReturnCallback(function () use (&$flushCount): void {
                $flushCount++;
            });
        }

        return new PushNotificationService(
            sender: $sender,
            logger: new NullLogger(),
            em: $em,
        );
    }

    #[Test]
    public function fansOutToEveryActiveTokenForTheCustomer(): void
    {
        $user = $this->makeUser();
        $tokens = [
            $this->makeToken($user, 'tok-ios'),
            $this->makeToken($user, 'tok-android', DeviceToken::PLATFORM_ANDROID),
        ];
        $sender = new InMemoryPushSender();
        $service = $this->serviceWith($tokens, $sender);

        $service->orderPaid($this->makeOrder($user));

        self::assertSame(['tok-ios', 'tok-android'], $sender->tokensSent());
        $first = $sender->sent()[0]['message'];
        self::assertSame('Payment confirmed', $first->title);
        self::assertSame('order.paid', $first->data['type']);
        self::assertSame('100', $first->data['order_id']);
        self::assertSame('V3-PUSH-1', $first->data['order_reference']);
    }

    #[Test]
    public function noTokensIsANoOp(): void
    {
        $user = $this->makeUser();
        $sender = new InMemoryPushSender();
        $service = $this->serviceWith([], $sender);

        $service->orderPaid($this->makeOrder($user));

        self::assertCount(0, $sender->sent());
    }

    #[Test]
    public function eachLifecycleEventCarriesItsType(): void
    {
        $user = $this->makeUser();
        $cases = [
            ['orderPlaced', 'order.placed', 'Order received'],
            ['orderPaid', 'order.paid', 'Payment confirmed'],
            ['orderPaymentFailed', 'order.payment_failed', 'Payment failed'],
            ['itemShipped', 'order.shipped', 'Your order has shipped'],
            ['itemDelivered', 'order.delivered', 'Order delivered'],
            ['orderCancelled', 'order.cancelled', 'Order cancelled'],
            ['orderRefunded', 'order.refunded', 'Refund issued'],
        ];

        foreach ($cases as [$method, $expectedType, $expectedTitle]) {
            $sender = new InMemoryPushSender();
            $service = $this->serviceWith([$this->makeToken($user, 'tok')], $sender);
            $service->{$method}($this->makeOrder($user));

            self::assertCount(1, $sender->sent(), "$method should send once");
            $msg = $sender->sent()[0]['message'];
            self::assertSame($expectedType, $msg->data['type'], "$method type");
            self::assertSame($expectedTitle, $msg->title, "$method title");
        }
    }

    #[Test]
    public function oneTokenFailingDoesNotStopTheOthers(): void
    {
        $user = $this->makeUser();
        $tokens = [
            $this->makeToken($user, 'tok-bad'),
            $this->makeToken($user, 'tok-good'),
        ];
        $sender = new InMemoryPushSender();
        // First token fails with a transient (non-dead) error.
        $sender->failToken('tok-bad', PushException::KIND_NETWORK);

        $service = $this->serviceWith($tokens, $sender);
        $service->orderPaid($this->makeOrder($user));

        // The good token still received its push.
        self::assertSame(['tok-good'], $sender->tokensSent());
    }

    #[Test]
    public function deadTokenIsDeactivatedAndFlushed(): void
    {
        $user = $this->makeUser();
        $deadToken = $this->makeToken($user, 'tok-dead');
        $liveToken = $this->makeToken($user, 'tok-live');
        self::assertTrue($deadToken->isActive());

        $sender = new InMemoryPushSender();
        $sender->failToken('tok-dead', PushException::KIND_UNREGISTERED);

        $flushCount = 0;
        $service = $this->serviceWith([$deadToken, $liveToken], $sender, $flushCount);
        $service->orderPaid($this->makeOrder($user));

        // Dead token deactivated + persisted; live token unaffected + pushed.
        self::assertFalse($deadToken->isActive(), 'UNREGISTERED token should be deactivated');
        self::assertTrue($liveToken->isActive());
        self::assertSame(['tok-live'], $sender->tokensSent());
        self::assertGreaterThanOrEqual(1, $flushCount, 'pruning should flush the deactivation');
    }

    #[Test]
    public function transientFailureDoesNotDeactivateToken(): void
    {
        $user = $this->makeUser();
        $token = $this->makeToken($user, 'tok-net');
        $sender = new InMemoryPushSender();
        $sender->failToken('tok-net', PushException::KIND_NETWORK);

        $service = $this->serviceWith([$token], $sender);
        $service->orderPaid($this->makeOrder($user));

        // A network blip must NOT prune the token (it may recover).
        self::assertTrue($token->isActive());
    }

    #[Test]
    public function withoutEntityManagerItIsASafeNoOp(): void
    {
        // No EM → cannot resolve tokens → no sends, no throw.
        $sender = new InMemoryPushSender();
        $service = new PushNotificationService(sender: $sender, logger: new NullLogger(), em: null);

        $service->orderPaid($this->makeOrder($this->makeUser()));

        self::assertCount(0, $sender->sent());
    }
}
