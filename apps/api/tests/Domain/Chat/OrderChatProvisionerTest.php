<?php

declare(strict_types=1);

namespace Bayti\Api\Tests\Domain\Chat;

use Bayti\Api\Domain\Catalog\Vendor;
use Bayti\Api\Domain\Chat\Conversation;
use Bayti\Api\Domain\Chat\ConversationRepository;
use Bayti\Api\Domain\Chat\Message;
use Bayti\Api\Domain\Chat\OrderChatProvisioner;
use Bayti\Api\Domain\Chat\OrderDetailsMessageBuilder;
use Bayti\Api\Domain\Order\Order;
use Bayti\Api\Domain\Order\OrderItem;
use Bayti\Api\Domain\User\User;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(OrderChatProvisioner::class)]
final class OrderChatProvisionerTest extends TestCase
{
    private function itemWithVendor(): OrderItem
    {
        $i = $this->createMock(OrderItem::class);
        $i->method('getVendor')->willReturn($this->createMock(Vendor::class));
        return $i;
    }

    /** @param list<OrderItem> $items */
    private function orderWith(array $items): Order
    {
        $o = $this->createMock(Order::class);
        $o->method('getItems')->willReturn(new ArrayCollection($items));
        $o->method('getUser')->willReturn($this->createMock(User::class));
        $o->method('getOrderReference')->willReturn('3B-TEST');
        return $o;
    }

    #[Test]
    public function createsOneConversationAndSystemMessagePerItem(): void
    {
        $persisted = [];
        $convRepo = $this->createMock(ConversationRepository::class);
        $convRepo->method('findByOrderItem')->willReturn(null);

        $em = $this->createMock(EntityManagerInterface::class);
        $em->method('getRepository')->with(Conversation::class)->willReturn($convRepo);
        $em->method('persist')->willReturnCallback(function ($e) use (&$persisted) {
            $persisted[] = $e;
        });
        $em->expects($this->once())->method('flush');

        $builder = $this->createMock(OrderDetailsMessageBuilder::class);
        $builder->method('build')->willReturn(['EN body', 'AR body']);

        $provisioner = new OrderChatProvisioner($em, $builder);
        $created = $provisioner->provisionForOrder($this->orderWith([
            $this->itemWithVendor(),
            $this->itemWithVendor(),
        ]));

        self::assertSame(2, $created);

        $conversations = array_values(array_filter($persisted, fn ($e) => $e instanceof Conversation));
        $messages = array_values(array_filter($persisted, fn ($e) => $e instanceof Message));
        self::assertCount(2, $conversations);
        self::assertCount(2, $messages);

        // System message carries the builder output + bilingual content.
        self::assertSame('EN body', $messages[0]->getContent());
        self::assertSame('AR body', $messages[0]->getContentAr());
        self::assertSame(Conversation::PARTY_SYSTEM, $messages[0]->getSenderType());

        // The seed bumps both parties' unread counters.
        self::assertSame(1, $conversations[0]->getVendorUnreadCount());
        self::assertSame(1, $conversations[0]->getCustomerUnreadCount());
        self::assertNotNull($conversations[0]->getLastMessageAt());
    }

    #[Test]
    public function isIdempotentWhenConversationAlreadyExists(): void
    {
        $convRepo = $this->createMock(ConversationRepository::class);
        $convRepo->method('findByOrderItem')->willReturn($this->createMock(Conversation::class));

        $em = $this->createMock(EntityManagerInterface::class);
        $em->method('getRepository')->with(Conversation::class)->willReturn($convRepo);
        $em->expects($this->never())->method('persist');
        $em->expects($this->never())->method('flush');

        $builder = $this->createMock(OrderDetailsMessageBuilder::class);
        $builder->expects($this->never())->method('build');

        $provisioner = new OrderChatProvisioner($em, $builder);
        $created = $provisioner->provisionForOrder($this->orderWith([$this->itemWithVendor()]));

        self::assertSame(0, $created);
    }

    #[Test]
    public function provisionsOnlyMissingItems(): void
    {
        $existingItem = $this->itemWithVendor();
        $newItem = $this->itemWithVendor();

        $convRepo = $this->createMock(ConversationRepository::class);
        $convRepo->method('findByOrderItem')->willReturnCallback(
            fn ($item) => $item === $existingItem ? $this->createMock(Conversation::class) : null
        );

        $persisted = [];
        $em = $this->createMock(EntityManagerInterface::class);
        $em->method('getRepository')->with(Conversation::class)->willReturn($convRepo);
        $em->method('persist')->willReturnCallback(function ($e) use (&$persisted) {
            $persisted[] = $e;
        });
        $em->expects($this->once())->method('flush');

        $builder = $this->createMock(OrderDetailsMessageBuilder::class);
        $builder->method('build')->willReturn(['EN', 'AR']);

        $provisioner = new OrderChatProvisioner($em, $builder);
        $created = $provisioner->provisionForOrder($this->orderWith([$existingItem, $newItem]));

        self::assertSame(1, $created);
        self::assertCount(1, array_filter($persisted, fn ($e) => $e instanceof Conversation));
    }
}
