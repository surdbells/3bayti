<?php

declare(strict_types=1);

namespace Bayti\Api\Tests\Http\Controllers\Checkout;

use Bayti\Api\Domain\Order\Order;
use Bayti\Api\Domain\Order\OrderRepository;
use Bayti\Api\Domain\User\User;
use Bayti\Api\Domain\User\UserRepository;
use Bayti\Api\Http\Controllers\Checkout\GetCheckoutStatusController;
use Bayti\Api\Infrastructure\Auth\JwtService;
use Bayti\Api\Payment\PaymentGatewayInterface;
use Bayti\Api\Tests\Http\HttpTestCase;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;

#[CoversClass(GetCheckoutStatusController::class)]
final class GetCheckoutStatusControllerTest extends HttpTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        // Default gateway stub, controller doesn't use it but the
        // /v3/checkout group's siblings do; DI graph needs it.
        $this->bind(PaymentGatewayInterface::class, $this->createMock(PaymentGatewayInterface::class));
    }

    #[Test]
    public function returnsPendingStatusForPendingPaymentOrder(): void
    {
        $user = $this->makeUser(id: 7);
        $order = new Order(user: $user, orderReference: 'V3-PENDING-001', subtotal: '299.00');
        $this->setEntityId($order, 100);

        $userRepo = $this->createMock(UserRepository::class);
        $userRepo->method('findById')->with(7)->willReturn($user);

        $orderRepo = $this->createMock(OrderRepository::class);
        $orderRepo->method('findByOrderReference')->with('V3-PENDING-001')->willReturn($order);

        $em = $this->stubEm(function ($em) use ($userRepo, $orderRepo) {
            $em->method('getRepository')->willReturnMap([
                [User::class, $userRepo],
                [Order::class, $orderRepo],
            ]);
        });
        $this->bind(EntityManagerInterface::class, $em);

        $jwt = $this->app->getContainer()->get(JwtService::class);
        $pair = $jwt->issueTokenPair($user);

        $response = $this->handle(
            $this->jsonRequest('GET', '/v3/checkout/status/V3-PENDING-001', [], [
                'Authorization' => 'Bearer ' . $pair->accessToken,
            ])
        );

        self::assertSame(200, $response->getStatusCode());
        $body = $this->jsonBody($response);

        self::assertSame('V3-PENDING-001', $body['order_reference']);
        self::assertSame(100, $body['order_id']);
        self::assertSame(Order::STATUS_PENDING_PAYMENT, $body['status']);
        self::assertFalse($body['terminal']);
        self::assertFalse($body['paid']);
        self::assertSame('299.00', $body['total']);
        self::assertNull($body['paid_at']);
    }

    #[Test]
    public function returnsPaidStatusAfterMarkPaid(): void
    {
        $user = $this->makeUser(id: 7);
        $order = new Order(user: $user, orderReference: 'V3-PAID-001', subtotal: '299.00');
        $this->setEntityId($order, 100);
        $order->markPaid();

        $userRepo = $this->createMock(UserRepository::class);
        $userRepo->method('findById')->with(7)->willReturn($user);

        $orderRepo = $this->createMock(OrderRepository::class);
        $orderRepo->method('findByOrderReference')->with('V3-PAID-001')->willReturn($order);

        $em = $this->stubEm(function ($em) use ($userRepo, $orderRepo) {
            $em->method('getRepository')->willReturnMap([
                [User::class, $userRepo],
                [Order::class, $orderRepo],
            ]);
        });
        $this->bind(EntityManagerInterface::class, $em);

        $jwt = $this->app->getContainer()->get(JwtService::class);
        $pair = $jwt->issueTokenPair($user);

        $response = $this->handle(
            $this->jsonRequest('GET', '/v3/checkout/status/V3-PAID-001', [], [
                'Authorization' => 'Bearer ' . $pair->accessToken,
            ])
        );

        self::assertSame(200, $response->getStatusCode());
        $body = $this->jsonBody($response);

        self::assertSame(Order::STATUS_PAID, $body['status']);
        self::assertTrue($body['terminal']);
        self::assertTrue($body['paid']);
        self::assertNotNull($body['paid_at']);
    }

    #[Test]
    public function returnsFailedStatusForFailedOrder(): void
    {
        $user = $this->makeUser(id: 7);
        $order = new Order(user: $user, orderReference: 'V3-FAIL-001', subtotal: '299.00');
        $this->setEntityId($order, 100);
        $order->markFailed();

        $userRepo = $this->createMock(UserRepository::class);
        $userRepo->method('findById')->with(7)->willReturn($user);

        $orderRepo = $this->createMock(OrderRepository::class);
        $orderRepo->method('findByOrderReference')->with('V3-FAIL-001')->willReturn($order);

        $em = $this->stubEm(function ($em) use ($userRepo, $orderRepo) {
            $em->method('getRepository')->willReturnMap([
                [User::class, $userRepo],
                [Order::class, $orderRepo],
            ]);
        });
        $this->bind(EntityManagerInterface::class, $em);

        $jwt = $this->app->getContainer()->get(JwtService::class);
        $pair = $jwt->issueTokenPair($user);

        $response = $this->handle(
            $this->jsonRequest('GET', '/v3/checkout/status/V3-FAIL-001', [], [
                'Authorization' => 'Bearer ' . $pair->accessToken,
            ])
        );

        self::assertSame(200, $response->getStatusCode());
        $body = $this->jsonBody($response);

        self::assertSame(Order::STATUS_FAILED, $body['status']);
        self::assertTrue($body['terminal']);
        self::assertFalse($body['paid']);
    }

    #[Test]
    public function returns404ForUnknownReference(): void
    {
        $user = $this->makeUser(id: 7);

        $userRepo = $this->createMock(UserRepository::class);
        $userRepo->method('findById')->with(7)->willReturn($user);

        $orderRepo = $this->createMock(OrderRepository::class);
        $orderRepo->method('findByOrderReference')->willReturn(null);

        $em = $this->stubEm(function ($em) use ($userRepo, $orderRepo) {
            $em->method('getRepository')->willReturnMap([
                [User::class, $userRepo],
                [Order::class, $orderRepo],
            ]);
        });
        $this->bind(EntityManagerInterface::class, $em);

        $jwt = $this->app->getContainer()->get(JwtService::class);
        $pair = $jwt->issueTokenPair($user);

        $response = $this->handle(
            $this->jsonRequest('GET', '/v3/checkout/status/V3-NONEXISTENT', [], [
                'Authorization' => 'Bearer ' . $pair->accessToken,
            ])
        );

        self::assertSame(404, $response->getStatusCode());
    }

    #[Test]
    public function returns404ForOtherUsersOrder(): void
    {
        $owner = $this->makeUser(id: 7, email: 'owner@test.local');
        $attacker = $this->makeUser(id: 99, email: 'attacker@test.local');

        // Order belongs to owner (id 7)
        $order = new Order(user: $owner, orderReference: 'V3-OWNED-001', subtotal: '299.00');
        $this->setEntityId($order, 100);

        $userRepo = $this->createMock(UserRepository::class);
        // Attacker (id 99) is the one authenticated.
        $userRepo->method('findById')->with(99)->willReturn($attacker);

        $orderRepo = $this->createMock(OrderRepository::class);
        $orderRepo->method('findByOrderReference')->with('V3-OWNED-001')->willReturn($order);

        $em = $this->stubEm(function ($em) use ($userRepo, $orderRepo) {
            $em->method('getRepository')->willReturnMap([
                [User::class, $userRepo],
                [Order::class, $orderRepo],
            ]);
        });
        $this->bind(EntityManagerInterface::class, $em);

        $jwt = $this->app->getContainer()->get(JwtService::class);
        $pair = $jwt->issueTokenPair($attacker);

        $response = $this->handle(
            $this->jsonRequest('GET', '/v3/checkout/status/V3-OWNED-001', [], [
                'Authorization' => 'Bearer ' . $pair->accessToken,
            ])
        );

        // 404 (not 403) to avoid leaking that the reference exists.
        self::assertSame(404, $response->getStatusCode());
    }

    #[Test]
    public function requiresAuth(): void
    {
        $response = $this->handle(
            $this->jsonRequest('GET', '/v3/checkout/status/V3-001')
        );

        self::assertSame(401, $response->getStatusCode());
    }

    private function setEntityId(object $entity, int $id): void
    {
        $ref = new \ReflectionProperty($entity::class, 'id');
        $ref->setAccessible(true);
        $ref->setValue($entity, $id);
    }
}
