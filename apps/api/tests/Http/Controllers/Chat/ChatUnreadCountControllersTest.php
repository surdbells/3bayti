<?php

declare(strict_types=1);

namespace Bayti\Api\Tests\Http\Controllers\Chat;

use Bayti\Api\Domain\Catalog\Vendor;
use Bayti\Api\Domain\Catalog\VendorRepository;
use Bayti\Api\Domain\Chat\Conversation;
use Bayti\Api\Domain\Chat\ConversationRepository;
use Bayti\Api\Domain\Chat\Message;
use Bayti\Api\Domain\User\User;
use Bayti\Api\Domain\User\UserRepository;
use Bayti\Api\Http\Controllers\Chat\Customer\GetUnreadCountController as CustomerUnread;
use Bayti\Api\Http\Controllers\Chat\Vendor\GetUnreadCountController as VendorUnread;
use Bayti\Api\Http\Serializers\ChatSerializer;
use Bayti\Api\Infrastructure\Auth\JwtService;
use Bayti\Api\Tests\Http\HttpTestCase;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use Psr\Http\Message\ResponseInterface;

#[CoversClass(CustomerUnread::class)]
#[CoversClass(VendorUnread::class)]
#[CoversClass(ChatSerializer::class)]
final class ChatUnreadCountControllersTest extends HttpTestCase
{
    private function request(User $user, string $uri): ResponseInterface
    {
        $jwt = $this->app->getContainer()->get(JwtService::class);
        $pair = $jwt->issueTokenPair($user);
        return $this->handle($this->jsonRequest('GET', $uri, [], [
            'Authorization' => 'Bearer ' . $pair->accessToken,
        ]));
    }

    #[Test]
    public function customerUnreadCount(): void
    {
        $user = $this->makeUser(id: 1);

        $userRepo = $this->createMock(UserRepository::class);
        $userRepo->method('findById')->willReturn($user);
        $convRepo = $this->createMock(ConversationRepository::class);
        $convRepo->method('unreadCountForCustomer')->willReturn(5);

        $em = $this->stubEm(function ($em) use ($userRepo, $convRepo) {
            $em->method('getRepository')->willReturnMap([
                [User::class, $userRepo],
                [Conversation::class, $convRepo],
            ]);
        });
        $this->bind(EntityManagerInterface::class, $em);

        $response = $this->request($user, '/v3/chat/unread-count');
        self::assertSame(200, $response->getStatusCode(), (string) $response->getBody());
        self::assertSame(5, $this->jsonBody($response)['unread_count']);
    }

    #[Test]
    public function vendorUnreadCount(): void
    {
        $user = $this->makeUser(id: 50);
        $user->setRoles(vendor: true);

        $userRepo = $this->createMock(UserRepository::class);
        $userRepo->method('findById')->willReturn($user);
        $vendorRepo = $this->createMock(VendorRepository::class);
        $vendorRepo->method('existsApprovedForOwnerUser')->willReturn(true);
        $vendorRepo->method('findIdsByOwnerUser')->willReturn([101, 202]);
        $convRepo = $this->createMock(ConversationRepository::class);
        $convRepo->method('unreadCountForVendor')->willReturn(3);

        $em = $this->stubEm(function ($em) use ($userRepo, $vendorRepo, $convRepo) {
            $em->method('getRepository')->willReturnMap([
                [User::class, $userRepo],
                [Vendor::class, $vendorRepo],
                [Conversation::class, $convRepo],
            ]);
        });
        $this->bind(EntityManagerInterface::class, $em);

        $response = $this->request($user, '/v3/vendor/chat/unread-count');
        self::assertSame(200, $response->getStatusCode(), (string) $response->getBody());
        self::assertSame(3, $this->jsonBody($response)['unread_count']);
    }

    #[Test]
    public function vendorUnreadCountForbiddenForNonVendor(): void
    {
        $user = $this->makeUser(id: 60);

        $userRepo = $this->createMock(UserRepository::class);
        $userRepo->method('findById')->willReturn($user);
        $vendorRepo = $this->createMock(VendorRepository::class);
        $vendorRepo->method('existsApprovedForOwnerUser')->willReturn(false);
        $vendorRepo->method('findIdsByOwnerUser')->willReturn([]);

        $em = $this->stubEm(function ($em) use ($userRepo, $vendorRepo) {
            $em->method('getRepository')->willReturnMap([
                [User::class, $userRepo],
                [Vendor::class, $vendorRepo],
            ]);
        });
        $this->bind(EntityManagerInterface::class, $em);

        $response = $this->request($user, '/v3/vendor/chat/unread-count');
        self::assertSame(403, $response->getStatusCode());
    }

    #[Test]
    public function messageShapeExposesCursorId(): void
    {
        $message = $this->createMock(Message::class);
        $message->method('getId')->willReturn(999);
        $message->method('getUuid')->willReturn('uuid-1');
        $message->method('getSenderType')->willReturn('customer');
        $message->method('getType')->willReturn('text');
        $message->method('getContent')->willReturn('hi');
        $message->method('getContentAr')->willReturn(null);
        $message->method('isFlagged')->willReturn(false);
        $message->method('getStatus')->willReturn('sent');
        $message->method('getCreatedAt')->willReturn(new \DateTimeImmutable());

        $shape = (new ChatSerializer())->messageShape($message);
        self::assertSame(999, $shape['id']);
        self::assertArrayHasKey('uuid', $shape);
    }
}
