<?php

declare(strict_types=1);

namespace Bayti\Api\Domain\Notification;

use Bayti\Api\Domain\User\User;
use Doctrine\ORM\EntityRepository;

/**
 * Repository for DeviceToken entities.
 *
 * @extends EntityRepository<DeviceToken>
 */
class DeviceTokenRepository extends EntityRepository
{
    /**
     * Active tokens for a user — the push fan-out lookup. Returns the
     * raw token strings (the sender only needs the strings, not the
     * entities), newest-seen first.
     *
     * @return list<string>
     */
    public function findActiveTokenStringsForUser(User $user): array
    {
        /** @var list<array{token:string}> $rows */
        $rows = $this->createQueryBuilder('d')
            ->select('d.token')
            ->where('d.user = :user')
            ->andWhere('d.isActive = true')
            ->setParameter('user', $user)
            ->orderBy('d.lastSeenAt', 'DESC')
            ->addOrderBy('d.id', 'DESC')
            ->getQuery()
            ->getResult();

        return array_map(static fn (array $row): string => $row['token'], $rows);
    }

    /**
     * Every active device token across all users — for admin broadcast.
     * Optionally narrowed to an audience by user role flag.
     *
     * @param 'all'|'customers'|'vendors'|'admins' $audience
     * @return list<string>
     */
    public function findAllActiveTokenStrings(string $audience = 'all'): array
    {
        $qb = $this->createQueryBuilder('d')
            ->select('DISTINCT d.token')
            ->where('d.isActive = true');

        if ($audience !== 'all') {
            $qb->innerJoin('d.user', 'u');
            $flag = match ($audience) {
                'customers' => 'u.isCustomer',
                'vendors'   => 'u.isVendor',
                'admins'    => 'u.isAdmin',
                default     => null,
            };
            if ($flag !== null) {
                $qb->andWhere($flag . ' = true');
            }
        }

        /** @var list<array{token:string}> $rows */
        $rows = $qb->getQuery()->getResult();

        return array_map(static fn (array $row): string => $row['token'], $rows);
    }

    /**
     * The full active DeviceToken entities for a user. Used where the
     * caller needs to act on the rows (e.g. mark dead tokens inactive
     * after a send). Newest-seen first.
     *
     * @return list<DeviceToken>
     */
    public function findActiveForUser(User $user): array
    {
        /** @var list<DeviceToken> $rows */
        $rows = $this->createQueryBuilder('d')
            ->where('d.user = :user')
            ->andWhere('d.isActive = true')
            ->setParameter('user', $user)
            ->orderBy('d.lastSeenAt', 'DESC')
            ->addOrderBy('d.id', 'DESC')
            ->getQuery()
            ->getResult();

        return $rows;
    }

    /** The row for a given token string, or null. */
    public function findOneByToken(string $token): ?DeviceToken
    {
        return $this->createQueryBuilder('d')
            ->where('d.token = :token')
            ->setParameter('token', $token)
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();
    }

    /**
     * Register (upsert) a device token for a user. If the token row
     * already exists it's touched (re-owned / reactivated / re-stamped);
     * otherwise a new row is created. Idempotent by design.
     */
    public function register(User $user, string $token, string $platform, bool $flush = true): DeviceToken
    {
        $existing = $this->findOneByToken($token);
        if ($existing !== null) {
            $existing->touch($user, $platform);
            $entity = $existing;
        } else {
            $entity = new DeviceToken($user, $token, $platform);
            $this->getEntityManager()->persist($entity);
        }
        if ($flush) {
            $this->getEntityManager()->flush();
        }
        return $entity;
    }

    /**
     * Deactivate a token by its string, scoped to the owning user so a
     * caller can only deactivate their own device tokens. No-op (returns
     * false) if the token doesn't exist or isn't owned by this user.
     */
    public function deactivateForUser(User $user, string $token, bool $flush = true): bool
    {
        $entity = $this->findOneByToken($token);
        if ($entity === null || $entity->getUser()->getId() !== $user->getId()) {
            return false;
        }
        $entity->deactivate();
        if ($flush) {
            $this->getEntityManager()->flush();
        }
        return true;
    }

    /**
     * Deactivate a token by string regardless of owner — used by the
     * push sender when FCM reports a token is permanently invalid
     * (UNREGISTERED). No-op if the token is unknown.
     */
    public function deactivateByToken(string $token, bool $flush = true): bool
    {
        $entity = $this->findOneByToken($token);
        if ($entity === null) {
            return false;
        }
        $entity->deactivate();
        if ($flush) {
            $this->getEntityManager()->flush();
        }
        return true;
    }
}
