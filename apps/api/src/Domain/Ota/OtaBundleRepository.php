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
     * bundle, setting is_active=false, cleanly falls back to the previously
     * published one. Semver comparison against the device's current/native
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
}
