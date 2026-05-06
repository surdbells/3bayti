<?php

declare(strict_types=1);

namespace Bayti\Api\Domain\User;

use Doctrine\ORM\EntityRepository;

/**
 * Repository for User entities.
 *
 * Doctrine 3's default EntityRepository has untyped magic finders;
 * this subclass wraps them with typed methods so calling code gets
 * IDE autocomplete and PHPStan can verify the calls.
 *
 * Convention: every "find" method returns nullable for "not found"
 * cases; methods that name a stronger contract (e.g. "findOneByIdOrFail")
 * throw on missing.
 *
 * Soft-delete: by default, queries here filter out users with
 * deleted_at IS NOT NULL. Use the explicit *IncludingDeleted variants
 * for admin recovery flows.
 *
 * @extends EntityRepository<User>
 */
class UserRepository extends EntityRepository
{
    /**
     * Find a user by their primary id, excluding soft-deleted users.
     */
    public function findById(int $id): ?User
    {
        return $this->createQueryBuilder('u')
            ->where('u.id = :id')
            ->andWhere('u.deletedAt IS NULL')
            ->setParameter('id', $id)
            ->getQuery()
            ->getOneOrNullResult();
    }

    /**
     * Find a user by email (case-insensitive). Excludes soft-deleted.
     * The unique index on email guarantees at most one match.
     */
    public function findByEmail(string $email): ?User
    {
        $email = strtolower(trim($email));
        return $this->createQueryBuilder('u')
            ->where('LOWER(u.email) = :email')
            ->andWhere('u.deletedAt IS NULL')
            ->setParameter('email', $email)
            ->getQuery()
            ->getOneOrNullResult();
    }

    /**
     * Find a user by phone. Excludes soft-deleted.
     */
    public function findByPhone(string $phone): ?User
    {
        return $this->createQueryBuilder('u')
            ->where('u.phone = :phone')
            ->andWhere('u.deletedAt IS NULL')
            ->setParameter('phone', $phone)
            ->getQuery()
            ->getOneOrNullResult();
    }

    /**
     * Find a user by their legacy MySQL id. Used by the CDC sync
     * (M1.8) when applying writes from the legacy side. Includes
     * deleted users — CDC needs to update them too.
     */
    public function findByLegacyId(int $legacyUserId): ?User
    {
        return $this->createQueryBuilder('u')
            ->where('u.legacyUserId = :legacyId')
            ->setParameter('legacyId', $legacyUserId)
            ->getQuery()
            ->getOneOrNullResult();
    }

    /**
     * Email-availability check used by /v3/auth/validate-email.
     * Returns true iff no active user has this email.
     */
    public function isEmailAvailable(string $email): bool
    {
        return $this->findByEmail($email) === null;
    }

    /**
     * Phone-availability check used by /v3/auth/validate-phone.
     */
    public function isPhoneAvailable(string $phone): bool
    {
        return $this->findByPhone($phone) === null;
    }

    /**
     * Persist + flush convenience for caller code that doesn't want
     * to thread EntityManager around. Use sparingly — services that
     * batch multiple operations should flush themselves.
     */
    public function save(User $user, bool $flush = true): void
    {
        $em = $this->getEntityManager();
        $em->persist($user);
        if ($flush) {
            $em->flush();
        }
    }
}
