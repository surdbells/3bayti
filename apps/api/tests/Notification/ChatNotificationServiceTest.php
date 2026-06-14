<?php

declare(strict_types=1);

namespace Bayti\Api\Tests\Notification;

use Bayti\Api\Domain\Catalog\Vendor;
use Bayti\Api\Domain\Chat\Conversation;
use Bayti\Api\Domain\Chat\Message;
use Bayti\Api\Domain\Notification\NotificationLog;
use Bayti\Api\Domain\Order\Order;
use Bayti\Api\Domain\Order\OrderItem;
use Bayti\Api\Domain\User\User;
use Bayti\Api\Notification\ChatNotificationService;
use Bayti\Api\Notification\MailerInterface;
use Bayti\Api\Notification\Push\PushNotificationService;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(ChatNotificationService::class)]
final class ChatNotificationServiceTest extends TestCase
{
    private function customer(): User
    {
        $u = $this->createMock(User::class);
        $u->method('getId')->willReturn(7);
        $u->method('getEmail')->willReturn('layla@example.com');
        $u->method('getFirstName')->willReturn('Layla');
        $u->method('getLastName')->willReturn('Hassan');
        return $u;
    }

    private function vendor(?User $owner): Vendor
    {
        $v = $this->createMock(Vendor::class);
        $v->method('getName')->willReturn('Almas Fashion');
        $v->method('getOwnerUser')->willReturn($owner);
        return $v;
    }

    private function conversation(Vendor $vendor, User $customer): Conversation
    {
        $order = $this->createMock(Order::class);
        $order->method('getOrderReference')->willReturn('3B-2026-0001');
        $order->method('getId')->willReturn(55);
        return new Conversation($customer, $vendor, $order, $this->createMock(OrderItem::class));
    }

    private function vendorOwner(): User
    {
        $u = $this->createMock(User::class);
        $u->method('getId')->willReturn(50);
        $u->method('getEmail')->willReturn('owner@almas.example');
        return $u;
    }

    #[Test]
    public function notifiesVendorOnFirstCustomerMessage(): void
    {
        $customer = $this->customer();
        $conv = $this->conversation($this->vendor($this->vendorOwner()), $customer);
        $message = Message::fromCustomer($conv, $customer, 'Is my abaya ready to ship?');

        $mailer = $this->createMock(MailerInterface::class);
        $mailer->expects($this->once())->method('send')
            ->with(
                'owner@almas.example',
                self::stringContains('3B-2026-0001'),
                self::anything(),
                self::anything(),
                self::anything(),
            );

        $push = $this->createMock(PushNotificationService::class);
        $push->expects($this->once())->method('chatMessage');

        $persisted = [];
        $em = $this->createMock(EntityManagerInterface::class);
        $em->method('persist')->willReturnCallback(function ($e) use (&$persisted) {
            $persisted[] = $e;
        });
        $em->expects($this->once())->method('flush');

        $svc = new ChatNotificationService($mailer, $push, $em);
        $svc->maybeNotify($conv, Conversation::PARTY_VENDOR, $message);

        self::assertCount(1, array_filter($persisted, fn ($e) => $e instanceof NotificationLog));
        self::assertNotNull($conv->getVendorLastNotifiedAt());
    }

    #[Test]
    public function suppressedWithinDebounceWindow(): void
    {
        $customer = $this->customer();
        $conv = $this->conversation($this->vendor($this->vendorOwner()), $customer);
        // Already notified 1 minute ago.
        $conv->markNotified(Conversation::PARTY_VENDOR, (new \DateTimeImmutable())->modify('-1 minute'));

        $message = Message::fromCustomer($conv, $customer, 'still there?');

        $mailer = $this->createMock(MailerInterface::class);
        $mailer->expects($this->never())->method('send');
        $push = $this->createMock(PushNotificationService::class);
        $push->expects($this->never())->method('chatMessage');
        $em = $this->createMock(EntityManagerInterface::class);
        $em->expects($this->never())->method('flush');

        $svc = new ChatNotificationService($mailer, $push, $em);
        $svc->maybeNotify($conv, Conversation::PARTY_VENDOR, $message);
    }

    #[Test]
    public function notifiesCustomerOnVendorMessage(): void
    {
        $customer = $this->customer();
        $conv = $this->conversation($this->vendor($this->vendorOwner()), $customer);
        $message = Message::fromVendor($conv, $this->vendorOwner(), 'Shipping today!');

        $mailer = $this->createMock(MailerInterface::class);
        $mailer->expects($this->once())->method('send')
            ->with('layla@example.com', self::anything(), self::anything(), self::anything(), self::anything());
        $push = $this->createMock(PushNotificationService::class);
        $push->expects($this->once())->method('chatMessage');
        $em = $this->createMock(EntityManagerInterface::class);
        $em->expects($this->once())->method('flush');

        $svc = new ChatNotificationService($mailer, $push, $em);
        $svc->maybeNotify($conv, Conversation::PARTY_CUSTOMER, $message);

        self::assertNotNull($conv->getCustomerLastNotifiedAt());
    }

    #[Test]
    public function skipsWhenVendorHasNoOwnerUser(): void
    {
        $customer = $this->customer();
        $conv = $this->conversation($this->vendor(null), $customer); // no owner
        $message = Message::fromCustomer($conv, $customer, 'hello');

        $mailer = $this->createMock(MailerInterface::class);
        $mailer->expects($this->never())->method('send');
        $push = $this->createMock(PushNotificationService::class);
        $push->expects($this->never())->method('chatMessage');
        $em = $this->createMock(EntityManagerInterface::class);
        $em->expects($this->never())->method('flush');

        $svc = new ChatNotificationService($mailer, $push, $em);
        $svc->maybeNotify($conv, Conversation::PARTY_VENDOR, $message);
    }
}
