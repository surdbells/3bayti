<?php

declare(strict_types=1);

namespace Bayti\Api\Domain\Chat;

use Bayti\Api\Domain\Order\Order;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * Creates the order-scoped chat threads for an order: one conversation per
 * order item, each seeded with a system message that lays out the full
 * order details. Idempotent, re-running for the same order skips items
 * that already have a conversation (the unique order_item_id index is the
 * final backstop), so it is safe to call from a payment webhook that may
 * be retried.
 */
class OrderChatProvisioner
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly OrderDetailsMessageBuilder $messageBuilder,
        private readonly LoggerInterface $logger = new NullLogger(),
    ) {
    }

    /**
     * Provision conversations for every item in the order. Returns the
     * number of conversations newly created.
     */
    public function provisionForOrder(Order $order): int
    {
        /** @var ConversationRepository $conversations */
        $conversations = $this->em->getRepository(Conversation::class);

        $created = 0;
        foreach ($order->getItems() as $item) {
            if ($conversations->findByOrderItem($item) !== null) {
                continue;
            }

            $conversation = new Conversation($order->getUser(), $item->getVendor(), $order, $item);
            $this->em->persist($conversation);

            [$en, $ar] = $this->messageBuilder->build($order, $item);
            $this->em->persist(Message::system($conversation, $en, $ar));
            $conversation->recordMessage(Conversation::PARTY_SYSTEM, $en);

            $created++;
        }

        if ($created > 0) {
            $this->em->flush();
            $this->logger->info('order_chat.provisioned', [
                'order_reference' => $order->getOrderReference(),
                'conversations'   => $created,
            ]);
        }

        return $created;
    }
}
