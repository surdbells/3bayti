<?php

declare(strict_types=1);

namespace Bayti\Api\Tests\Domain\Chat;

use Bayti\Api\Domain\Catalog\Vendor;
use Bayti\Api\Domain\Chat\ChatMessageSender;
use Bayti\Api\Domain\Chat\Conversation;
use Bayti\Api\Domain\Chat\Message;
use Bayti\Api\Domain\Chat\ModerationService;
use Bayti\Api\Domain\Order\Order;
use Bayti\Api\Domain\Order\OrderItem;
use Bayti\Api\Domain\User\User;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(ChatMessageSender::class)]
#[CoversClass(\Bayti\Api\Domain\Chat\SendResult::class)]
final class ChatMessageSenderTest extends TestCase
{
    private function conversation(): Conversation
    {
        return new Conversation(
            $this->createMock(User::class),
            $this->createMock(Vendor::class),
            $this->createMock(Order::class),
            $this->createMock(OrderItem::class),
        );
    }

    #[Test]
    public function cleanMessageIsDeliveredAndBumpsRecipientUnread(): void
    {
        $persisted = [];
        $em = $this->createMock(EntityManagerInterface::class);
        $em->method('persist')->willReturnCallback(function ($e) use (&$persisted) {
            $persisted[] = $e;
        });
        $em->expects($this->once())->method('flush');

        $sender = new ChatMessageSender($em, new ModerationService());
        $conv = $this->conversation();

        $result = $sender->send($conv, $this->createMock(User::class), Conversation::PARTY_CUSTOMER, 'Hello, when will my order be ready?');

        self::assertTrue($result->delivered);
        self::assertTrue($result->message->isDelivered());
        self::assertSame('customer', $result->message->getSenderType());
        // customer → recipient is the vendor
        self::assertSame(1, $conv->getVendorUnreadCount());
        self::assertSame(0, $conv->getCustomerUnreadCount());
        self::assertCount(1, array_filter($persisted, fn ($e) => $e instanceof Message));
    }

    #[Test]
    public function messageWithPhoneIsBlockedAndNotDelivered(): void
    {
        $em = $this->createMock(EntityManagerInterface::class);
        $em->expects($this->once())->method('flush'); // blocked message is still persisted (audit)

        $sender = new ChatMessageSender($em, new ModerationService());
        $conv = $this->conversation();

        $result = $sender->send($conv, $this->createMock(User::class), Conversation::PARTY_CUSTOMER, 'my line is 0501234567 ok');

        self::assertFalse($result->delivered);
        self::assertNotNull($result->moderation);
        self::assertTrue($result->moderation->isFlagged);
        self::assertContains(ModerationService::FLAG_PHONE, $result->moderation->flagTypes);
        // Not delivered → recipient unread untouched.
        self::assertSame(0, $conv->getVendorUnreadCount());
        self::assertFalse($result->message->isDelivered());
    }

    #[Test]
    public function vendorMessageBumpsCustomerUnread(): void
    {
        $em = $this->createMock(EntityManagerInterface::class);
        $em->expects($this->once())->method('flush');

        $sender = new ChatMessageSender($em, new ModerationService());
        $conv = $this->conversation();

        $result = $sender->send($conv, $this->createMock(User::class), Conversation::PARTY_VENDOR, 'Your order ships tomorrow.');

        self::assertTrue($result->delivered);
        self::assertSame('vendor', $result->message->getSenderType());
        self::assertSame(1, $conv->getCustomerUnreadCount());
        self::assertSame(0, $conv->getVendorUnreadCount());
    }
}
