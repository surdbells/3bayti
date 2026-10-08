<?php

declare(strict_types=1);

namespace Bayti\Api\Tests\Domain\Catalog;

use Bayti\Api\Doctrine\AdvisoryLock;
use Bayti\Api\Domain\Catalog\CollectionProduct;
use Bayti\Api\Domain\Catalog\CollectionProductRepository;
use Bayti\Api\Domain\Catalog\ProductCollection;
use Bayti\Api\Domain\Catalog\ProductCollectionRepository;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Result;
use Doctrine\ORM\EntityManager;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Mapping\ClassMetadata;
use Doctrine\ORM\Query;
use Doctrine\ORM\QueryBuilder;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Pins the collection-curation repository queries (CI has no PostgreSQL, see
 * ProductRepositoryActiveVendorGatingTest for the pattern):
 *
 *   1. a mocked EntityManager hands back a REAL QueryBuilder and captures
 *      every DQL string, so we can assert each batch method issues exactly
 *      ONE query (no N+1) with the contracted ordering / filtering / grouping;
 *   2. every captured DQL is then COMPILED to SQL by a real EntityManager
 *      (the app's own Doctrine configuration + the pdo_pgsql platform; it
 *      never connects), so a DQL construct the parser rejects or a wrong
 *      column/table mapping fails here, and the Postgres ORDER BY / GROUP BY
 *      text is pinned;
 *   3. the advisory-lock methods are checked against a mocked Connection.
 */
#[CoversClass(CollectionProductRepository::class)]
#[CoversClass(ProductCollectionRepository::class)]
#[CoversClass(AdvisoryLock::class)]
final class CollectionCurationRepositoryDqlTest extends TestCase
{
    /** @var list<string> */
    private array $captured = [];
    /** @var array<string, mixed> */
    private array $params = [];
    /** @var array<string, mixed> */
    private array $hints = [];
    /** @var list<array{sql: string, params: list<mixed>}> */
    private array $statements = [];
    private bool $transactionActive = true;

    private static ?EntityManager $sqlEm = null;

    private const NULLS_LAST_ORDER =
        '/ORDER BY CASE WHEN c\.displayOrder IS NULL THEN 1 ELSE 0 END ASC, c\.displayOrder ASC, c\.id DESC$/';

    /** The same NULLS LAST ordering, as compiled Postgres SQL (alias-agnostic). */
    private const NULLS_LAST_SQL =
        '/ORDER BY CASE WHEN (\w+)\.display_order IS NULL THEN 1 ELSE 0 END ASC, \1\.display_order ASC, \1\.id DESC$/';

    #[Test]
    public function membershipsForProductsIsOneQueryOrderedByDisplayOrderNullsLastThenIdDesc(): void
    {
        $this->joinRepo()->membershipsForProducts([7, 3, 4]);

        self::assertCount(1, $this->captured, 'one query for the whole batch');
        $dql = $this->captured[0];
        self::assertMatchesRegularExpression('/INNER JOIN cp\.collection c\b/', $dql);
        self::assertStringContainsString('cp.product IN (:pids)', $dql);
        self::assertMatchesRegularExpression(self::NULLS_LAST_ORDER, $dql);
        self::assertSame([7, 3, 4], $this->params['pids']);
        foreach (['IDENTITY(cp.product) AS pid', 'c.id AS cid', 'c.name AS cname', 'c.slug AS cslug', 'c.isActive AS cactive', 'cp.sortOrder AS cpsort'] as $select) {
            self::assertStringContainsString($select, $dql);
        }

        $sql = $this->compile($dql);
        self::assertMatchesRegularExpression('/FROM collection_products (\w+) INNER JOIN product_collections (\w+) ON \1\.collection_id = \2\.id WHERE \1\.product_id IN \(\?\)/', $sql);
        self::assertMatchesRegularExpression(self::NULLS_LAST_SQL, $sql);
    }

    #[Test]
    public function membershipsForNoProductsRunsNoQuery(): void
    {
        self::assertSame([], $this->joinRepo()->membershipsForProducts([]));
        self::assertSame([], $this->captured);
    }

    #[Test]
    public function countsByCollectionIsOneGroupedQuery(): void
    {
        $this->joinRepo()->countsByCollection([1, 2, 3]);

        self::assertCount(1, $this->captured);
        self::assertStringContainsString('COUNT(cp.id) AS agg', $this->captured[0]);
        self::assertStringContainsString('cp.collection IN (:cids)', $this->captured[0]);
        self::assertStringEndsWith('GROUP BY cp.collection', $this->captured[0]);
        self::assertSame([1, 2, 3], $this->params['cids']);

        $sql = $this->compile($this->captured[0]);
        self::assertMatchesRegularExpression('/^SELECT (\w+)\.collection_id AS \w+, COUNT\(\1\.id\) AS \w+ FROM collection_products \1 WHERE \1\.collection_id IN \(\?\) GROUP BY \1\.collection_id$/', $sql);
    }

    #[Test]
    public function maxSortOrdersIsOneGroupedQuery(): void
    {
        $this->joinRepo()->maxSortOrders([4, 9]);

        self::assertCount(1, $this->captured);
        self::assertStringContainsString('MAX(cp.sortOrder) AS agg', $this->captured[0]);
        self::assertStringEndsWith('GROUP BY cp.collection', $this->captured[0]);

        $sql = $this->compile($this->captured[0]);
        self::assertMatchesRegularExpression('/MAX\((\w+)\.sort_order\) AS \w+ FROM collection_products \1 .*GROUP BY \1\.collection_id$/', $sql);
    }

    #[Test]
    public function aggregatesForNoCollectionsRunNoQuery(): void
    {
        self::assertSame([], $this->joinRepo()->countsByCollection([]));
        self::assertSame([], $this->joinRepo()->maxSortOrders([]));
        self::assertSame([], $this->captured);
    }

    #[Test]
    public function findForProductFetchJoinsTheCollection(): void
    {
        $this->joinRepo()->findForProduct(7);

        self::assertCount(1, $this->captured);
        self::assertMatchesRegularExpression('/^SELECT cp, c FROM .*CollectionProduct cp INNER JOIN cp\.collection c WHERE cp\.product = :pid$/', $this->captured[0]);
        self::assertSame(7, $this->params['pid']);

        $sql = $this->compile($this->captured[0]);
        self::assertMatchesRegularExpression('/FROM collection_products (\w+) INNER JOIN product_collections (\w+) ON \1\.collection_id = \2\.id WHERE \1\.product_id = \?$/', $sql);
        self::assertMatchesRegularExpression('/\b\w+\.display_order AS /', $sql, 'collection columns selected (fetch-join)');
    }

    #[Test]
    public function findForCollectionReadsOneCollectionsRowsInCurationOrder(): void
    {
        $this->joinRepo()->findForCollection(5);

        self::assertCount(1, $this->captured);
        self::assertSame(5, $this->params['cid']);

        $sql = $this->compile($this->captured[0]);
        self::assertMatchesRegularExpression('/FROM collection_products (\w+) WHERE \1\.collection_id = \? ORDER BY \1\.sort_order ASC, \1\.id ASC$/', $sql);
    }

    #[Test]
    public function collectionIdsForProductIsAScalarQuery(): void
    {
        $this->joinRepo()->collectionIdsForProduct(7);

        self::assertCount(1, $this->captured);
        self::assertSame(7, $this->params['pid']);

        $sql = $this->compile($this->captured[0]);
        self::assertMatchesRegularExpression('/^SELECT (\w+)\.collection_id AS \w+ FROM collection_products \1 WHERE \1\.product_id = \?$/', $sql);
    }

    #[Test]
    public function findAllOrderedUsesTheAdminListOrderWithoutFilters(): void
    {
        $this->collectionRepo()->findAllOrdered();

        self::assertCount(1, $this->captured);
        self::assertStringNotContainsString('WHERE', $this->captured[0], 'inactive collections are reordered too');
        self::assertMatchesRegularExpression(self::NULLS_LAST_ORDER, $this->captured[0]);
        self::assertArrayNotHasKey(Query::HINT_REFRESH, $this->hints);

        $sql = $this->compile($this->captured[0]);
        self::assertStringContainsString('FROM product_collections', $sql);
        self::assertStringNotContainsString('WHERE', $sql);
        self::assertMatchesRegularExpression(self::NULLS_LAST_SQL, $sql);
    }

    #[Test]
    public function findAllOrderedWithRefreshOverwritesManagedEntities(): void
    {
        $this->collectionRepo()->findAllOrdered(refresh: true);

        self::assertTrue($this->hints[Query::HINT_REFRESH] ?? false);
    }

    #[Test]
    public function orderedIdsIsAScalarQueryInTheSameOrder(): void
    {
        $this->collectionRepo()->orderedIds();

        self::assertCount(1, $this->captured);
        self::assertMatchesRegularExpression('/^SELECT c\.id AS id FROM /', $this->captured[0]);
        self::assertMatchesRegularExpression(self::NULLS_LAST_ORDER, $this->captured[0]);

        $sql = $this->compile($this->captured[0]);
        self::assertMatchesRegularExpression('/^SELECT (\w+)\.id AS \w+ FROM product_collections \1 ORDER BY/', $sql);
        self::assertMatchesRegularExpression(self::NULLS_LAST_SQL, $sql);
    }

    // ── advisory locks ──────────────────────────────────────────────

    #[Test]
    public function lockCollectionsTakesOneLockPerDistinctCollectionInAscendingOrder(): void
    {
        $this->joinRepo()->lockCollections([9, 4, 9, 6]);

        self::assertSame(
            [AdvisoryLock::SQL, AdvisoryLock::SQL, AdvisoryLock::SQL],
            array_column($this->statements, 'sql'),
        );
        self::assertSame('SELECT pg_advisory_xact_lock(CAST(? AS integer), CAST(? AS integer))', AdvisoryLock::SQL);
        $ns = CollectionProductRepository::LOCK_NAMESPACE_COLLECTION;
        self::assertSame([[$ns, 4], [$ns, 6], [$ns, 9]], array_column($this->statements, 'params'));
    }

    #[Test]
    public function lockProductAndLockDisplayOrderUseTheirOwnKeySpaces(): void
    {
        $this->joinRepo()->lockProduct(7);
        $this->collectionRepo()->lockDisplayOrder();

        self::assertSame([
            [CollectionProductRepository::LOCK_NAMESPACE_PRODUCT, 7],
            [ProductCollectionRepository::LOCK_NAMESPACE_ORDER, 0],
        ], array_column($this->statements, 'params'));
        self::assertCount(3, array_unique([
            CollectionProductRepository::LOCK_NAMESPACE_COLLECTION,
            CollectionProductRepository::LOCK_NAMESPACE_PRODUCT,
            ProductCollectionRepository::LOCK_NAMESPACE_ORDER,
        ]));
    }

    #[Test]
    public function lockIdsAreFoldedIntoTheInt4Range(): void
    {
        $this->joinRepo()->lockProduct(0x7FFFFFFF + 5);

        self::assertSame([[CollectionProductRepository::LOCK_NAMESPACE_PRODUCT, 4]], array_column($this->statements, 'params'));
    }

    #[Test]
    public function locksOutsideATransactionAreRefused(): void
    {
        $this->transactionActive = false;

        $this->expectException(\LogicException::class);
        try {
            $this->joinRepo()->lockCollections([5]);
        } finally {
            self::assertSame([], $this->statements, 'no lock statement sent (it would be released immediately)');
        }
    }

    // ── harness ─────────────────────────────────────────────────────

    private function joinRepo(): CollectionProductRepository
    {
        return new CollectionProductRepository($this->capturingEm(), new ClassMetadata(CollectionProduct::class));
    }

    private function collectionRepo(): ProductCollectionRepository
    {
        return new ProductCollectionRepository($this->capturingEm(), new ClassMetadata(ProductCollection::class));
    }

    private function capturingEm(): EntityManagerInterface
    {
        $em = $this->createMock(EntityManagerInterface::class);
        $em->method('createQueryBuilder')->willReturnCallback(
            static fn (): QueryBuilder => new QueryBuilder($em),
        );
        $em->method('createQuery')->willReturnCallback(function (string $dql): Query {
            $this->captured[] = $dql;
            return $this->stubQuery();
        });
        $em->method('getConnection')->willReturn($this->recordingConnection());
        return $em;
    }

    private function recordingConnection(): Connection
    {
        $conn = $this->createMock(Connection::class);
        $conn->method('isTransactionActive')->willReturnCallback(fn (): bool => $this->transactionActive);
        $conn->method('executeQuery')->willReturnCallback(function (string $sql, array $params = []) use ($conn): Result {
            $this->statements[] = ['sql' => $sql, 'params' => array_values($params)];
            return new Result($this->createMock(\Doctrine\DBAL\Driver\Result::class), $conn);
        });
        return $conn;
    }

    /**
     * Compile DQL to SQL with a REAL EntityManager: the app's own Doctrine
     * configuration (attribute mappings, naming strategy, custom functions)
     * on the pdo_pgsql platform. DBAL connects lazily and the server version
     * is given, so nothing ever touches a database.
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

    /** A Query double that records bound parameters and hints, and never touches a DB. */
    private function stubQuery(): Query
    {
        $test = $this;
        return new class ($test) extends Query {
            public function __construct(private readonly CollectionCurationRepositoryDqlTest $test)
            {
                // Parent constructor needs a real EM + UnitOfWork; never executed.
            }

            public function getResult(string|int $hydrationMode = self::HYDRATE_OBJECT): mixed
            {
                return [];
            }

            public function getArrayResult(): array
            {
                return [];
            }

            public function setParameters(\Doctrine\Common\Collections\ArrayCollection|array $parameters): static
            {
                foreach ($parameters as $p) {
                    $this->test->recordParam($p->getName(), $p->getValue());
                }
                return $this;
            }

            public function setHint(string $name, mixed $value): static
            {
                $this->test->recordHint($name, $value);
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

    /** @internal called by the Query double */
    public function recordParam(string $name, mixed $value): void
    {
        $this->params[$name] = $value;
    }

    /** @internal called by the Query double */
    public function recordHint(string $name, mixed $value): void
    {
        $this->hints[$name] = $value;
    }
}
