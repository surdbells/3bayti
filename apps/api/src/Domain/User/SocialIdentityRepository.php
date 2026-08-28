<?php

declare(strict_types=1);

namespace Bayti\Api\Domain\User;

use Doctrine\ORM\EntityRepository;

/**
 * Repository for {@see SocialIdentity} entities.
 *
 * @extends EntityRepository<SocialIdentity>
 */
class SocialIdentityRepository extends EntityRepository
{
    /**
     * Resolve a social identity by its provider + provider subject id.
     *
     * This is the primary lookup for the social-login "find or create"
     * flow: a hit returns the already-linked identity (whose user we log
     * in); a miss means the provider account is unknown and we fall
     * through to auto-link-by-email or create-new.
     *
     * Backed by the UNIQUE(provider, provider_uid) constraint, so there
     * is at most one match.
     */
    public function findByProviderUid(string $provider, string $uid): ?SocialIdentity
    {
        return $this->createQueryBuilder('s')
            ->where('s.provider = :provider')
            ->andWhere('s.providerUid = :uid')
            ->setParameter('provider', $provider)
            ->setParameter('uid', $uid)
            ->getQuery()
            ->getOneOrNullResult();
    }

    /**
     * Every social identity linked to a user, backs the
     * GET /v3/me/social-identities "connected accounts" list and the
     * "would unlinking leave them with no sign-in method?" guard on
     * DELETE.
     *
     * @return SocialIdentity[]
     */
    public function findAllForUser(User $user): array
    {
        return $this->createQueryBuilder('s')
            ->where('s.user = :user')
            ->setParameter('user', $user)
            ->orderBy('s.createdAt', 'ASC')
            ->getQuery()
            ->getResult();
    }

    public function save(SocialIdentity $identity, bool $flush = true): void
    {
        $em = $this->getEntityManager();
        $em->persist($identity);
        if ($flush) {
            $em->flush();
        }
    }

    /**
     * Move every social identity from one user to another (account merge).
     *
     * A bulk DQL UPDATE rather than an entity mutator: SocialIdentity has no
     * setUser() by design (identities aren't casually reassigned), and this is
     * the single sanctioned path. The UNIQUE(provider, provider_uid) constraint
     * is unaffected — only user_id changes — so moving the source's identities
     * onto the target can't collide (the target never held those provider/uid
     * pairs). Returns the number of rows moved.
     *
     * Bulk updates bypass the UnitOfWork, so run this inside the merge
     * transaction and don't rely on already-loaded identity collections
     * afterwards.
     */
    public function reassignToUser(int $fromUserId, int $toUserId): int
    {
        $em = $this->getEntityManager();
        return (int) $em->createQuery(
            'UPDATE ' . SocialIdentity::class . ' s SET s.user = :to WHERE s.user = :from',
        )
            ->setParameter('to', $em->getReference(User::class, $toUserId))
            ->setParameter('from', $em->getReference(User::class, $fromUserId))
            ->execute();
    }
}
