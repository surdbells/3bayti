<?php

declare(strict_types=1);

namespace Bayti\Api\Domain\Ota;

use Doctrine\ORM\EntityRepository;

/**
 * @extends EntityRepository<OtaBundle>
 *
 * Resolved via $em->getRepository(OtaBundle::class), the entity declares this
 * as its repositoryClass, so no explicit DI registration is needed.
 */
class OtaBundleRepository extends EntityRepository
{
    /**
     * Every version ever published for an app + platform + channel (active or
     * not), used to pick a rollback version that no device has seen yet.
     *
     * @return list<string>
     */
    public function versionsFor(string $appId, string $platform, string $channel): array
    {
        $rows = $this->createQueryBuilder('b')
            ->select('b.version')
            ->where('b.appId = :appId')
            ->andWhere('b.platform = :platform')
            ->andWhere('b.channel = :channel')
            ->setParameter('appId', $appId)
            ->setParameter('platform', $platform)
            ->setParameter('channel', $channel)
            ->getQuery()
            ->getSingleColumnResult();

        return array_values(array_map('strval', $rows));
    }

    /**
     * All ACTIVE bundles for an app + platform + channel (a small set: one row
     * per release, and every rollback deactivates the rest). The update endpoint
     * picks the highest compatible version among them in PHP, since SQL can't
     * order dotted versions numerically.
     *
     * @return list<OtaBundle>
     */
    public function activeFor(string $appId, string $platform, string $channel): array
    {
        /** @var list<OtaBundle> $rows */
        $rows = $this->findBy([
            'appId' => $appId,
            'platform' => $platform,
            'channel' => $channel,
            'isActive' => true,
        ]);

        return $rows;
    }

    /** How many rows point at this bundle URL (rollbacks share their source's file). */
    public function countByUrl(string $url): int
    {
        return $this->count(['url' => $url]);
    }
}
