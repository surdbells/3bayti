<?php

declare(strict_types=1);

namespace Bayti\Api\Tests\Domain\Chat;

use Bayti\Api\Domain\Chat\Conversation;
use Bayti\Api\Domain\Chat\Message;
use Bayti\Api\Domain\User\User;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(Message::class)]
final class MessageTest extends TestCase
{
    private function conv(): Conversation
    {
        return $this->createMock(Conversation::class);
    }

    #[Test]
    public function customerFactorySetsSenderAndType(): void
    {
        $user = $this->createMock(User::class);
        $m = Message::fromCustomer($this->conv(), $user, 'hello');
        self::assertSame(Conversation::PARTY_CUSTOMER, $m->getSenderType());
        self::assertSame($user, $m->getSender());
        self::assertSame('hello', $m->getContent());
        self::assertSame(Message::STATUS_SENT, $m->getStatus());
        self::assertTrue($m->isDelivered());
        self::assertNotSame('', $m->getUuid());
    }

    #[Test]
    public function systemFactoryHasNoSenderAndCarriesArabic(): void
    {
        $m = Message::system($this->conv(), 'Order details', 'تفاصيل الطلب');
        self::assertSame(Conversation::PARTY_SYSTEM, $m->getSenderType());
        self::assertNull($m->getSender());
        self::assertSame(Message::TYPE_SYSTEM, $m->getType());
        self::assertSame('تفاصيل الطلب', $m->getContentAr());
    }

    #[Test]
    public function blockMarksFlaggedAndUndelivered(): void
    {
        $m = Message::fromVendor($this->conv(), $this->createMock(User::class), 'call me 0501234567');
        $m->block('phone');
        self::assertSame(Message::STATUS_BLOCKED, $m->getStatus());
        self::assertTrue($m->isFlagged());
        self::assertSame('phone', $m->getFlagType());
        self::assertFalse($m->isDelivered());
    }

    #[Test]
    public function redactReplacesContentButDelivers(): void
    {
        $m = Message::fromCustomer($this->conv(), $this->createMock(User::class), 'email a@b.com');
        $m->redact('email •••', 'email');
        self::assertSame('email •••', $m->getContent());
        self::assertSame(Message::STATUS_REDACTED, $m->getStatus());
        self::assertTrue($m->isFlagged());
        self::assertTrue($m->isDelivered());
    }
}
