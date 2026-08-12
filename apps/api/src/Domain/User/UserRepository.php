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
     * Find a user by phone, tolerant of stored format. Excludes soft-deleted.
     *
     * The phone column is not normalized: new sign-ups store E.164
     * ('+971506995999') while legacy-migrated rows store a local number
     * ('0506995999' / '506995999'). An exact match therefore missed migrated
     * users on OTP login (the app always sends E.164), so no SMS was sent.
     * We match against every plausible stored shape of the same number.
     *
     * When more than one shape exists for the same person, the canonical
     * E.164 form is preferred ('+' sorts before digits).
     */
    public function findByPhone(string $phone): ?User
    {
        $candidates = self::phoneMatchCandidates($phone);
        if ($candidates === []) {
            return null;
        }

        return $this->createQueryBuilder('u')
            ->where('u.phone IN (:phones)')
            ->andWhere('u.deletedAt IS NULL')
            ->setParameter('phones', $candidates)
            ->orderBy('u.phone', 'ASC')
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();
    }

    /**
     * Every plausible stored shape of a phone number, so a lookup matches
     * whether it was saved as E.164 ('+971506995999'), with the country code
     * but no '+', or as a local number with/without a leading zero. Bare
     * digits with no recognizable country code are treated as a local UAE
     * (+971) number — the platform default.
     *
     * @return list<string>
     */
    public static function phoneMatchCandidates(string $phone): array
    {
        $raw = trim($phone);
        if ($raw === '') {
            return [];
        }

        $digits = preg_replace('/\D/', '', $raw) ?? '';
        if ($digits === '') {
            return array_values(array_unique([$raw, $phone]));
        }
        if (str_starts_with($digits, '00')) {
            $digits = substr($digits, 2);
        }

        // GCC dial codes the platform serves. Split off a leading one when the
        // remaining national part is long enough to be a real number.
        $dial = null;
        $national = $digits;
        foreach (['971', '966', '965', '974', '973', '968'] as $dc) {
            if (str_starts_with($digits, $dc) && strlen($digits) - strlen($dc) >= 6) {
                $dial = $dc;
                $national = substr($digits, strlen($dc));
                break;
            }
        }
        if ($dial === null) {
            $dial = '971';
        }
        $national = ltrim($national, '0');
        if ($national === '') {
            return array_values(array_unique(array_filter([$raw, $phone])));
        }

        return array_values(array_unique(array_filter([
            '+' . $dial . $national, // canonical E.164
            $dial . $national,       // country code, no '+'
            $national,               // local, no leading zero
            '0' . $national,         // local, leading zero
            $raw,                    // caller's original input
            $phone,
        ], static fn (string $c): bool => $c !== '')));
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

    /**
     * Paginated list of users with optional role + search filters.
     * Used by GET /v3/admin/users (M3.3.2-C).
     *
     * @param array{role?:string|null,search?:string|null,limit?:int,offset?:int} $filters
     * @return array{items: list<User>, total: int}
     */
    public function findPaginated(array $filters = []): array
    {
        $qb = $this->createQueryBuilder('u')
            ->where('u.deletedAt IS NULL');

        if (!empty($filters['role'])) {
            $role = $filters['role'];
            $map  = [
                'admin'    => 'u.isAdmin = true',
                'vendor'   => 'u.isVendor = true',
                'customer' => 'u.isCustomer = true',
                'finance'  => 'u.isFinance = true',
                'support'  => 'u.isSupport = true',
            ];
            if (isset($map[$role])) {
                $qb->andWhere($map[$role]);
            }
        }

        if (!empty($filters['staff'])) {
            // Staff = anyone with back-office access. This must catch a freshly
            // created back-office account BEFORE it holds any RBAC role, otherwise
            // the account is invisible on the Staff screen and can never be
            // assigned a role (chicken-and-egg). We therefore admit:
            //   - full admins (is_admin), the super-admin bypass;
            //   - holders of at least one RBAC role;
            //   - back-office flag markers (finance / support / sub_admin) which
            //     CreateUserController stamps at creation time.
            $qb->andWhere(
                'u.isAdmin = true '
                . 'OR u.isFinance = true '
                . 'OR u.isSupport = true '
                . 'OR u.isSubAdmin = true '
                . 'OR SIZE(u.roles) > 0',
            );
        }

        if (!empty($filters['search'])) {
            $needle = '%' . $filters['search'] . '%';
            $qb->andWhere(
                $qb->expr()->orX(
                    $qb->expr()->like('u.email', ':s'),
                    $qb->expr()->like('u.firstName', ':s'),
                    $qb->expr()->like('u.lastName', ':s'),
                ),
            )->setParameter('s', $needle);
        }

        $countQb = clone $qb;
        $total   = (int) $countQb->select('COUNT(u.id)')->getQuery()->getSingleScalarResult();

        $qb->orderBy('u.id', 'DESC')
           ->setMaxResults($filters['limit'] ?? 20)
           ->setFirstResult($filters['offset'] ?? 0);

        /** @var list<User> $items */
        $items = $qb->getQuery()->getResult();

        return ['items' => $items, 'total' => $total];
    }

    /**
     * Paginated customer listing for the admin Customers screen.
     *
     * Returns each matched customer paired with their lifetime orders_count,
     * computed in ONE grouped query (LEFT JOIN orders + COUNT, GROUP BY user)
     * so the listing never fires an N+1 Order count per row.
     *
     * Filters (all optional, applied only when present):
     *   - search        : email / first name / last name LIKE
     *   - status        : 'active' | 'inactive'  → is_active = true/false
     *   - email_verified: bool → is_email_verified
     *   - phone_verified: bool → is_phone_verified
     *   - created_from  : DateTimeImmutable → created_at >= (inclusive)
     *   - created_to    : DateTimeImmutable → created_at <= (inclusive end-of-day handled by caller)
     *   - min_orders    : int → HAVING COUNT(orders) >= n
     *
     * @param array{
     *   search?:string|null,
     *   status?:string|null,
     *   email_verified?:bool|null,
     *   phone_verified?:bool|null,
     *   created_from?:\DateTimeImmutable|null,
     *   created_to?:\DateTimeImmutable|null,
     *   min_orders?:int|null,
     *   limit?:int,
     *   offset?:int
     * } $filters
     * @return array{items: list<array{user: User, orders_count: int}>, total: int}
     */
    public function findCustomersPaginated(array $filters = []): array
    {
        $qb = $this->createQueryBuilder('u')
            ->where('u.deletedAt IS NULL')
            ->andWhere('u.isCustomer = true');

        if (!empty($filters['search'])) {
            $needle = '%' . $filters['search'] . '%';
            $qb->andWhere(
                $qb->expr()->orX(
                    $qb->expr()->like('u.email', ':s'),
                    $qb->expr()->like('u.firstName', ':s'),
                    $qb->expr()->like('u.lastName', ':s'),
                ),
            )->setParameter('s', $needle);
        }

        if (isset($filters['status']) && $filters['status'] !== null && $filters['status'] !== '') {
            if ($filters['status'] === 'active') {
                $qb->andWhere('u.isActive = true');
            } elseif ($filters['status'] === 'inactive') {
                $qb->andWhere('u.isActive = false');
            }
        }

        if (isset($filters['email_verified']) && $filters['email_verified'] !== null) {
            $qb->andWhere('u.isEmailVerified = :emailVerified')
               ->setParameter('emailVerified', (bool) $filters['email_verified']);
        }

        if (isset($filters['phone_verified']) && $filters['phone_verified'] !== null) {
            $qb->andWhere('u.isPhoneVerified = :phoneVerified')
               ->setParameter('phoneVerified', (bool) $filters['phone_verified']);
        }

        if (!empty($filters['created_from'])) {
            $qb->andWhere('u.createdAt >= :createdFrom')
               ->setParameter('createdFrom', $filters['created_from']);
        }

        if (!empty($filters['created_to'])) {
            $qb->andWhere('u.createdAt <= :createdTo')
               ->setParameter('createdTo', $filters['created_to']);
        }

        $minOrders = isset($filters['min_orders']) ? (int) $filters['min_orders'] : 0;

        // Total count of matching customers. When min_orders is in play the
        // count must respect the HAVING clause, so we count the grouped rows.
        if ($minOrders > 0) {
            $countQb = clone $qb;
            $countQb->leftJoin(
                \Bayti\Api\Domain\Order\Order::class,
                'oc',
                'WITH',
                'oc.user = u',
            )
            ->select('u.id')
            ->groupBy('u.id')
            ->having('COUNT(oc.id) >= :minOrders')
            ->setParameter('minOrders', $minOrders);
            $total = count($countQb->getQuery()->getScalarResult());
        } else {
            $countQb = clone $qb;
            $total = (int) $countQb->select('COUNT(u.id)')->getQuery()->getSingleScalarResult();
        }

        // Page query: pull the User entities plus their grouped orders_count.
        $qb->leftJoin(\Bayti\Api\Domain\Order\Order::class, 'o', 'WITH', 'o.user = u')
            ->select('u AS user', 'COUNT(o.id) AS orders_count')
            ->groupBy('u.id')
            ->orderBy('u.id', 'DESC')
            ->setMaxResults($filters['limit'] ?? 20)
            ->setFirstResult($filters['offset'] ?? 0);

        if ($minOrders > 0) {
            $qb->having('COUNT(o.id) >= :minOrders')
               ->setParameter('minOrders', $minOrders);
        }

        /** @var list<array{user: User, orders_count: int|string}> $rows */
        $rows = $qb->getQuery()->getResult();

        $items = array_map(
            static fn (array $row): array => [
                'user' => $row['user'],
                'orders_count' => (int) $row['orders_count'],
            ],
            $rows,
        );

        return ['items' => $items, 'total' => $total];
    }

    /**
     * Find lapsed users eligible for a re-engagement PUSH nudge
     * (balanced cadence).
     *
     * Eligibility (all must hold):
     *   1. last_login_at <= (now - 14 days)   — genuinely lapsed
     *   2. has >= 1 active device token        — a push can land
     *   3. marketing_push_opt_out = false      — consented to marketing
     *   4. NOT soft-deleted
     *   5. NOT nudged in the last 14 days: no notification_logs row with
     *      user_id = u.id AND template='re_engagement.nudge' AND
     *      channel='push' AND sent_at > (now - 14 days)
     *
     * The opt-out + the cadence guard are BOTH enforced here so the
     * finder returns a tight candidate set; PushNotificationService
     * ::reEngagementNudge re-checks the opt-out at dispatch (defence in
     * depth — the flag could flip between this query and the send).
     *
     * Raw SQL (not DQL) because the cadence guard references the
     * unmapped notification_logs.user_id / channel columns. Returns ids;
     * the caller hydrates one user at a time to bound memory.
     *
     * @return list<int>
     */
    public function findLapsedForReengagement(
        \DateTimeImmutable $now,
        \DateInterval $lapsedThreshold,
        \DateInterval $cadence,
        int $batchLimit,
    ): array {
        $batchLimit = max(1, min($batchLimit, 500));

        $lapsedBefore = $now->sub($lapsedThreshold);
        $nudgedAfter = $now->sub($cadence);

        $sql = <<<'SQL'
            SELECT u.id
            FROM users u
            WHERE u.deleted_at IS NULL
              AND u.marketing_push_opt_out = false
              AND u.last_login_at IS NOT NULL
              AND u.last_login_at <= :lapsedBefore
              AND EXISTS (
                  SELECT 1 FROM device_tokens dt
                  WHERE dt.user_id = u.id AND dt.is_active = true
              )
              AND NOT EXISTS (
                  SELECT 1 FROM notification_logs nl
                  WHERE nl.user_id = u.id
                    AND nl.template = :tpl
                    AND nl.channel = :ch
                    AND nl.sent_at > :nudgedAfter
              )
            ORDER BY u.last_login_at ASC
            LIMIT :lim
        SQL;

        $rows = $this->getEntityManager()->getConnection()->executeQuery(
            $sql,
            [
                'lapsedBefore' => $lapsedBefore->format('Y-m-d H:i:sP'),
                'tpl' => 're_engagement.nudge',
                'ch' => 'push',
                'nudgedAfter' => $nudgedAfter->format('Y-m-d H:i:sP'),
                'lim' => $batchLimit,
            ],
            [
                'lapsedBefore' => \Doctrine\DBAL\ParameterType::STRING,
                'tpl' => \Doctrine\DBAL\ParameterType::STRING,
                'ch' => \Doctrine\DBAL\ParameterType::STRING,
                'nudgedAfter' => \Doctrine\DBAL\ParameterType::STRING,
                'lim' => \Doctrine\DBAL\ParameterType::INTEGER,
            ],
        )->fetchAllAssociative();

        /** @var list<int> $ids */
        $ids = array_map(static fn (array $r): int => (int) $r['id'], $rows);
        return $ids;
    }

    public function save(User $user, bool $flush = true): void
    {
        $em = $this->getEntityManager();
        $em->persist($user);
        if ($flush) {
            $em->flush();
        }
    }
}
