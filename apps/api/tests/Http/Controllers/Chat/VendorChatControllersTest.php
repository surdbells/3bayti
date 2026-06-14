<?php

declare(strict_types=1);

namespace Bayti\Api\Tests\Http\Controllers\Chat;

use Bayti\Api\Domain\Catalog\Vendor;
use Bayti\Api\Domain\Catalog\VendorRepository;
use Bayti\Api\Domain\Chat\Conversation;
use Bayti\Api\Domain\Chat\ConversationRepository;
use Bayti\Api\Domain\Chat\Message;
use Bayti\Api\Domain\Chat\MessageRepository;
use Bayti\Api\Domain\Order\Order;
use Bayti\Api\Domain\Order\OrderItem;
use Bayti\Api\Domain\User\User;
use Bayti\Api\Domain\User\UserRepository;
use Bayti\Api\Http\Controllers\Chat\Vendor\GetMessagesController;
use Bayti\Api\Http\Controllers\Chat\Vendor\ListConversationsController;
use Bayti\Api\Http\Controllers\Chat\Vendor\MarkReadController;
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
final class VendorChatControllersTest extends HttpTestCase
{
    private const OWNED_VENDOR_ID = 101;

    private function vendorEntity(int $id = self::OWNED_VENDOR_ID): Vendor
    {
        $v = $this->createMock(Vendor::class);
        $v->method('getId')->willReturn($id);
        $v->method('getName')->willReturn('Almas Fashion');
        $v->method('getSlug')->willReturn('almas-fashion');
        $v->method('getLogoUrl')->willReturn(null);
        return $v;
    }

    private function customer(): User
    {
        $u = $this->createMock(User::class);
        $u->method('getId')->willReturn(7);
        $u->method('getFirstName')->willReturn('Layla');
        $u->method('getLastName')->willReturn('Hassan');
        return $u;
    }

    private function order(): Order
    {
        $o = $this->createMock(Order::class);
        $o->method('getOrderReference')->willReturn('3B-2026-0009');
        return $o;
    }

    private function orderItem(): OrderItem
    {
        $i = $this->createMock(OrderItem::class);
        $i->method('getProductNameSnapshot')->willReturn('Linen Kandura');
        $i->method('getProductImageSnapshot')->willReturn(null);
        $i->method('getSize')->willReturn('L');
        $i->method('getColor')->willReturn('White');
        return $i;
    }

    private function conversationFor(Vendor $vendor): Conversation
    {
        return new Conversation($this->customer(), $vendor, $this->order(), $this->orderItem());
    }

    private function vendorUser(int $id = 50): User
    {
        $user = $this->makeUser(id: $id);
        $user->setRoles(vendor: true);
        return $user;
    }

    /** @param array<class-string, object> $repos */
    private function bindVendorChat(User $authUser, VendorRepository $vendorRepo, array $repos): void
    {
        $userRepo = $this->createMock(UserRepository::class);
        $userRepo->method('findById')->willReturn($authUser);

        $map = [[User::class, $userRepo], [Vendor::class, $vendorRepo]];
        foreach ($repos as $class => $repo) {
            $map[] = [$class, $repo];
        }

        $em = $this->stubEm(function ($em) use ($map) {
            $em->method('getRepository')->willReturnMap($map);
        });
        $this->bind(EntityManagerInterface::class, $em);
    }

    private function vendorRepo(): VendorRepository
    {
        $repo = $this->createMock(VendorRepository::class);
        $repo->method('existsApprovedForOwnerUser')->willReturn(true);
        $repo->method('findIdsByOwnerUser')->willReturn([self::OWNED_VENDOR_ID]);
        return $repo;
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
    public function listsVendorConversationsWithCustomerCounterparty(): void
    {
        $user = $this->vendorUser();
        $conv = $this->conversationFor($this->vendorEntity());

        $convRepo = $this->createMock(ConversationRepository::class);
        $convRepo->method('findForVendor')->willReturn(['items' => [$conv], 'total' => 1, 'unread' => 3]);

        $this->bindVendorChat($user, $this->vendorRepo(), [Conversation::class => $convRepo]);

        $response = $this->request($user, 'GET', '/v3/vendor/chat/conversations');
        self::assertSame(200, $response->getStatusCode(), (string) $response->getBody());

        $body = $this->jsonBody($response);
        self::assertCount(1, $body['conversations']);
        self::assertSame('customer', $body['conversations'][0]['counterparty']['type']);
        self::assertSame('Layla Hassan', $body['conversations'][0]['counterparty']['name']);
        self::assertSame(3, $body['unread_total']);
    }

    #[Test]
    public function getsMessagesForOwnedConversation(): void
    {
        $user = $this->vendorUser();
        $conv = $this->conversationFor($this->vendorEntity());

        $convRepo = $this->createMock(ConversationRepository::class);
        $convRepo->method('findByUuid')->willReturn($conv);

        $msgRepo = $this->createMock(MessageRepository::class);
        $msgRepo->method('findForConversation')->willReturn([Message::system($conv, 'Order details', null)]);

        $this->bindVendorChat($user, $this->vendorRepo(), [
            Conversation::class => $convRepo,
            Message::class => $msgRepo,
        ]);

        $response = $this->request($user, 'GET', '/v3/vendor/chat/conversations/' . $conv->getUuid() . '/messages');
        self::assertSame(200, $response->getStatusCode(), (string) $response->getBody());

        $body = $this->jsonBody($response);
        self::assertSame($conv->getUuid(), $body['conversation']['uuid']);
        self::assertCount(1, $body['messages']);
    }

    #[Test]
    public function cannotAccessConversationForUnownedStore(): void
    {
        $user = $this->vendorUser();
        $conv = $this->conversationFor($this->vendorEntity(999)); // not in owned [101]

        $convRepo = $this->createMock(ConversationRepository::class);
        $convRepo->method('findByUuid')->willReturn($conv);

        $this->bindVendorChat($user, $this->vendorRepo(), [Conversation::class => $convRepo]);

        $response = $this->request($user, 'GET', '/v3/vendor/chat/conversations/' . $conv->getUuid() . '/messages');
        self::assertSame(404, $response->getStatusCode());
    }

    #[Test]
    public function marksVendorConversationRead(): void
    {
        $user = $this->vendorUser();
        $conv = $this->conversationFor($this->vendorEntity());
        $conv->recordMessage(Conversation::PARTY_CUSTOMER, 'question'); // bumps vendor unread
        self::assertSame(1, $conv->getVendorUnreadCount());

        $convRepo = $this->createMock(ConversationRepository::class);
        $convRepo->method('findByUuid')->willReturn($conv);

        $this->bindVendorChat($user, $this->vendorRepo(), [Conversation::class => $convRepo]);

        $response = $this->request($user, 'POST', '/v3/vendor/chat/conversations/' . $conv->getUuid() . '/read');
        self::assertSame(200, $response->getStatusCode(), (string) $response->getBody());
        self::assertSame(0, $conv->getVendorUnreadCount());
    }

    #[Test]
    public function nonVendorIsForbidden(): void
    {
        $user = $this->makeUser(id: 60); // no vendor role

        $vendorRepo = $this->createMock(VendorRepository::class);
        $vendorRepo->method('existsApprovedForOwnerUser')->willReturn(false);
        $vendorRepo->method('findIdsByOwnerUser')->willReturn([]);

        $this->bindVendorChat($user, $vendorRepo, []);

        $response = $this->request($user, 'GET', '/v3/vendor/chat/conversations');
        self::assertSame(403, $response->getStatusCode());
    }
}
