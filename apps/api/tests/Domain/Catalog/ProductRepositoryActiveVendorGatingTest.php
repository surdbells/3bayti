<?php

declare(strict_types=1);

namespace Bayti\Api\Tests\Domain\Catalog;

use Bayti\Api\Domain\Catalog\Product;
use Bayti\Api\Domain\Catalog\ProductRepository;
use Bayti\Api\Domain\Catalog\Vendor;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Mapping\ClassMetadata;
use Doctrine\ORM\Query;
use Doctrine\ORM\QueryBuilder;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Storefront catalog gating (#1): findActivePaginated must only surface
 * products whose vendor is BOTH is_active = TRUE AND status = APPROVED.
 *
 * CI has no PostgreSQL (the JSONB_EXISTS_ANY / TSMATCH DQL functions are
 * Postgres-only, see HttpTestCase), so we can't execute the query against
 * a real database. Instead we drive findActivePaginated through a mocked
 * EntityManager that hands back a REAL Doctrine QueryBuilder, capture the
 * DQL the repository produces, and assert the vendor-gating join + WHERE
 * clauses are present. This proves a product whose vendor is
 * pending/suspended (or deactivated) is excluded, because the generated
 * SQL inner-joins the vendor and filters on v.isActive = TRUE AND
 * v.status = 'approved'.
 */
#[CoversClass(ProductRepository::class)]
final class ProductRepositoryActiveVendorGatingTest extends TestCase
{
    #[Test]
    public function findActivePaginatedGatesOnActiveAndApprovedVendor(): void
    {
        $dql = $this->captureFindActivePaginatedDql([]);

        // Inner join onto the vendor relation, products with no/inactive
        // vendor are dropped entirely.
        self::assertMatchesRegularExpression(
            '/INNER JOIN\s+p\.vendor\s+v\b/i',
            $dql,
            'findActivePaginated must INNER JOIN p.vendor so unapproved vendors are excluded.',
        );

        // Vendor must be active.
        self::assertMatchesRegularExpression(
            '/v\.isActive\s*=\s*TRUE/i',
            $dql,
            'findActivePaginated must require v.isActive = TRUE.',
        );

        // Vendor must be approved (status parameter bound to STATUS_APPROVED).
        self::assertMatchesRegularExpression(
            '/v\.status\s*=\s*:vendorApproved/i',
            $dql,
            'findActivePaginated must require v.status = :vendorApproved.',
        );
    }

    #[Test]
    public function bestSellerSortKeepsVendorGating(): void
    {
        // The best_seller branch adds groupBy(p.id) + LEFT JOIN OrderItem;
        // the vendor inner join must still be present and compatible.
        $dql = $this->captureFindActivePaginatedDql(['sort' => 'best_seller']);

        self::assertMatchesRegularExpression('/INNER JOIN\s+p\.vendor\s+v\b/i', $dql);
        self::assertMatchesRegularExpression('/v\.status\s*=\s*:vendorApproved/i', $dql);
        self::assertStringContainsStringIgnoringCase('GROUP BY', $dql);
    }

    /**
     * SEARCH #4: a non-empty searchQuery must widen matching across
     * product name + description + store/vendor name + category name,
     * using case-insensitive substring (LIKE) so short/prefix queries
     * match. We assert the four OR'd LOWER(...) LIKE clauses and the
     * category LEFT JOIN are present, and that the bound term is the
     * lower-cased, wildcard-wrapped '%term%'.
     */
    #[Test]
    public function searchMatchesNameDescriptionVendorAndCategory(): void
    {
        $dql = $this->captureFindActivePaginatedDql(['searchQuery' => 'Ab']);

        // Category is left-joined (nullable) so its name is searchable
        // without dropping uncategorised products.
        self::assertMatchesRegularExpression('/LEFT JOIN\s+p\.category\s+sc\b/i', $dql);

        // All four searched columns appear in a LOWER(...) LIKE form.
        self::assertMatchesRegularExpression('/LOWER\(p\.name\)\s+LIKE\s+:searchTerm/i', $dql);
        self::assertMatchesRegularExpression('/LOWER\(p\.description\)\s+LIKE\s+:searchTerm/i', $dql);
        self::assertMatchesRegularExpression('/LOWER\(v\.name\)\s+LIKE\s+:searchTerm/i', $dql);
        self::assertMatchesRegularExpression('/LOWER\(sc\.name\)\s+LIKE\s+:searchTerm/i', $dql);
    }

    /**
     * The bound :searchTerm must be lower-cased and wrapped in % wildcards
     * so a 2-char query like "Ab" becomes '%ab%' (substring, case-folded).
     */
    #[Test]
    public function searchTermIsLowerCasedSubstring(): void
    {
        $em = $this->createMock(EntityManagerInterface::class);

        /** @var list<QueryBuilder> $builders */
        $builders = [];
        $em->method('createQueryBuilder')->willReturnCallback(
            function () use ($em, &$builders): QueryBuilder {
                $qb = new QueryBuilder($em);
                $builders[] = $qb;
                return $qb;
            },
        );
        $em->method('createQuery')->willReturnCallback(
            fn (string $dql): Query => $this->stubQuery(),
        );

        $repo = new ProductRepository($em, new ClassMetadata(Product::class));
        $repo->findActivePaginated(['searchQuery' => 'Ab']);

        $boundValue = null;
        foreach ($builders as $qb) {
            $param = $qb->getParameter('searchTerm');
            if ($param !== null) {
                $boundValue = $param->getValue();
                break;
            }
        }

        self::assertSame('%ab%', $boundValue);
    }

    /**
     * sort=relevance must no longer reference the removed :searchQuery
     * parameter (which would throw "unbound parameter"); it ranks by a
     * name-match CASE expression instead. Proves the relevance branch
     * produces valid DQL alongside the new substring search.
     */
    #[Test]
    public function relevanceSortWithSearchProducesValidDql(): void
    {
        $dql = $this->captureFindActivePaginatedDql([
            'searchQuery' => 'ab',
            'sort' => 'relevance',
        ]);

        self::assertStringNotContainsString(':searchQuery', $dql);
        self::assertMatchesRegularExpression('/ORDER BY\s+relevanceRank/i', $dql);
    }

    /**
     * The :vendorApproved placeholder must be bound to STATUS_APPROVED,
     * so suspended/pending vendors (any other status value) are excluded.
     */
    #[Test]
    public function vendorApprovedParameterIsStatusApproved(): void
    {
        $em = $this->createMock(EntityManagerInterface::class);

        /** @var list<QueryBuilder> $builders */
        $builders = [];
        $em->method('createQueryBuilder')->willReturnCallback(
            function () use ($em, &$builders): QueryBuilder {
                $qb = new QueryBuilder($em);
                $builders[] = $qb;
                return $qb;
            },
        );
        $em->method('createQuery')->willReturnCallback(
            fn (string $dql): Query => $this->stubQuery(),
        );

        $repo = new ProductRepository($em, new ClassMetadata(Product::class));
        $repo->findActivePaginated([]);

        $boundValue = null;
        foreach ($builders as $qb) {
            $param = $qb->getParameter('vendorApproved');
            if ($param !== null) {
                $boundValue = $param->getValue();
                break;
            }
        }

        self::assertSame(Vendor::STATUS_APPROVED, $boundValue);
    }

    /**
     * Drive findActivePaginated and return the DQL string the repository
     * generated for the main (item) query. The count query runs first
     * (COUNT(p.id)); the second createQuery call is the item query.
     *
     * @param array<string, mixed> $filters
     */
    private function captureFindActivePaginatedDql(array $filters): string
    {
        $em = $this->createMock(EntityManagerInterface::class);
        $em->method('createQueryBuilder')->willReturnCallback(
            static fn (): QueryBuilder => new QueryBuilder($em),
        );

        $captured = [];
        $em->method('createQuery')->willReturnCallback(
            function (string $dql) use (&$captured): Query {
                $captured[] = $dql;
                return $this->stubQuery();
            },
        );

        $repo = new ProductRepository($em, new ClassMetadata(Product::class));
        $repo->findActivePaginated($filters);

        // Return the item query (last createQuery call); it carries the
        // full join/where/orderBy shape. The count query has the same
        // joins + where but a COUNT select, so either works for the
        // gating assertions, we use the last for completeness.
        self::assertNotEmpty($captured, 'findActivePaginated produced no DQL.');
        return (string) end($captured);
    }

    /**
     * A Query test double that no-ops the execution surface so
     * findActivePaginated completes without a database.
     *
     * Anonymous subclass (rather than a PHPUnit mock) because Query's
     * fluent setters (setParameters/setFirstResult/setMaxResults) are
     * called by QueryBuilder::getQuery() and we want their real, prop-
     * setting behaviour; only the DB-touching execution methods are
     * stubbed out.
     */
    private function stubQuery(): Query
    {
        return new class extends Query {
            public function __construct()
            {
                // Skip the parent constructor: it requires an
                // EntityManager + UnitOfWork. We never execute against a
                // DB, so the uninitialised parent state is fine.
            }

            public function getResult(string|int $hydrationMode = self::HYDRATE_OBJECT): mixed
            {
                return [];
            }

            public function getSingleScalarResult(): mixed
            {
                return 0;
            }

            public function setParameters(\Doctrine\Common\Collections\ArrayCollection|array $parameters): static
            {
                return $this;
            }

            public function setFirstResult(int|null $firstResult): self
            {
                return $this;
            }

            public function setMaxResults(int|null $maxResults): self
            {
                return $this;
            }
        };
    }
}
