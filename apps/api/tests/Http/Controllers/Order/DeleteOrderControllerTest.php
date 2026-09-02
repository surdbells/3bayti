<?php

declare(strict_types=1);

namespace Bayti\Api\Tests\Http\Controllers\Order;

use Bayti\Api\Domain\Order\Order;
use Bayti\Api\Domain\Order\OrderRepository;
use Bayti\Api\Domain\User\User;
use Bayti\Api\Domain\User\UserRepository;
use Bayti\Api\Http\Controllers\Order\DeleteOrderController;
use Bayti\Api\Infrastructure\Auth\JwtService;
use Bayti\Api\Tests\Http\HttpTestCase;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use Psr\Http\Message\ResponseInterface;

#[CoversClass(DeleteOrderController::class)]
final class DeleteOrderControllerTest extends HttpTestCase
{
    #[Test]
    public function softDeletesAFailedOrder(): void
    {
        $user = $this->makeUser(id: 7);
        $order = $this->makeOrder($user, 100);
        $order->markFailed();
        self::assertFalse($order->isDeleted());

        $this->bindEm($user, $order);
        $response = $this->delete($user, '/v3/orders/100');

        self::assertSame(200, $response->getStatusCode(), (string) $response->getBody());
        self::assertTrue($order->isDeleted(), 'failed order is soft-deleted');
        $body = $this->jsonBody($response);
        self::assertTrue($body['deleted']);
        self::assertSame(100, $body['id']);
    }

    #[Test]
    public function softDeletesACancelledOrder(): void
    {
        $user = $this->makeUser(id: 7);
        $order = $this->makeOrder($user, 100);
        $this->setStatus($order, Order::STATUS_CANCELLED);

        $this->bindEm($user, $order);
        $response = $this->delete($user, '/v3/orders/100');

        self::assertSame(200, $response->getStatusCode(), (string) $response->getBody());
        self::assertTrue($order->isDeleted(), 'cancelled order is soft-deleted');
    }

    #[Test]
    public function rejectsANonRemovableOrderWith422(): void
    {
        $user = $this->makeUser(id: 7);
        $order = $this->makeOrder($user, 100); // pending_payment (not removable)
        $this->bindEm($user, $order);

        $response = $this->delete($user, '/v3/orders/100');

        self::assertSame(422, $response->getStatusCode());
        self::assertFalse($order->isDeleted(), 'a pending order is not deletable');
    }

    #[Test]
    public function returns404WhenNotFoundOrCrossUser(): void
    {
        $user = $this->makeUser(id: 7);
        $this->bindEm($user, null); // findForUser → null

        $response = $this->delete($user, '/v3/orders/999');
        self::assertSame(404, $response->getStatusCode());
    }

    #[Test]
    public function requiresAuth(): void
    {
        $response = $this->handle($this->jsonRequest('DELETE', '/v3/orders/100'));
        self::assertSame(401, $response->getStatusCode());
    }

    // ===== helpers =====

    private function makeOrder(User $user, int $id): Order
    {
        $order = new Order(user: $user, orderReference: 'V3-' . $id, subtotal: '299.00');
        $ref = new \ReflectionProperty(Order::class, 'id');
        $ref->setAccessible(true);
        $ref->setValue($order, $id);
        return $order;
    }

    private function setStatus(Order $order, string $status): void
    {
        $ref = new \ReflectionProperty(Order::class, 'status');
        $ref->setAccessible(true);
        $ref->setValue($order, $status);
    }

    private function bindEm(User $user, ?Order $order): void
    {
        $userRepo = $this->createMock(UserRepository::class);
        $userRepo->method('findById')->willReturn($user);

        $orderRepo = $this->createMock(OrderRepository::class);
        $orderRepo->method('findForUser')->willReturn($order);

        $em = $this->stubEm(function ($em) use ($userRepo, $orderRepo) {
            $em->method('getRepository')->willReturnMap([
                [User::class, $userRepo],
                [Order::class, $orderRepo],
            ]);
        });
        $this->bind(EntityManagerInterface::class, $em);
    }

    private function delete(User $user, string $uri): ResponseInterface
    {
        $jwt = $this->app->getContainer()->get(JwtService::class);
        $pair = $jwt->issueTokenPair($user);
        return $this->handle($this->jsonRequest('DELETE', $uri, [], [
            'Authorization' => 'Bearer ' . $pair->accessToken,
        ]));
    }
}
