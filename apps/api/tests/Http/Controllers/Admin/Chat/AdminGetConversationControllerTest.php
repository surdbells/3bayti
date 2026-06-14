<?php

declare(strict_types=1);

namespace Bayti\Api\Tests\Http\Controllers\Admin\Chat;

use Bayti\Api\Domain\Catalog\Vendor;
use Bayti\Api\Domain\Chat\Conversation;
use Bayti\Api\Domain\Chat\ConversationRepository;
use Bayti\Api\Domain\Chat\Message;
use Bayti\Api\Domain\Chat\MessageRepository;
use Bayti\Api\Domain\Order\Order;
use Bayti\Api\Domain\Order\OrderItem;
use Bayti\Api\Domain\User\User;
use Bayti\Api\Domain\User\UserRepository;
use Bayti\Api\Http\Controllers\Admin\Chat\GetConversationController;
use Bayti\Api\Http\Serializers\ChatSerializer;
use Bayti\Api\Infrastructure\Auth\JwtService;
use Bayti\Api\Tests\Http\HttpTestCase;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use Psr\Http\Message\ResponseInterface;

#[CoversClass(GetConversationController::class)]
#[CoversClass(ChatSerializer::class)]
final class AdminGetConversationControllerTest extends HttpTestCase
{
    private function conversation(): Conversation
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

        return new Conversation($customer, $vendor, $order, $item);
    }

    /** @param list<Message> $messages */
    private function bindAdmin(User $authUser, ?Conversation $conv, array $messages): void
    {
        $userRepo = $this->createMock(UserRepository::class);
        $userRepo->method('findById')->willReturn($authUser);

        $convRepo = $this->createMock(ConversationRepository::class);
        $convRepo->method('findByUuid')->willReturn($conv);

        $msgRepo = $this->createMock(MessageRepository::class);
        $msgRepo->method('findAllForConversation')->willReturn($messages);

        $em = $this->stubEm(function ($em) use ($userRepo, $convRepo, $msgRepo) {
            $em->method('getRepository')->willReturnMap([
                [User::class, $userRepo],
                [Conversation::class, $convRepo],
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
    public function viewsFullThreadIncludingBlocked(): void
    {
        $admin = $this->adminUser();
        $conv = $this->conversation();
        $sender = $this->createMock(User::class);

        $blocked = Message::fromCustomer($conv, $sender, 'call me 0501234567');
        $blocked->block('phone');

        $messages = [
            Message::system($conv, 'Order details', null),
            $blocked,
            Message::fromVendor($conv, $sender, 'Sure, it ships tomorrow.'),
        ];

        $this->bindAdmin($admin, $conv, $messages);

        $response = $this->get($admin, '/v3/admin/chat/conversations/' . $conv->getUuid());
        self::assertSame(200, $response->getStatusCode(), (string) $response->getBody());

        $body = $this->jsonBody($response);
        self::assertSame($conv->getUuid(), $body['conversation']['uuid']);
        self::assertSame('layla@example.com', $body['conversation']['customer']['email']);
        self::assertSame('Almas Fashion', $body['conversation']['vendor']['name']);
        self::assertCount(3, $body['messages']);

        $blockedShapes = array_filter($body['messages'], fn ($m) => $m['status'] === 'blocked');
        self::assertCount(1, $blockedShapes);
        self::assertTrue(array_values($blockedShapes)[0]['is_flagged']);
    }

    #[Test]
    public function unknownConversationReturns404(): void
    {
        $admin = $this->adminUser();
        $this->bindAdmin($admin, null, []);

        $response = $this->get($admin, '/v3/admin/chat/conversations/01900000-0000-7000-8000-000000000000');
        self::assertSame(404, $response->getStatusCode());
    }

    #[Test]
    public function nonAdminIsForbidden(): void
    {
        $user = $this->makeUser(id: 2);
        $this->bindAdmin($user, $this->conversation(), []);

        $response = $this->get($user, '/v3/admin/chat/conversations/01900000-0000-7000-8000-000000000000');
        self::assertSame(403, $response->getStatusCode());
    }
}
