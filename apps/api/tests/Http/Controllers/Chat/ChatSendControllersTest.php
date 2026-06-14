<?php

declare(strict_types=1);

namespace Bayti\Api\Tests\Http\Controllers\Chat;

use Bayti\Api\Domain\Catalog\Vendor;
use Bayti\Api\Domain\Catalog\VendorRepository;
use Bayti\Api\Domain\Chat\Conversation;
use Bayti\Api\Domain\Chat\ConversationRepository;
use Bayti\Api\Domain\Order\Order;
use Bayti\Api\Domain\Order\OrderItem;
use Bayti\Api\Domain\User\User;
use Bayti\Api\Domain\User\UserRepository;
use Bayti\Api\Http\Controllers\Chat\Customer\SendMessageController as CustomerSend;
use Bayti\Api\Http\Controllers\Chat\Vendor\SendMessageController as VendorSend;
use Bayti\Api\Infrastructure\Auth\JwtService;
use Bayti\Api\Tests\Http\HttpTestCase;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use Psr\Http\Message\ResponseInterface;

#[CoversClass(CustomerSend::class)]
#[CoversClass(VendorSend::class)]
#[CoversClass(\Bayti\Api\Domain\Chat\ChatMessageSender::class)]
final class ChatSendControllersTest extends HttpTestCase
{
    private const VENDOR_ID = 101;

    private function vendorEntity(int $id = self::VENDOR_ID): Vendor
    {
        $v = $this->createMock(Vendor::class);
        $v->method('getId')->willReturn($id);
        $v->method('getName')->willReturn('Almas Fashion');
        $v->method('getSlug')->willReturn('almas-fashion');
        $v->method('getLogoUrl')->willReturn(null);
        return $v;
    }

    private function conversation(User $customer, ?Vendor $vendor = null): Conversation
    {
        $order = $this->createMock(Order::class);
        $order->method('getOrderReference')->willReturn('3B-2026-0010');
        $item = $this->createMock(OrderItem::class);
        $item->method('getProductNameSnapshot')->willReturn('Abaya');
        $item->method('getProductImageSnapshot')->willReturn(null);
        $item->method('getSize')->willReturn(null);
        $item->method('getColor')->willReturn(null);

        return new Conversation($customer, $vendor ?? $this->vendorEntity(), $order, $item);
    }

    private function customerMock(int $id = 7): User
    {
        $u = $this->createMock(User::class);
        $u->method('getId')->willReturn($id);
        $u->method('getFirstName')->willReturn('Layla');
        $u->method('getLastName')->willReturn('Hassan');
        return $u;
    }

    private function bindForCustomer(User $authUser, Conversation $conv): void
    {
        $userRepo = $this->createMock(UserRepository::class);
        $userRepo->method('findById')->willReturn($authUser);
        $convRepo = $this->createMock(ConversationRepository::class);
        $convRepo->method('findByUuid')->willReturn($conv);

        $em = $this->stubEm(function ($em) use ($userRepo, $convRepo) {
            $em->method('getRepository')->willReturnMap([
                [User::class, $userRepo],
                [Conversation::class, $convRepo],
            ]);
        });
        $this->bind(EntityManagerInterface::class, $em);
    }

    private function bindForVendor(User $authUser, Conversation $conv): void
    {
        $userRepo = $this->createMock(UserRepository::class);
        $userRepo->method('findById')->willReturn($authUser);
        $vendorRepo = $this->createMock(VendorRepository::class);
        $vendorRepo->method('existsApprovedForOwnerUser')->willReturn(true);
        $vendorRepo->method('findIdsByOwnerUser')->willReturn([self::VENDOR_ID]);
        $convRepo = $this->createMock(ConversationRepository::class);
        $convRepo->method('findByUuid')->willReturn($conv);

        $em = $this->stubEm(function ($em) use ($userRepo, $vendorRepo, $convRepo) {
            $em->method('getRepository')->willReturnMap([
                [User::class, $userRepo],
                [Vendor::class, $vendorRepo],
                [Conversation::class, $convRepo],
            ]);
        });
        $this->bind(EntityManagerInterface::class, $em);
    }

    private function post(User $user, string $uri, array $body): ResponseInterface
    {
        $jwt = $this->app->getContainer()->get(JwtService::class);
        $pair = $jwt->issueTokenPair($user);
        return $this->handle($this->jsonRequest('POST', $uri, $body, [
            'Authorization' => 'Bearer ' . $pair->accessToken,
        ]));
    }

    private function vendorUser(int $id = 50): User
    {
        $user = $this->makeUser(id: $id);
        $user->setRoles(vendor: true);
        return $user;
    }

    // ── Customer ────────────────────────────────────────────────

    #[Test]
    public function customerSendsCleanMessage(): void
    {
        $user = $this->makeUser(id: 1);
        $conv = $this->conversation($user);
        $this->bindForCustomer($user, $conv);

        $response = $this->post($user, '/v3/chat/conversations/' . $conv->getUuid() . '/messages', [
            'content' => 'Hello, is my order ready to ship?',
        ]);

        self::assertSame(201, $response->getStatusCode(), (string) $response->getBody());
        $body = $this->jsonBody($response);
        self::assertSame('customer', $body['message']['sender_type']);
        self::assertSame('sent', $body['message']['status']);
        self::assertSame(1, $conv->getVendorUnreadCount());
    }

    #[Test]
    public function customerMessageWithPhoneIsBlocked(): void
    {
        $user = $this->makeUser(id: 1);
        $conv = $this->conversation($user);
        $this->bindForCustomer($user, $conv);

        $response = $this->post($user, '/v3/chat/conversations/' . $conv->getUuid() . '/messages', [
            'content' => 'my line is 0501234567 ok',
        ]);

        self::assertSame(422, $response->getStatusCode());
        $body = $this->jsonBody($response);
        self::assertSame('CHAT_MESSAGE_BLOCKED', $body['error']['code']);
        self::assertContains('phone', $body['error']['details']['flag_types']);
        // Withheld — vendor never notified.
        self::assertSame(0, $conv->getVendorUnreadCount());
    }

    #[Test]
    public function customerEmptyMessageRejected(): void
    {
        $user = $this->makeUser(id: 1);
        $conv = $this->conversation($user);
        $this->bindForCustomer($user, $conv);

        $response = $this->post($user, '/v3/chat/conversations/' . $conv->getUuid() . '/messages', [
            'content' => '   ',
        ]);

        self::assertSame(422, $response->getStatusCode());
        self::assertSame('VALIDATION_FAILED', $this->jsonBody($response)['error']['code']);
    }

    // ── Vendor ──────────────────────────────────────────────────

    #[Test]
    public function vendorSendsCleanMessage(): void
    {
        $user = $this->vendorUser();
        $conv = $this->conversation($this->customerMock(), $this->vendorEntity());
        $this->bindForVendor($user, $conv);

        $response = $this->post($user, '/v3/vendor/chat/conversations/' . $conv->getUuid() . '/messages', [
            'content' => 'Your order will ship tomorrow morning.',
        ]);

        self::assertSame(201, $response->getStatusCode(), (string) $response->getBody());
        $body = $this->jsonBody($response);
        self::assertSame('vendor', $body['message']['sender_type']);
        self::assertSame(1, $conv->getCustomerUnreadCount());
    }

    #[Test]
    public function vendorMessageWithEmailIsBlocked(): void
    {
        $user = $this->vendorUser();
        $conv = $this->conversation($this->customerMock(), $this->vendorEntity());
        $this->bindForVendor($user, $conv);

        $response = $this->post($user, '/v3/vendor/chat/conversations/' . $conv->getUuid() . '/messages', [
            'content' => 'reach me at seller@gmail.com',
        ]);

        self::assertSame(422, $response->getStatusCode());
        $body = $this->jsonBody($response);
        self::assertSame('CHAT_MESSAGE_BLOCKED', $body['error']['code']);
        self::assertContains('email', $body['error']['details']['flag_types']);
        self::assertSame(0, $conv->getCustomerUnreadCount());
    }
}
