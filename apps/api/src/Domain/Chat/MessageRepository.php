<?php

declare(strict_types=1);

namespace Bayti\Api\Domain\Chat;

use Doctrine\ORM\EntityRepository;

/**
 * @extends EntityRepository<Message>
 */
class MessageRepository extends EntityRepository
{
    public function save(Message $message): void
    {
        $em = $this->getEntityManager();
        $em->persist($message);
        $em->flush();
    }

    /** Persist without flushing — for batching inside an existing UoW. */
    public function add(Message $message): void
    {
        $this->getEntityManager()->persist($message);
    }

    /**
     * Messages in a conversation, oldest→newest, page by ascending id with
     * an optional `afterId` cursor (for "load newer") or `beforeId` (for
     * "load older"). Blocked messages are visible only to their own sender,
     * so callers pass $includeBlockedSenderType to reveal the viewer's own
     * blocked attempts while hiding the other party's.
     *
     * @return list<Message>
     */
    public function findForConversation(
        int $conversationId,
        int $limit,
        ?int $beforeId = null,
        ?int $afterId = null,
        ?string $viewerParty = null,
    ): array {
        $qb = $this->createQueryBuilder('m')
            ->where('m.conversation = :cid')
            ->setParameter('cid', $conversationId);

        // Delivered messages are visible to everyone; a blocked message is
        // visible only to the party that sent it.
        if ($viewerParty !== null) {
            $qb->andWhere('(m.status = :sent OR m.status = :redacted OR (m.status = :blocked AND m.senderType = :viewer))')
                ->setParameter('sent', Message::STATUS_SENT)
                ->setParameter('redacted', Message::STATUS_REDACTED)
                ->setParameter('blocked', Message::STATUS_BLOCKED)
                ->setParameter('viewer', $viewerParty);
        } else {
            $qb->andWhere('m.status != :blocked')->setParameter('blocked', Message::STATUS_BLOCKED);
        }

        if ($beforeId !== null) {
            $qb->andWhere('m.id < :before')->setParameter('before', $beforeId);
        }
        if ($afterId !== null) {
            $qb->andWhere('m.id > :after')->setParameter('after', $afterId);
        }

        $items = $qb->orderBy('m.id', 'DESC')
            ->setMaxResults($limit)
            ->getQuery()->getResult();

        // Return chronological (oldest first) regardless of paging direction.
        return array_reverse($items);
    }

    public function findByUuid(string $uuid): ?Message
    {
        return $this->createQueryBuilder('m')
            ->where('m.uuid = :u')->setParameter('u', $uuid)
            ->getQuery()->getOneOrNullResult();
    }
}
