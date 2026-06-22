<?php

declare(strict_types=1);

namespace Bayti\Api\Http\Controllers\Admin\Vendor;

use Bayti\Api\Domain\Catalog\Vendor;
use Bayti\Api\Http\Responder;
use Bayti\Api\Http\Serializers\VendorSerializer;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * GET /v3/admin/vendors
 *   [?search=&limit=&offset=&sort=&dir=
 *    &status=&is_active=&is_verified=&is_featured=&emirate=
 *    &created_from=&created_to=]
 *
 * Lists vendors (active + inactive) with admin shape. Supports
 * case-insensitive search across name, slug, and contact email,
 * limit/offset pagination, whitelisted single-column sort, plus a set
 * of optional filters (only applied when present). Response includes a
 * meta block with the total count so the portal can paginate.
 */
final class ListVendorsAdminController
{
    use Responder;

    private const DEFAULT_LIMIT = 50;
    private const MAX_LIMIT     = 200;

    /**
     * Whitelist of sortable columns: request `sort` key => entity field.
     * Anything outside this map falls back to the default (name ASC) so
     * a caller can never inject an arbitrary ORDER BY expression.
     */
    private const SORTABLE = [
        'name'        => 'v.name',
        'slug'        => 'v.slug',
        'status'      => 'v.status',
        'is_active'   => 'v.isActive',
        'is_verified' => 'v.isVerified',
        'is_featured' => 'v.isFeatured',
        'emirate'     => 'v.emirate',
        'country'     => 'v.country',
        'created_at'  => 'v.createdAt',
        'updated_at'  => 'v.updatedAt',
    ];

    public function __construct(
        protected readonly ResponseFactoryInterface $responseFactory,
        private readonly EntityManagerInterface $em,
        private readonly VendorSerializer $serializer,
    ) {
    }

    protected function getResponseFactory(): ResponseFactoryInterface
    {
        return $this->responseFactory;
    }

    public function __invoke(ServerRequestInterface $request): ResponseInterface
    {
        $q      = $request->getQueryParams();
        $search = isset($q['search']) ? trim((string) $q['search']) : '';
        $limit  = $this->clampLimit($q['limit'] ?? null);
        $offset = max(0, (int) ($q['offset'] ?? 0));

        $qb = $this->em->getRepository(Vendor::class)
            ->createQueryBuilder('v');

        if ($search !== '') {
            $qb->andWhere(
                'LOWER(v.name) LIKE :s OR LOWER(v.slug) LIKE :s OR LOWER(v.contactEmail) LIKE :s'
            )->setParameter('s', '%' . mb_strtolower($search) . '%');
        }

        $this->applyFilters($qb, $q);

        // Total (count) — reset orderBy on the clone to keep PostgreSQL happy.
        $countQb = clone $qb;
        $total   = (int) $countQb->select('COUNT(v.id)')
            ->resetDQLPart('orderBy')
            ->getQuery()
            ->getSingleScalarResult();

        [$sortField, $sortDir] = $this->resolveSort($q);

        $vendors = $qb->orderBy($sortField, $sortDir)
            ->setFirstResult($offset)
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();

        return $this->ok([
            'vendors' => $this->serializer->adminShapeMany($vendors),
            'meta'    => [
                'total'  => $total,
                'limit'  => $limit,
                'offset' => $offset,
            ],
        ]);
    }

    private function clampLimit(mixed $raw): int
    {
        $limit = (int) ($raw ?? self::DEFAULT_LIMIT);
        if ($limit <= 0) {
            $limit = self::DEFAULT_LIMIT;
        }
        return min($limit, self::MAX_LIMIT);
    }

    /**
     * Resolve the ORDER BY field + direction from the request, falling
     * back to name ASC. `sort` is matched against the SORTABLE whitelist;
     * `dir` is normalised to ASC/DESC.
     *
     * @param array<string, mixed> $q
     * @return array{0: string, 1: string} [field, direction]
     */
    private function resolveSort(array $q): array
    {
        $sortKey = isset($q['sort']) ? strtolower(trim((string) $q['sort'])) : '';
        $field   = self::SORTABLE[$sortKey] ?? 'v.name';

        $dir = isset($q['dir']) && strtolower(trim((string) $q['dir'])) === 'desc'
            ? 'DESC'
            : 'ASC';

        return [$field, $dir];
    }

    /**
     * Apply the optional filters to the query builder. Each filter is
     * only added when its param is present (and non-empty), so an
     * unfiltered request keeps the previous behaviour.
     *
     * @param array<string, mixed> $q
     */
    private function applyFilters(\Doctrine\ORM\QueryBuilder $qb, array $q): void
    {
        // status — pending | approved | suspended (validated against the
        // entity's known statuses; an unknown value is ignored).
        if (isset($q['status']) && trim((string) $q['status']) !== '') {
            $status = trim((string) $q['status']);
            if (in_array($status, Vendor::ALL_STATUSES, true)) {
                $qb->andWhere('v.status = :status')->setParameter('status', $status);
            }
        }

        // Boolean flags — accept 1/0/true/false.
        if (($isActive = $this->boolOrNull($q['is_active'] ?? null)) !== null) {
            $qb->andWhere('v.isActive = :is_active')->setParameter('is_active', $isActive);
        }
        if (($isVerified = $this->boolOrNull($q['is_verified'] ?? null)) !== null) {
            $qb->andWhere('v.isVerified = :is_verified')->setParameter('is_verified', $isVerified);
        }
        if (($isFeatured = $this->boolOrNull($q['is_featured'] ?? null)) !== null) {
            $qb->andWhere('v.isFeatured = :is_featured')->setParameter('is_featured', $isFeatured);
        }

        // emirate — exact (case-insensitive) match.
        if (isset($q['emirate']) && trim((string) $q['emirate']) !== '') {
            $qb->andWhere('LOWER(v.emirate) = :emirate')
                ->setParameter('emirate', mb_strtolower(trim((string) $q['emirate'])));
        }

        // created_at range — inclusive. Parse leniently; ignore unparsable.
        if (($from = $this->dateOrNull($q['created_from'] ?? null)) !== null) {
            $qb->andWhere('v.createdAt >= :created_from')->setParameter('created_from', $from);
        }
        if (($to = $this->dateOrNull($q['created_to'] ?? null, endOfDay: true)) !== null) {
            $qb->andWhere('v.createdAt <= :created_to')->setParameter('created_to', $to);
        }
    }

    /**
     * Coerce a query param to bool, or null when absent/empty/unrecognised.
     */
    private function boolOrNull(mixed $raw): ?bool
    {
        if ($raw === null || $raw === '') {
            return null;
        }
        return filter_var($raw, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
    }

    /**
     * Parse a YYYY-MM-DD (or any strtotime-parsable) date into a
     * DateTimeImmutable, or null when absent/unparsable. When $endOfDay
     * is set, a date-only value is pushed to 23:59:59 so the range is
     * inclusive of the whole `created_to` day.
     */
    private function dateOrNull(mixed $raw, bool $endOfDay = false): ?\DateTimeImmutable
    {
        if ($raw === null || trim((string) $raw) === '') {
            return null;
        }
        try {
            $dt = new \DateTimeImmutable((string) $raw);
        } catch (\Exception) {
            return null;
        }
        // A date-only input parses to midnight; for the upper bound we
        // want the end of that day so the range stays inclusive.
        if ($endOfDay && preg_match('/^\d{4}-\d{2}-\d{2}$/', trim((string) $raw)) === 1) {
            $dt = $dt->setTime(23, 59, 59);
        }
        return $dt;
    }
}
