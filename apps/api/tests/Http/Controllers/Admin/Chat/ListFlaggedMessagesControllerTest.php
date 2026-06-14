<?php

declare(strict_types=1);

namespace Bayti\Api\Tests\Http\Controllers\Admin\Chat;

use Bayti\Api\Domain\Catalog\Vendor;
use Bayti\Api\Domain\Chat\Conversation;
use Bayti\Api\Domain\Chat\Message;
use Bayti\Api\Domain\Chat\MessageRepository;
use Bayti\Api\Domain\Order\Order;
use Bayti\Api\Domain\Order\OrderItem;
use Bayti\Api\Domain\User\User;
use Bayti\Api\Domain\User\UserRepository;
use Bayti\Api\Http\Controllers\Admin\Chat\ListFlaggedMessagesController;
use Bayti\Api\Http\Serializers\ChatSerializer;
use Bayti\Api\Infrastructure\Auth\JwtService;
use Bayti\Api\Tests\Http\HttpTestCase;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use Psr\Http\Message\ResponseInterface;

#[CoversClass(ListFlaggedMessagesController::class)]
#[CoversClass(ChatSerializer::class)]
final class ListFlaggedMessagesControllerTest extends HttpTestCase
{
    /** @var string|null Captured flag_type forwarded to the repository */
    private ?string $capturedFlagType = 'UNSET';

    protected function setUp(): void
    {
        parent::setUp();
        $this->capturedFlagType = 'UNSET';
    }

    private function blockedMessage(): Message
    {
        $customer = $this->createMock(User::class);
        $customer->method('getId')->willReturn(7);
        $customer->method('getFirstName')->willReturn('Layla');
        $customer->method('getLastName')->willReturn('Hassan');
        $customer->method('getEmail')->willReturn('layla@example.com');

        $vendor = $this->createMock(Vendor::class);
        $vendor->method('getName')->willReturn('Almas Fashion');
        $vendor->method('getSlug')->willReturn('almas-fashion');

        $order = $this->createMock(Order::class);
        $order->method('getOrderReference')->willReturn('3B-2026-0042');

        $item = $this->createMock(OrderItem::class);
        $item->method('getProductNameSnapshot')->willReturn('Custom Abaya');

        $conv = new Conversation($customer, $vendor, $order, $item);

        $message = Message::fromCustomer($conv, $customer, 'call me on 0501234567');
        $message->block('phone');
        return $message;
    }

    /** @param list<Message> $items */
    private function bindAdmin(User $authUser, array $items): void
    {
        $userRepo = $this->createMock(UserRepository::class);
        $userRepo->method('findById')->willReturn($authUser);

        $msgRepo = $this->createMock(MessageRepository::class);
        $msgRepo->method('findFlagged')->willReturnCallback(
            function (int $limit, int $offset, ?string $flagType) use (&$items): array {
                $this->capturedFlagType = $flagType;
                return ['items' => $items, 'total' => count($items)];
            },
        );

        $em = $this->stubEm(function ($em) use ($userRepo, $msgRepo) {
            $em->method('getRepository')->willReturnMap([
                [User::class, $userRepo],
                [Message::class, $msgRepo],
            ]);
        });
        $this->bind(EntityManagerInterface::class, $em);
    }

    private function get(User $user, string $uri): ResponseInterface
    {
        $jwt = $this->app->getContainer()->get(JwtService::class);
        $pair = $jwt->issueTokenPair($user);
        return $this->handle($this->jsonRequest('GET', $uri, [], [
            'Authorization' => 'Bearer ' . $pair->accessToken,
        ]));
    }

    private function adminUser(int $id = 1): User
    {
        $user = $this->makeUser(id: $id);
        $user->setRoles(admin: true);
        return $user;
    }

    #[Test]
    public function listsFlaggedMessagesWithContext(): void
    {
        $admin = $this->adminUser();
        $this->bindAdmin($admin, [$this->blockedMessage()]);

        $response = $this->get($admin, '/v3/admin/chat/flagged');
        self::assertSame(200, $response->getStatusCode(), (string) $response->getBody());

        $body = $this->jsonBody($response);
        self::assertCount(1, $body['messages']);
        $msg = $body['messages'][0];
        self::assertSame('call me on 0501234567', $msg['content']);
        self::assertSame('phone', $msg['flag_type']);
        self::assertSame('blocked', $msg['status']);
        self::assertSame('customer', $msg['sender_type']);
        self::assertSame('3B-2026-0042', $msg['conversation']['order_reference']);
        self::assertSame('layla@example.com', $msg['conversation']['customer']['email']);
        self::assertSame('Almas Fashion', $msg['conversation']['vendor']['name']);
        self::assertSame(1, $body['pagination']['total']);
    }

    #[Test]
    public function flagTypeFilterIsForwarded(): void
    {
        $admin = $this->adminUser();
        $this->bindAdmin($admin, []);

        $this->get($admin, '/v3/admin/chat/flagged?flag_type=phone');
        self::assertSame('phone', $this->capturedFlagType);
    }

    #[Test]
    public function invalidFlagTypeIsIgnored(): void
    {
        $admin = $this->adminUser();
        $this->bindAdmin($admin, []);

        $this->get($admin, '/v3/admin/chat/flagged?flag_type=bogus');
        self::assertNull($this->capturedFlagType);
    }

    #[Test]
    public function nonAdminIsForbidden(): void
    {
        $user = $this->makeUser(id: 2); // no admin role
        $this->bindAdmin($user, []);

        $response = $this->get($user, '/v3/admin/chat/flagged');
        self::assertSame(403, $response->getStatusCode());
    }
}
