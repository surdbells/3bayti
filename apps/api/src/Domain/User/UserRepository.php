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

    public function save(User $user, bool $flush = true): void
    {
        $em = $this->getEntityManager();
        $em->persist($user);
        if ($flush) {
            $em->flush();
        }
    }
}
