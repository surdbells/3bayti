<?php

declare(strict_types=1);

namespace Bayti\Api\Tests\Http\Controllers\Chat;

use Bayti\Api\Domain\Catalog\Vendor;
use Bayti\Api\Domain\Chat\Conversation;
use Bayti\Api\Domain\Chat\ConversationRepository;
use Bayti\Api\Domain\Chat\Message;
use Bayti\Api\Domain\Chat\MessageRepository;
use Bayti\Api\Domain\Order\Order;
use Bayti\Api\Domain\Order\OrderItem;
use Bayti\Api\Domain\User\User;
use Bayti\Api\Domain\User\UserRepository;
use Bayti\Api\Http\Controllers\Chat\Customer\GetMessagesController;
use Bayti\Api\Http\Controllers\Chat\Customer\ListConversationsController;
use Bayti\Api\Http\Controllers\Chat\Customer\MarkReadController;
use Bayti\Api\Http\Serializers\ChatSerializer;
use Bayti\Api\Infrastructure\Auth\JwtService;
use Bayti\Api\Tests\Http\HttpTestCase;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use Psr\Http\Message\ResponseInterface;

#[CoversClass(ListConversationsController::class)]
#[CoversClass(GetMessagesController::class)]
#[CoversClass(MarkReadController::class)]
#[CoversClass(ChatSerializer::class)]
#[CoversClass(\Bayti\Api\Http\Controllers\Chat\ResolvesChatConversation::class)]
final class CustomerChatControllersTest extends HttpTestCase
{
    private function vendor(): Vendor
    {
        $v = $this->createMock(Vendor::class);
        $v->method('getName')->willReturn('Almas Fashion');
        $v->method('getSlug')->willReturn('almas-fashion');
        $v->method('getLogoUrl')->willReturn('https://cdn/almas.png');
        return $v;
    }

    private function order(): Order
    {
        $o = $this->createMock(Order::class);
        $o->method('getOrderReference')->willReturn('3B-2026-0001');
        return $o;
    }

    private function orderItem(): OrderItem
    {
        $i = $this->createMock(OrderItem::class);
        $i->method('getProductNameSnapshot')->willReturn('Custom Silk Abaya');
        $i->method('getProductImageSnapshot')->willReturn('https://cdn/abaya.jpg');
        $i->method('getSize')->willReturn('M');
        $i->method('getColor')->willReturn('Blue');
        return $i;
    }

    private function conversationFor(User $customer): Conversation
    {
        return new Conversation($customer, $this->vendor(), $this->order(), $this->orderItem());
    }

    /**
     * @param array<class-string, object> $repos
     */
    private function bindChat(User $authUser, array $repos): void
    {
        $userRepo = $this->createMock(UserRepository::class);
        $userRepo->method('findById')->willReturn($authUser);

        $map = [[User::class, $userRepo]];
        foreach ($repos as $class => $repo) {
            $map[] = [$class, $repo];
        }

        $em = $this->stubEm(function ($em) use ($map) {
            $em->method('getRepository')->willReturnMap($map);
        });
        $this->bind(EntityManagerInterface::class, $em);
    }

    private function request(User $user, string $method, string $uri): ResponseInterface
    {
        $jwt = $this->app->getContainer()->get(JwtService::class);
        $pair = $jwt->issueTokenPair($user);
        return $this->handle($this->jsonRequest($method, $uri, [], [
            'Authorization' => 'Bearer ' . $pair->accessToken,
        ]));
    }

    #[Test]
    public function listsCustomerConversations(): void
    {
        $user = $this->makeUser(id: 1);
        $conv = $this->conversationFor($user);

        $convRepo = $this->createMock(ConversationRepository::class);
        $convRepo->method('findForCustomer')->willReturn(['items' => [$conv], 'total' => 1, 'unread' => 2]);

        $this->bindChat($user, [ConversationRepository::class => $convRepo, Conversation::class => $convRepo]);

        $response = $this->request($user, 'GET', '/v3/chat/conversations');
        self::assertSame(200, $response->getStatusCode(), (string) $response->getBody());

        $body = $this->jsonBody($response);
        self::assertCount(1, $body['conversations']);
        self::assertSame('3B-2026-0001', $body['conversations'][0]['order_reference']);
        self::assertSame('vendor', $body['conversations'][0]['counterparty']['type']);
        self::assertSame('Almas Fashion', $body['conversations'][0]['counterparty']['name']);
        self::assertSame(2, $body['unread_total']);
        self::assertSame(1, $body['pagination']['total']);
    }

    #[Test]
    public function getsMessagesWithConversationMeta(): void
    {
        $user = $this->makeUser(id: 1);
        $conv = $this->conversationFor($user);

        $messages = [
            Message::system($conv, 'Order details', 'تفاصيل'),
            Message::fromCustomer($conv, $user, 'Is it ready?'),
        ];

        $convRepo = $this->createMock(ConversationRepository::class);
        $convRepo->method('findByUuid')->willReturn($conv);

        $msgRepo = $this->createMock(MessageRepository::class);
        $msgRepo->method('findForConversation')->willReturn($messages);

        $this->bindChat($user, [Conversation::class => $convRepo, Message::class => $msgRepo]);

        $response = $this->request($user, 'GET', '/v3/chat/conversations/' . $conv->getUuid() . '/messages');
        self::assertSame(200, $response->getStatusCode(), (string) $response->getBody());

        $body = $this->jsonBody($response);
        self::assertSame($conv->getUuid(), $body['conversation']['uuid']);
        self::assertCount(2, $body['messages']);
        self::assertSame('system', $body['messages'][0]['sender_type']);
        self::assertSame('customer', $body['messages'][1]['sender_type']);
    }

    #[Test]
    public function cannotAccessAnotherCustomersConversation(): void
    {
        $authUser = $this->makeUser(id: 1);
        $otherCustomer = $this->createMock(User::class);
        $otherCustomer->method('getId')->willReturn(999);
        $conv = $this->conversationFor($otherCustomer);

        $convRepo = $this->createMock(ConversationRepository::class);
        $convRepo->method('findByUuid')->willReturn($conv);

        $this->bindChat($authUser, [Conversation::class => $convRepo]);

        $response = $this->request($authUser, 'GET', '/v3/chat/conversations/' . $conv->getUuid() . '/messages');
        self::assertSame(404, $response->getStatusCode());
    }

    #[Test]
    public function unknownConversationReturns404(): void
    {
        $user = $this->makeUser(id: 1);
        $convRepo = $this->createMock(ConversationRepository::class);
        $convRepo->method('findByUuid')->willReturn(null);

        $this->bindChat($user, [Conversation::class => $convRepo]);

        $response = $this->request($user, 'GET', '/v3/chat/conversations/01900000-0000-7000-8000-000000000000/messages');
        self::assertSame(404, $response->getStatusCode());
    }

    #[Test]
    public function marksConversationRead(): void
    {
        $user = $this->makeUser(id: 1);
        $conv = $this->conversationFor($user);
        $conv->recordMessage(Conversation::PARTY_VENDOR, 'reply'); // bumps customer unread to 1
        self::assertSame(1, $conv->getCustomerUnreadCount());

        $convRepo = $this->createMock(ConversationRepository::class);
        $convRepo->method('findByUuid')->willReturn($conv);

        $this->bindChat($user, [Conversation::class => $convRepo]);

        $response = $this->request($user, 'POST', '/v3/chat/conversations/' . $conv->getUuid() . '/read');
        self::assertSame(200, $response->getStatusCode(), (string) $response->getBody());

        $body = $this->jsonBody($response);
        self::assertSame(0, $body['unread_count']);
        self::assertSame(0, $conv->getCustomerUnreadCount());
    }
}
