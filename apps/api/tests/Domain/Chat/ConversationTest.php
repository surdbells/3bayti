<?php

declare(strict_types=1);

namespace Bayti\Api\Tests\Domain\Chat;

use Bayti\Api\Domain\Catalog\Vendor;
use Bayti\Api\Domain\Chat\Conversation;
use Bayti\Api\Domain\Order\Order;
use Bayti\Api\Domain\Order\OrderItem;
use Bayti\Api\Domain\User\User;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(Conversation::class)]
final class ConversationTest extends TestCase
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
    public function startsActiveWithUuidAndZeroUnread(): void
    {
        $c = $this->conversation();
        self::assertTrue($c->isActive());
        self::assertNotSame('', $c->getUuid());
        self::assertSame(0, $c->getCustomerUnreadCount());
        self::assertSame(0, $c->getVendorUnreadCount());
        self::assertNull($c->getLastMessageAt());
    }

    #[Test]
    public function customerMessageBumpsVendorUnreadOnly(): void
    {
        $c = $this->conversation();
        $c->recordMessage(Conversation::PARTY_CUSTOMER, 'Hello, is it ready?');
        self::assertSame(1, $c->getVendorUnreadCount());
        self::assertSame(0, $c->getCustomerUnreadCount());
        self::assertNotNull($c->getLastMessageAt());
        self::assertSame('Hello, is it ready?', $c->getLastMessagePreview());
    }

    #[Test]
    public function vendorMessageBumpsCustomerUnreadOnly(): void
    {
        $c = $this->conversation();
        $c->recordMessage(Conversation::PARTY_VENDOR, 'Yes, shipping today.');
        self::assertSame(1, $c->getCustomerUnreadCount());
        self::assertSame(0, $c->getVendorUnreadCount());
    }

    #[Test]
    public function systemMessageBumpsBoth(): void
    {
        $c = $this->conversation();
        $c->recordMessage(Conversation::PARTY_SYSTEM, 'Order #123 details');
        self::assertSame(1, $c->getCustomerUnreadCount());
        self::assertSame(1, $c->getVendorUnreadCount());
    }

    #[Test]
    public function markReadResetsOnlyThatParty(): void
    {
        $c = $this->conversation();
        $c->recordMessage(Conversation::PARTY_SYSTEM, 'seed');
        $c->recordMessage(Conversation::PARTY_CUSTOMER, 'hi');
        // vendor: 2, customer: 1
        $c->markReadFor(Conversation::PARTY_VENDOR);
        self::assertSame(0, $c->getVendorUnreadCount());
        self::assertSame(1, $c->getCustomerUnreadCount());
        self::assertSame(1, $c->unreadFor(Conversation::PARTY_CUSTOMER));
    }

    #[Test]
    public function previewIsTruncatedTo200(): void
    {
        $c = $this->conversation();
        $c->recordMessage(Conversation::PARTY_CUSTOMER, str_repeat('x', 500));
        self::assertSame(200, mb_strlen((string) $c->getLastMessagePreview()));
    }

    #[Test]
    public function closeMakesInactive(): void
    {
        $c = $this->conversation();
        $c->close();
        self::assertFalse($c->isActive());
        self::assertSame(Conversation::STATUS_CLOSED, $c->getStatus());
    }

    #[Test]
    public function shouldNotifyWhenNeverNotified(): void
    {
        $c = $this->conversation();
        $now = new \DateTimeImmutable();
        self::assertTrue($c->shouldNotify(Conversation::PARTY_VENDOR, $now, 600));
        self::assertTrue($c->shouldNotify(Conversation::PARTY_CUSTOMER, $now, 600));
    }

    #[Test]
    public function debounceSuppressesWithinWindowAndAllowsAfter(): void
    {
        $c = $this->conversation();
        $t0 = new \DateTimeImmutable('2026-06-14 10:00:00');
        $c->markNotified(Conversation::PARTY_VENDOR, $t0);

        // 5 minutes later — still inside a 10-minute window.
        self::assertFalse($c->shouldNotify(Conversation::PARTY_VENDOR, $t0->modify('+5 minutes'), 600));
        // 10 minutes later — window elapsed.
        self::assertTrue($c->shouldNotify(Conversation::PARTY_VENDOR, $t0->modify('+10 minutes'), 600));
        // The other party is unaffected.
        self::assertTrue($c->shouldNotify(Conversation::PARTY_CUSTOMER, $t0->modify('+1 minute'), 600));
    }

    #[Test]
    public function readingReArmsNotification(): void
    {
        $c = $this->conversation();
        $t0 = new \DateTimeImmutable('2026-06-14 10:00:00');
        $c->markNotified(Conversation::PARTY_VENDOR, $t0);
        self::assertFalse($c->shouldNotify(Conversation::PARTY_VENDOR, $t0->modify('+1 minute'), 600));

        // Vendor reads the thread → timer cleared → next message notifies.
        $c->markReadFor(Conversation::PARTY_VENDOR);
        self::assertNull($c->getVendorLastNotifiedAt());
        self::assertTrue($c->shouldNotify(Conversation::PARTY_VENDOR, $t0->modify('+1 minute'), 600));
    }
}
