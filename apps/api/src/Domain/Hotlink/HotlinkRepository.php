<?php

declare(strict_types=1);

namespace Bayti\Api\Domain\Hotlink;

use Doctrine\ORM\EntityRepository;

/**
 * @extends EntityRepository<Hotlink>
 */
class HotlinkRepository extends EntityRepository
{
    public function save(Hotlink $hotlink): void
    {
        $em = $this->getEntityManager();
        $em->persist($hotlink);
        $em->flush();
    }

    /** Resolve a hotlink by its short code. */
    public function findByCode(string $code): ?Hotlink
    {
        return $this->findOneBy(['code' => $code]);
    }

    /** The canonical hotlink for a target (one per target_type + target_slug). */
    public function findByTarget(string $targetType, string $targetSlug): ?Hotlink
    {
        return $this->findOneBy(['targetType' => $targetType, 'targetSlug' => $targetSlug]);
    }

    /** Whether a code is already taken (for unique-code generation). */
    public function codeExists(string $code): bool
    {
        return $this->findOneBy(['code' => $code]) !== null;
    }
}
