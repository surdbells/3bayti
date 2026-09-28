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
     * The newest ACTIVE bundle for an app + platform + channel, or null.
     *
     * Ordered by created_at DESC (not by version) so that retiring a bad
     * bundle, setting is_active=false, serves the previously published one to
     * devices that never downloaded the bad one. Devices that already did are
     * only reached by a strictly newer version, see OtaRollbackService. Semver comparison against the device's current/native
     * version is done by the caller (SQL can't order semver correctly).
     */
    public function latestActive(string $appId, string $platform, string $channel): ?OtaBundle
    {
        return $this->createQueryBuilder('b')
            ->where('b.appId = :appId')
            ->andWhere('b.platform = :platform')
            ->andWhere('b.channel = :channel')
            ->andWhere('b.isActive = true')
            ->setParameter('appId', $appId)
            ->setParameter('platform', $platform)
            ->setParameter('channel', $channel)
            ->orderBy('b.createdAt', 'DESC')
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();
    }

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
     * All ACTIVE bundles for an app + platform + channel.
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
