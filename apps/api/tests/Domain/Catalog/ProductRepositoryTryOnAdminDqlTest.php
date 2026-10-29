<?php

declare(strict_types=1);

namespace Bayti\Api\Tests\Domain\Catalog;

use Bayti\Api\Domain\Catalog\Product;
use Bayti\Api\Domain\Catalog\ProductRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\DriverManager;
use Doctrine\ORM\EntityManager;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Mapping\ClassMetadata;
use Doctrine\ORM\Query;
use Doctrine\ORM\Query\Parameter;
use Doctrine\ORM\QueryBuilder;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * DQL shape of the admin virtual try-on queries (GET /v3/admin/try-on/products
 * + /status + the bulk toggle):
 *   - findForTryOnAdmin: soft-deleted hidden, case-insensitive name OR slug
 *     substring (wildcards escaped, OR grouped in parentheses so it cannot
 *     escape the soft-delete AND), optional try_on_enabled filter, ordered
 *     enabled first then newest then id; the COUNT clone carries the same
 *     WHERE but no ORDER BY.
 *   - countTryOnEnabled: flagged + not soft-deleted.
 *   - findByIds: v3 ids via an IN list (integer array parameter).
 *
 * CI has no PostgreSQL, so (like ProductRepositoryActiveVendorGatingTest) the
 * repository is driven through a mocked EntityManager that hands back a REAL
 * QueryBuilder; the DQL + bound parameters of every query are captured. Each
 * query must bind exactly the placeholders its DQL uses (an unbound or stray
 * parameter is what produces HY093 / "Too many parameters" at runtime).
 */
#[CoversClass(ProductRepository::class)]
final class ProductRepositoryTryOnAdminDqlTest extends TestCase
{
    /** @var list<array{dql: string, params: array<string, Parameter>}> */
    private array $queries = [];

    /** @var list<QueryBuilder> */
    private array $builders = [];

    private static ?EntityManager $sqlEm = null;

    protected function setUp(): void
    {
        $this->queries = [];
        $this->builders = [];
    }

    #[Test]
    public function listWithoutFiltersHidesSoftDeletedAndOrdersEnabledFirstThenNewest(): void
    {
        $result = $this->repo()->findForTryOnAdmin([]);

        self::assertSame(['items' => [], 'total' => 0], $result);
        self::assertCount(2, $this->queries, 'one COUNT query + one item query');
        [$count, $items] = $this->queries;

        foreach ([$count, $items] as $query) {
            self::assertMatchesRegularExpression('/INNER JOIN\s+p\.vendor\s+v\b/i', $query['dql']);
            self::assertMatchesRegularExpression('/p\.status\s*!=\s*:softDeleted/', $query['dql']);
            self::assertStringNotContainsString('LIKE', $query['dql'], 'no search clause without q');
            self::assertStringNotContainsString(':enabled', $query['dql'], 'no flag filter when enabled is omitted');
            self::assertSame(Product::STATUS_SOFT_DELETED, $query['params']['softDeleted']->getValue());
            $this->assertPlaceholdersMatchBoundParameters($query);
        }

        self::assertMatchesRegularExpression('/^SELECT\s+COUNT\(p\.id\)/i', $count['dql']);
        self::assertStringNotContainsStringIgnoringCase('ORDER BY', $count['dql'], 'COUNT must not carry the ORDER BY');

        self::assertMatchesRegularExpression(
            '/ORDER BY\s+p\.tryOnEnabled\s+DESC\s*,\s*p\.createdAt\s+DESC\s*,\s*p\.id\s+DESC$/',
            $items['dql'],
        );

        // Compiled for PostgreSQL (never connects).
        $countSql = $this->compile($count['dql']);
        self::assertMatchesRegularExpression('/^SELECT COUNT\((\w+)\.id\) AS \w+ FROM products \1 INNER JOIN vendors (\w+) ON \1\.vendor_id = \2\.id WHERE \1\.status <> \?$/', $countSql);
        $itemsSql = $this->compile($items['dql']);
        self::assertMatchesRegularExpression('/FROM products (\w+) INNER JOIN vendors (\w+) ON \1\.vendor_id = \2\.id WHERE \1\.status <> \? ORDER BY \1\.try_on_enabled DESC, \1\.created_at DESC, \1\.id DESC$/', $itemsSql);
    }

    #[Test]
    public function searchMatchesNameOrSlugInsideParenthesesWithAnEscapedLowerCasedNeedle(): void
    {
        $this->repo()->findForTryOnAdmin(['q' => '  50%_Off\\ ']);

        foreach ($this->queries as $query) {
            // The OR is grouped, so it is AND-ed as a whole with the
            // soft-delete clause (a bare OR would leak soft-deleted rows).
            self::assertMatchesRegularExpression(
                '/p\.status\s*!=\s*:softDeleted\s+AND\s+\(LOWER\(p\.name\)\s+LIKE\s+:qName\s+OR\s+LOWER\(p\.slug\)\s+LIKE\s+:qSlug\)/',
                $query['dql'],
            );
            // Lower-cased, trimmed, LIKE wildcards + backslash escaped.
            self::assertSame('%50\\%\\_off\\\\%', $query['params']['qName']->getValue());
            self::assertSame('%50\\%\\_off\\\\%', $query['params']['qSlug']->getValue());
            // No ESCAPE literal (it broke the COUNT clone's parameter mapping, HY093).
            self::assertStringNotContainsStringIgnoringCase('ESCAPE', $query['dql']);
            $this->assertPlaceholdersMatchBoundParameters($query);
        }
    }

    #[Test]
    public function blankSearchIsIgnored(): void
    {
        $this->repo()->findForTryOnAdmin(['q' => '   ']);

        foreach ($this->queries as $query) {
            self::assertStringNotContainsString('LIKE', $query['dql']);
            $this->assertPlaceholdersMatchBoundParameters($query);
        }
    }

    #[Test]
    public function enabledFilterIsAppliedOnlyForABoolean(): void
    {
        foreach ([true, false] as $enabled) {
            $this->queries = [];
            $this->repo()->findForTryOnAdmin(['enabled' => $enabled]);

            foreach ($this->queries as $query) {
                self::assertMatchesRegularExpression('/p\.tryOnEnabled\s*=\s*:enabled/', $query['dql']);
                self::assertSame($enabled, $query['params']['enabled']->getValue());
                $this->assertPlaceholdersMatchBoundParameters($query);
            }
        }

        $this->queries = [];
        $this->repo()->findForTryOnAdmin(['enabled' => null]);
        foreach ($this->queries as $query) {
            self::assertStringNotContainsString(':enabled', $query['dql']);
        }
    }

    #[Test]
    public function allFiltersTogetherBindEveryPlaceholderExactlyOnce(): void
    {
        $this->repo()->findForTryOnAdmin(['q' => 'abaya', 'enabled' => true, 'limit' => 50, 'offset' => 100]);

        self::assertCount(2, $this->queries);
        foreach ($this->queries as $query) {
            $this->assertPlaceholdersMatchBoundParameters($query);
            self::assertMatchesRegularExpression(
                '/WHERE (\w+)\.status <> \? AND \(LOWER\(\1\.name\) LIKE \? OR LOWER\(\1\.slug\) LIKE \?\) AND \1\.try_on_enabled = \?/',
                $this->compile($query['dql']),
            );
        }
        self::assertSame(50, $this->builders[0]->getMaxResults());
        self::assertSame(100, $this->builders[0]->getFirstResult());
    }

    #[Test]
    public function limitAndOffsetAreClamped(): void
    {
        $cases = [
            'defaults' => [[], 20, 0],
            'over max' => [['limit' => 500], 100, 0],
            'zero limit' => [['limit' => 0], 1, 0],
            'negative offset' => [['offset' => -5], 20, 0],
        ];

        foreach ($cases as $case => [$filters, $limit, $offset]) {
            $this->builders = [];
            $this->repo()->findForTryOnAdmin($filters);

            self::assertSame($limit, $this->builders[0]->getMaxResults(), $case);
            self::assertSame($offset, $this->builders[0]->getFirstResult(), $case);
        }
    }

    #[Test]
    public function countTryOnEnabledCountsFlaggedProductsThatAreNotSoftDeleted(): void
    {
        self::assertSame(0, $this->repo()->countTryOnEnabled());

        self::assertCount(1, $this->queries);
        $query = $this->queries[0];
        self::assertMatchesRegularExpression('/^SELECT\s+COUNT\(p\.id\)/i', $query['dql']);
        self::assertMatchesRegularExpression('/p\.tryOnEnabled\s*=\s*TRUE/', $query['dql']);
        self::assertMatchesRegularExpression('/p\.status\s*!=\s*:softDeleted/', $query['dql']);
        self::assertSame(Product::STATUS_SOFT_DELETED, $query['params']['softDeleted']->getValue());
        $this->assertPlaceholdersMatchBoundParameters($query);
        self::assertMatchesRegularExpression(
            '/^SELECT COUNT\((\w+)\.id\) AS \w+ FROM products \1 WHERE \1\.try_on_enabled = true AND \1\.status <> \?$/',
            $this->compile($query['dql']),
        );
    }

    #[Test]
    public function findByIdsUsesAnIntegerInListAndSkipsTheQueryForNoIds(): void
    {
        self::assertSame([], $this->repo()->findByIds([]));
        self::assertSame([], $this->queries, 'no query for an empty id list');

        $this->repo()->findByIds([101, 102]);

        self::assertCount(1, $this->queries);
        $query = $this->queries[0];
        self::assertMatchesRegularExpression('/INNER JOIN\s+p\.vendor\s+v\b/i', $query['dql']);
        self::assertMatchesRegularExpression('/p\.id\s+IN\s*\(:ids\)/', $query['dql']);
        self::assertSame([101, 102], $query['params']['ids']->getValue());
        self::assertSame(ArrayParameterType::INTEGER, $query['params']['ids']->getType());
        // Soft-deleted products are hidden from the list, so the bulk write
        // must not find (and flip) them either.
        self::assertMatchesRegularExpression('/p\.status\s*!=\s*:softDeleted/', $query['dql']);
        self::assertSame(Product::STATUS_SOFT_DELETED, $query['params']['softDeleted']->getValue());
        $this->assertPlaceholdersMatchBoundParameters($query);
        self::assertMatchesRegularExpression('/WHERE (\w+)\.id IN \(\?\) AND \1\.status <> \?$/', $this->compile($query['dql']));
    }

    /**
     * Compile DQL to PostgreSQL SQL with a real EntityManager (the app's own
     * Doctrine configuration + the pdo_pgsql platform). It never connects, so
     * a DQL construct the parser rejects or a wrong column mapping fails here.
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

    // =================================================================
    // Helpers
    // =================================================================

    private function repo(): ProductRepository
    {
        $em = $this->createMock(EntityManagerInterface::class);
        $em->method('createQueryBuilder')->willReturnCallback(
            function () use ($em): QueryBuilder {
                $qb = new QueryBuilder($em);
                $this->builders[] = $qb;
                return $qb;
            },
        );
        $em->method('createQuery')->willReturnCallback(
            fn (string $dql): Query => $this->recordingQuery($dql),
        );

        return new ProductRepository($em, new ClassMetadata(Product::class));
    }

    /**
     * The set of :named placeholders in the DQL must equal the set of bound
     * parameter names: a placeholder without a value, or a value without a
     * placeholder, fails at execution time on PostgreSQL.
     *
     * @param array{dql: string, params: array<string, Parameter>} $query
     */
    private function assertPlaceholdersMatchBoundParameters(array $query): void
    {
        preg_match_all('/:([A-Za-z_]\w*)/', $query['dql'], $m);
        $placeholders = array_values(array_unique($m[1]));
        sort($placeholders);

        $bound = array_keys($query['params']);
        sort($bound);

        self::assertSame($placeholders, $bound, 'placeholders vs bound parameters in: ' . $query['dql']);
    }

    /**
     * A Query double that records the DQL and the parameters QueryBuilder::
     * getQuery() binds, and no-ops execution (no database in CI).
     */
    private function recordingQuery(string $dql): Query
    {
        $index = count($this->queries);
        $this->queries[] = ['dql' => $dql, 'params' => []];
        $record = function (ArrayCollection|array $parameters) use ($index): void {
            foreach ($parameters as $parameter) {
                if ($parameter instanceof Parameter) {
                    $this->queries[$index]['params'][$parameter->getName()] = $parameter;
                }
            }
        };

        return new class ($record) extends Query {
            /** @param \Closure(ArrayCollection<int, Parameter>|array<mixed>): void $record */
            public function __construct(private readonly \Closure $record)
            {
                // Skip the parent constructor: it requires an EntityManager +
                // UnitOfWork, and nothing here touches a database.
            }

            public function getResult(string|int $hydrationMode = self::HYDRATE_OBJECT): mixed
            {
                return [];
            }

            public function getSingleScalarResult(): mixed
            {
                return 0;
            }

            public function setParameters(ArrayCollection|array $parameters): static
            {
                ($this->record)($parameters);
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
