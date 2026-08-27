<?php

declare(strict_types=1);

namespace Bayti\Api\Domain\Notification;

use Bayti\Api\Domain\User\User;
use Doctrine\ORM\EntityRepository;
use Doctrine\ORM\QueryBuilder;

/**
 * Repository for DeviceToken entities.
 *
 * @extends EntityRepository<DeviceToken>
 */
class DeviceTokenRepository extends EntityRepository
{
    /**
     * Active tokens for a user, the push fan-out lookup. Returns the
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
     * Every active device token across all users, for admin broadcast.
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
     * One keyset-paginated batch of active device tokens for an audience -
     * ordered by id so a broadcast can stream the whole audience in bounded
     * chunks (never loading every recipient into memory). Returns the fields
     * the broadcast sender needs: id, token, platform, owning user id.
     *
     * User name fields are joined in so a broadcast with {{first_name}} etc.
     * can resolve per recipient without an N+1 lookup.
     *
     * @param 'all'|'customers'|'vendors'|'admins' $audience
     * @return list<array{id:int, token:string, platform:string, user_id:int, first_name:?string, last_name:?string, email:?string}>
     */
    public function findActiveForAudienceBatch(string $audience, int $afterId, int $limit): array
    {
        $qb = $this->createQueryBuilder('d')
            ->select(
                'd.id AS id',
                'd.token AS token',
                'd.platform AS platform',
                'IDENTITY(d.user) AS user_id',
                'u.firstName AS first_name',
                'u.lastName AS last_name',
                'u.email AS email',
            )
            ->leftJoin('d.user', 'u')
            ->where('d.isActive = true')
            ->andWhere('d.id > :afterId')
            ->setParameter('afterId', $afterId)
            ->orderBy('d.id', 'ASC')
            ->setMaxResults(max(1, $limit));

        if ($audience !== 'all') {
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

        /** @var list<array{id:mixed, token:mixed, platform:mixed, user_id:mixed, first_name:mixed, last_name:mixed, email:mixed}> $rows */
        $rows = $qb->getQuery()->getResult();
        return array_map(static fn (array $r): array => [
            'id' => (int) $r['id'],
            'token' => (string) $r['token'],
            'platform' => (string) $r['platform'],
            'user_id' => (int) $r['user_id'],
            'first_name' => $r['first_name'] !== null ? (string) $r['first_name'] : null,
            'last_name' => $r['last_name'] !== null ? (string) $r['last_name'] : null,
            'email' => $r['email'] !== null ? (string) $r['email'] : null,
        ], $rows);
    }

    /**
     * Active-token counts for an audience, split by platform, powers the
     * compose-time audience preview and the broadcast totals.
     *
     * @param 'all'|'customers'|'vendors'|'admins' $audience
     * @return array{total:int, android:int, ios:int}
     */
    public function countActiveForAudienceByPlatform(string $audience): array
    {
        $qb = $this->createQueryBuilder('d')
            ->select('d.platform AS platform', 'COUNT(d.id) AS cnt')
            ->where('d.isActive = true')
            ->groupBy('d.platform');
        $this->applyAudience($qb, $audience);

        $android = 0;
        $ios = 0;
        foreach ($qb->getQuery()->getResult() as $row) {
            if ($row['platform'] === DeviceToken::PLATFORM_IOS) {
                $ios = (int) $row['cnt'];
            } elseif ($row['platform'] === DeviceToken::PLATFORM_ANDROID) {
                $android = (int) $row['cnt'];
            }
        }
        return ['total' => $android + $ios, 'android' => $android, 'ios' => $ios];
    }

    /** Narrow a device-token query to an audience by the owner's role flag. */
    private function applyAudience(QueryBuilder $qb, string $audience): void
    {
        if ($audience === 'all') {
            return;
        }
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
     * Register (upsert) a device token for a user. If the token row already
     * exists it's re-owned / reactivated / re-stamped (mirrors
     * DeviceToken::touch()); otherwise a new row is created. Idempotent.
     *
     * Uses a single atomic Postgres UPSERT (ON CONFLICT) rather than a
     * check-then-insert. The old check-then-insert raced under concurrent
     * registrations, the app re-registers its FCM token on app open/resume,
     * so two near-simultaneous POSTs both saw no row and both inserted,
     * violating uq_device_tokens_token with a 500 (Sentry PHP-11). ON CONFLICT
     * is race-proof and never trips the unique index.
     *
     * created_at is only set on INSERT (preserved on conflict). $flush is kept
     * for signature stability but is now a no-op, the UPSERT commits itself.
     */
    public function register(User $user, string $token, string $platform, bool $flush = true): DeviceToken
    {
        // NB: date_trunc('second', …), NOT now(): the datetimetz columns are
        // hydrated by Doctrine's DateTimeTzImmutableType with the format
        // "Y-m-d H:i:sO" (seconds only). now() writes microseconds, which then
        // fail to convert back on read (Sentry PHP-1P). Second precision matches
        // what the ORM itself writes, so the row round-trips cleanly.
        $this->getEntityManager()->getConnection()->executeStatement(
            <<<'SQL'
            INSERT INTO device_tokens
                (user_id, token, platform, is_active, created_at, updated_at, last_seen_at)
            VALUES
                (:user_id, :token, :platform, TRUE,
                 date_trunc('second', now()), date_trunc('second', now()), date_trunc('second', now()))
            ON CONFLICT (token) DO UPDATE SET
                user_id      = EXCLUDED.user_id,
                platform     = EXCLUDED.platform,
                is_active    = TRUE,
                updated_at   = date_trunc('second', now()),
                last_seen_at = date_trunc('second', now())
            SQL,
            [
                'user_id'  => $user->getId(),
                'token'    => $token,
                'platform' => $platform,
            ],
        );

        // Return the row in its post-upsert state. If the caller's "is this
        // new?" probe already loaded a now-stale instance into the identity
        // map, refresh() re-syncs it with the row we just wrote.
        $entity = $this->findOneByToken($token);
        if ($entity === null) {
            // Unreachable right after the upsert; keep the return type honest.
            return new DeviceToken($user, $token, $platform);
        }
        $this->getEntityManager()->refresh($entity);
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
     * Deactivate a token by string regardless of owner, used by the
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
