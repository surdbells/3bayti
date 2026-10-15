<?php

declare(strict_types=1);

namespace Bayti\Api\Tests\Domain\Catalog;

use Bayti\Api\Domain\Catalog\Vendor;
use Bayti\Api\Domain\Catalog\VendorRepository;
use Doctrine\DBAL\DriverManager;
use Doctrine\ORM\EntityManager;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Mapping\ClassMetadata;
use Doctrine\ORM\Query;
use Doctrine\ORM\QueryBuilder;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Pins the store-DIRECTORY query (GET /v3/vendors) that ranks vendors by
 * follower count: VendorRepository::findActiveWithStatsPaginated.
 *
 * CI has no PostgreSQL (see CollectionCurationRepositoryDqlTest for the
 * pattern), so:
 *   1. a mocked EntityManager hands back a REAL QueryBuilder and captures
 *      each DQL string, bound parameter and limit/offset;
 *   2. every captured DQL is COMPILED to SQL by a real EntityManager (the
 *      app's own Doctrine configuration on the pdo_pgsql platform; it never
 *      connects), so a construct the DQL parser rejects, or a wrong
 *      table/column mapping, fails here and the Postgres ORDER BY is pinned;
 *   3. the stub query returns canned rows so the row normalization
 *      (followersCount / rating / ratingCount casts) is checked.
 *
 * The key invariants:
 *   - default order = followers DESC, name ASC, id ASC (total, so offset
 *     paging is stable across ties); sort=name = name ASC, id ASC;
 *   - the follower count is a correlated SUBQUERY counting only live
 *     users' follows, never a second outer join (which would multiply the
 *     review rows and inflate rating_count / skew the rating average);
 *   - the meta.total COUNT query is unchanged.
 */
#[CoversClass(VendorRepository::class)]
final class VendorDirectoryRepositoryDqlTest extends TestCase
{
    /** @var list<string> */
    private array $captured = [];
    /** @var list<array<string, mixed>> */
    private array $params = [];
    /** @var list<array{first: int|null, max: int|null}> */
    private array $windows = [];
    /** @var list<mixed> rows the next getResult() calls return, in order */
    private array $resultQueue = [];
    private mixed $scalarResult = 0;

    private static ?EntityManager $sqlEm = null;

    /** The exact follower-count subselect (live users only). */
    private const FOLLOWERS_SUBSELECT =
        '(SELECT COUNT(vf.id) FROM Bayti\Api\Domain\Following\VendorFollow vf INNER JOIN vf.user vfu'
        . ' WHERE vf.vendor = v AND vfu.deletedAt IS NULL AND vfu.isActive = true) AS followersCount';

    #[Test]
    public function defaultOrderRanksByLiveFollowerCountThenNameThenId(): void
    {
        $this->repo()->findActiveWithStatsPaginated(24, 0);

        self::assertCount(2, $this->captured, 'one page query + one COUNT query');
        $dql = $this->captured[0];

        self::assertStringContainsString(self::FOLLOWERS_SUBSELECT, $dql);
        self::assertStringEndsWith('GROUP BY v.id ORDER BY followersCount DESC, v.name ASC, v.id ASC', $dql);

        $sql = $this->compile($dql);
        // The subselect is correlated on the outer vendor and joins users to
        // drop soft-deleted / deactivated followers.
        $matched = preg_match(
            '/\(SELECT COUNT\((\w+)\.id\) AS \w+ FROM vendor_follow \1 INNER JOIN users (\w+) ON \1\.user_id = \2\.id'
            . ' WHERE \1\.vendor_id = (\w+)\.id AND \2\.deleted_at IS NULL AND \2\.is_active = true\) AS (sclr_\d+)/',
            $sql,
            $m,
        );
        self::assertSame(1, $matched, 'correlated live-follower subselect: ' . $sql);
        self::assertMatchesRegularExpression('/FROM vendors ' . $m[3] . ' /', $sql, 'correlated on the OUTER vendor');
        // Postgres ORDER BY: the follower column first, then name, then the
        // unique id, a total order.
        self::assertMatchesRegularExpression(
            '/GROUP BY ' . $m[3] . '\.id ORDER BY ' . $m[4] . ' DESC, ' . $m[3] . '\.name ASC, ' . $m[3] . '\.id ASC$/',
            $sql,
        );
    }

    #[Test]
    public function unknownSortFallsBackToTheFollowersOrder(): void
    {
        $this->repo()->findActiveWithStatsPaginated(24, 0, null, 'popularity');

        self::assertStringEndsWith('ORDER BY followersCount DESC, v.name ASC, v.id ASC', $this->captured[0]);
    }

    #[Test]
    public function explicitFollowersSortMatchesTheDefault(): void
    {
        $this->repo()->findActiveWithStatsPaginated(24, 0, null, VendorRepository::DIRECTORY_SORT_FOLLOWERS);
        $this->repo()->findActiveWithStatsPaginated(24, 0);

        self::assertSame($this->captured[0], $this->captured[2]);
    }

    #[Test]
    public function nameSortIsAlphabeticalWithAnIdTieBreakAndStillCarriesTheFollowerCount(): void
    {
        $this->repo()->findActiveWithStatsPaginated(48, 0, null, VendorRepository::DIRECTORY_SORT_NAME);

        $dql = $this->captured[0];
        self::assertStringEndsWith('GROUP BY v.id ORDER BY v.name ASC, v.id ASC', $dql);
        self::assertStringNotContainsString('ORDER BY followersCount', $dql);
        self::assertStringContainsString(self::FOLLOWERS_SUBSELECT, $dql, 'followers_count is emitted in every order');

        $sql = $this->compile($dql);
        self::assertMatchesRegularExpression('/GROUP BY (\w+)\.id ORDER BY \1\.name ASC, \1\.id ASC$/', $sql);
    }

    #[Test]
    public function followerCountNeverJoinsTheOuterQuerySoRatingAggregatesAreNotInflated(): void
    {
        $this->repo()->findActiveWithStatsPaginated(24, 0, 'beit');

        $dql = $this->captured[0];
        // Strip the follower subselect: what remains is the outer query, which
        // must join ONLY the approved reviews (one LEFT JOIN, no other join).
        // A vendor_follow join here would repeat each review row once per
        // follower, so COUNT(pr.id) and AVG(pr.star) would be computed over a
        // cross product.
        $outer = str_replace(self::FOLLOWERS_SUBSELECT, '', $dql);
        self::assertSame(1, substr_count($outer, 'JOIN'), 'the review LEFT JOIN is the only outer join');
        self::assertStringContainsString(
            'LEFT JOIN Bayti\Api\Domain\Catalog\ProductReview pr WITH pr.vendor = v AND pr.status = :reviewApproved',
            $outer,
        );
        self::assertStringNotContainsString('VendorFollow', $outer);
        self::assertStringContainsString('AVG(pr.star) AS rating, COUNT(pr.id) AS ratingCount', $outer);
        self::assertSame('approved', $this->params[0]['reviewApproved']);

        $sql = $this->compile($dql);
        $outerSql = (string) preg_replace('/\(SELECT COUNT\(\w+\.id\) AS sclr_\d+ FROM vendor_follow .*?\) AS sclr_\d+/', '', $sql, -1, $stripped);
        self::assertSame(1, $stripped, 'exactly one follower subselect');
        self::assertStringNotContainsString('vendor_follow', $outerSql);
        self::assertSame(1, substr_count($outerSql, ' JOIN '));
        self::assertMatchesRegularExpression(
            '/FROM vendors (\w+) LEFT JOIN product_reviews (\w+) ON \(\2\.vendor_id = \1\.id AND \2\.status = \?\)/',
            $outerSql,
        );
        self::assertMatchesRegularExpression('/AVG\((\w+)\.star\) AS sclr_\d+, COUNT\(\1\.id\) AS sclr_\d+/', $outerSql);
        self::assertStringNotContainsString('DISTINCT', $sql);
    }

    #[Test]
    public function searchFiltersBothQueriesAndKeepsTheFollowersOrder(): void
    {
        $this->repo()->findActiveWithStatsPaginated(10, 0, '  Beit ');

        [$page, $count] = $this->captured;
        self::assertStringContainsString('LOWER(v.name) LIKE :q', $page);
        self::assertStringEndsWith('ORDER BY followersCount DESC, v.name ASC, v.id ASC', $page);
        self::assertStringContainsString('LOWER(v.name) LIKE :q', $count);
        self::assertSame('%beit%', $this->params[0]['q']);
        self::assertSame('%beit%', $this->params[1]['q']);

        $this->compile($page);
        $this->compile($count);
    }

    #[Test]
    public function countQueryForMetaTotalIsUnchanged(): void
    {
        $this->scalarResult = '7';
        $result = $this->repo()->findActiveWithStatsPaginated(24, 0);

        $count = $this->captured[1];
        self::assertMatchesRegularExpression(
            '/^SELECT COUNT\(v\.id\) FROM Bayti\\\\Api\\\\Domain\\\\Catalog\\\\Vendor v WHERE v\.isActive = true AND v\.status = :approved$/',
            $count,
        );
        self::assertSame(7, $result['total']);

        $sql = $this->compile($count);
        self::assertStringNotContainsString('vendor_follow', $sql);
        self::assertStringNotContainsString('ORDER BY', $sql);
    }

    #[Test]
    public function limitAndOffsetReachThePageQueryForStableOffsetPaging(): void
    {
        $this->repo()->findActiveWithStatsPaginated(24, 48);

        self::assertSame(['first' => 48, 'max' => 24], $this->windows[0]);
        // The order ends on the unique v.id, so (for a given snapshot) page 3
        // at offset 48 can neither repeat nor skip a store that ties on
        // follower count + name.
        self::assertStringEndsWith(', v.id ASC', $this->captured[0]);
    }

    #[Test]
    public function rowsAreNormalizedWithAnIntegerFollowerCount(): void
    {
        $popular = new Vendor('popular', 'Popular', 'popular@example.test');
        $quiet = new Vendor('quiet', 'Quiet', 'quiet@example.test');
        // Postgres returns COUNT / AVG as strings through PDO.
        $this->resultQueue[] = [
            [0 => $popular, 'rating' => '4.5000', 'ratingCount' => '2', 'followersCount' => '12'],
            [0 => $quiet, 'rating' => null, 'ratingCount' => 0, 'followersCount' => 0],
        ];
        $this->scalarResult = 2;

        $result = $this->repo()->findActiveWithStatsPaginated(24, 0);

        self::assertSame(2, $result['total']);
        self::assertSame(
            ['vendor' => $popular, 'rating' => 4.5, 'ratingCount' => 2, 'followersCount' => 12],
            $result['items'][0],
        );
        self::assertSame(
            ['vendor' => $quiet, 'rating' => null, 'ratingCount' => 0, 'followersCount' => 0],
            $result['items'][1],
        );
    }

    // ── harness ─────────────────────────────────────────────────────

    private function repo(): VendorRepository
    {
        return new VendorRepository($this->capturingEm(), new ClassMetadata(Vendor::class));
    }

    private function capturingEm(): EntityManagerInterface
    {
        $em = $this->createMock(EntityManagerInterface::class);
        $em->method('createQueryBuilder')->willReturnCallback(
            static fn (): QueryBuilder => new QueryBuilder($em),
        );
        $em->method('createQuery')->willReturnCallback(function (string $dql): Query {
            $this->captured[] = $dql;
            $this->params[] = [];
            $this->windows[] = ['first' => null, 'max' => null];
            return $this->stubQuery(count($this->captured) - 1);
        });
        return $em;
    }

    /**
     * Compile DQL to SQL with a REAL EntityManager: the app's own Doctrine
     * configuration on the pdo_pgsql platform. DBAL connects lazily and the
     * server version is given, so nothing ever touches a database.
     */
    private function compile(string $dql): string
    {
        if (self::$sqlEm === null) {
            $root = dirname(__DIR__, 3);
            /** @var array{config_factory: callable(string, string): \Doctrine\ORM\Configuration} $doctrine */
            $doctrine = require $root . '/config/doctrine.php';
            $config = ($doctrine['config_factory'])('test', $root);
            $conn = DriverManager::getConnection([
                'driver'        => 'pdo_pgsql',
                'serverVersion' => '16.0',
                'host'          => '127.0.0.1',
                'dbname'        => 'never_connected',
                'user'          => 'never_connected',
            ], $config);
            self::$sqlEm = new EntityManager($conn, $config);
        }
        $sql = self::$sqlEm->createQuery($dql)->getSQL();
        self::assertIsString($sql);
        self::assertFalse(self::$sqlEm->getConnection()->isConnected(), 'SQL generation must not connect');
        return $sql;
    }

    /** A Query double that records parameters + limit/offset and never touches a DB. */
    private function stubQuery(int $index): Query
    {
        $test = $this;
        return new class ($test, $index) extends Query {
            public function __construct(
                private readonly VendorDirectoryRepositoryDqlTest $test,
                private readonly int $index,
            ) {
                // Parent constructor needs a real EM + UnitOfWork; never executed.
            }

            public function getResult(string|int $hydrationMode = self::HYDRATE_OBJECT): mixed
            {
                return $this->test->nextResult();
            }

            public function getSingleScalarResult(): mixed
            {
                return $this->test->scalar();
            }

            public function setParameters(\Doctrine\Common\Collections\ArrayCollection|array $parameters): static
            {
                foreach ($parameters as $p) {
                    $this->test->recordParam($this->index, $p->getName(), $p->getValue());
                }
                return $this;
            }

            public function setFirstResult(int|null $firstResult): self
            {
                $this->test->recordWindow($this->index, 'first', $firstResult);
                return $this;
            }

            public function setMaxResults(int|null $maxResults): self
            {
                $this->test->recordWindow($this->index, 'max', $maxResults);
                return $this;
            }
        };
    }

    /** @internal called by the Query double */
    public function recordParam(int $index, string $name, mixed $value): void
    {
        $this->params[$index][$name] = $value;
    }

    /** @internal called by the Query double */
    public function recordWindow(int $index, string $key, ?int $value): void
    {
        $this->windows[$index][$key] = $value;
    }

    /** @internal called by the Query double */
    public function nextResult(): mixed
    {
        return $this->resultQueue === [] ? [] : array_shift($this->resultQueue);
    }

    /** @internal called by the Query double */
    public function scalar(): mixed
    {
        return $this->scalarResult;
    }
}
