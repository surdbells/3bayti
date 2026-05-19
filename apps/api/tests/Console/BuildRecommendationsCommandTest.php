<?php

declare(strict_types=1);

namespace Bayti\Api\Tests\Console;

use Bayti\Api\Console\BuildRecommendationsCommand;
use Bayti\Api\Domain\Catalog\CategoryAffinityCalculator;
use Bayti\Api\Domain\Catalog\CoPurchaseAffinityCalculator;
use Bayti\Api\Tests\Support\InMemoryLogger;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Application;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * Tests for BuildRecommendationsCommand (M3.2.X.12-E).
 *
 * Strategy: mock both calculators + EM + Connection; drive the
 * command through Symfony's CommandTester. Verifies:
 *   - Merge logic (copurchase first, then category top-up)
 *   - Skip already-recommended products in category top-up
 *   - TOP_N_TARGET cap respected per source product
 *   - Truncate + transactional bulk-insert
 *   - --dry-run does not touch the database
 *   - Failure rolls back the transaction
 *   - Observability log emitted on success
 */
#[CoversClass(BuildRecommendationsCommand::class)]
final class BuildRecommendationsCommandTest extends TestCase
{
    private CoPurchaseAffinityCalculator $copurchaseCalc;
    private CategoryAffinityCalculator $categoryCalc;
    private EntityManagerInterface $em;
    private Connection $connection;
    private InMemoryLogger $logger;

    /** @var list<array{sql: string, params: list<mixed>}> */
    private array $executedStatements = [];

    private bool $beginCalled = false;
    private bool $commitCalled = false;
    private bool $rollbackCalled = false;

    protected function setUp(): void
    {
        $this->copurchaseCalc = $this->createMock(CoPurchaseAffinityCalculator::class);
        $this->categoryCalc = $this->createMock(CategoryAffinityCalculator::class);
        $this->logger = new InMemoryLogger();

        $this->executedStatements = [];
        $this->beginCalled = false;
        $this->commitCalled = false;
        $this->rollbackCalled = false;

        $this->connection = $this->createMock(Connection::class);
        $this->connection->method('beginTransaction')
            ->willReturnCallback(function (): bool {
                $this->beginCalled = true;
                return true;
            });
        $this->connection->method('commit')
            ->willReturnCallback(function (): bool {
                $this->commitCalled = true;
                return true;
            });
        $this->connection->method('rollBack')
            ->willReturnCallback(function (): bool {
                $this->rollbackCalled = true;
                return true;
            });
        $this->connection->method('executeStatement')
            ->willReturnCallback(function (string $sql, array $params = []): int {
                $this->executedStatements[] = ['sql' => $sql, 'params' => $params];
                return 1;
            });

        $this->em = $this->createMock(EntityManagerInterface::class);
        $this->em->method('getConnection')->willReturn($this->connection);
    }

    #[Test]
    public function emptyGraphsDoNothingButSucceed(): void
    {
        $this->copurchaseCalc->method('computeFullGraph')->willReturn([]);
        $this->categoryCalc->method('computeFullGraph')->willReturn([]);

        $tester = $this->makeTester();
        $exit = $tester->execute([]);

        self::assertSame(Command::SUCCESS, $exit);
        // Transaction still opened/committed for the (empty) truncate
        self::assertTrue($this->beginCalled);
        self::assertTrue($this->commitCalled);
        self::assertFalse($this->rollbackCalled);

        // Only the DELETE statement should run (no inserts)
        $deletes = array_filter($this->executedStatements,
            fn ($s) => str_contains($s['sql'], 'DELETE FROM product_recommendations'));
        self::assertCount(1, $deletes);

        $inserts = array_filter($this->executedStatements,
            fn ($s) => str_contains($s['sql'], 'INSERT INTO product_recommendations'));
        self::assertCount(0, $inserts);
    }

    #[Test]
    public function dryRunDoesNotTouchDatabase(): void
    {
        $this->copurchaseCalc->method('computeFullGraph')->willReturn([
            100 => [
                ['recommended_product_id' => 200, 'score' => '5.0000'],
            ],
        ]);
        $this->categoryCalc->method('computeFullGraph')->willReturn([]);

        $tester = $this->makeTester();
        $exit = $tester->execute(['--dry-run' => true]);

        self::assertSame(Command::SUCCESS, $exit);
        self::assertFalse($this->beginCalled);
        self::assertFalse($this->commitCalled);
        self::assertSame([], $this->executedStatements);

        $output = $tester->getDisplay();
        self::assertStringContainsString('DRY RUN', $output);
    }

    #[Test]
    public function copurchaseRowsRankedBeforeCategoryRows(): void
    {
        // Product 100 has 2 copurchase recs + 3 category recs.
        // Expected merge: 2 copurchase rows (rank 1-2) + 3 category
        // rows (rank 3-5).
        $this->copurchaseCalc->method('computeFullGraph')->willReturn([
            100 => [
                ['recommended_product_id' => 200, 'score' => '23.0000'],
                ['recommended_product_id' => 300, 'score' => '15.0000'],
            ],
        ]);
        $this->categoryCalc->method('computeFullGraph')->willReturn([
            100 => [
                ['recommended_product_id' => 400, 'score' => '1.0000'],
                ['recommended_product_id' => 500, 'score' => '1.0000'],
                ['recommended_product_id' => 600, 'score' => '1.0000'],
            ],
        ]);

        $tester = $this->makeTester();
        $exit = $tester->execute([]);

        self::assertSame(Command::SUCCESS, $exit);

        // Find the INSERT and check its params
        $insert = $this->findInsert();
        self::assertNotNull($insert);

        // 5 rows × 6 fields each = 30 params
        self::assertCount(30, $insert['params']);

        // Row 1: product=100, recommended=200, score=23.0000,
        //        source=copurchase, rank=1
        self::assertSame(100, $insert['params'][0]);
        self::assertSame(200, $insert['params'][1]);
        self::assertSame('23.0000', $insert['params'][2]);
        self::assertSame('copurchase', $insert['params'][3]);
        self::assertSame(1, $insert['params'][4]);

        // Row 2: copurchase 300 with rank 2
        self::assertSame(300, $insert['params'][7]);
        self::assertSame('copurchase', $insert['params'][9]);
        self::assertSame(2, $insert['params'][10]);

        // Row 3: category 400 with rank 3
        self::assertSame(400, $insert['params'][13]);
        self::assertSame('category', $insert['params'][15]);
        self::assertSame(3, $insert['params'][16]);

        // Row 4: category 500 with rank 4
        self::assertSame(500, $insert['params'][19]);
        self::assertSame(4, $insert['params'][22]);

        // Row 5: category 600 with rank 5
        self::assertSame(600, $insert['params'][25]);
        self::assertSame(5, $insert['params'][28]);
    }

    #[Test]
    public function categoryTopUpSkipsAlreadyRecommendedProducts(): void
    {
        // Product 100 has 1 copurchase rec (id=200) + 3 category
        // recs that INCLUDE id=200. The merge should skip 200 in
        // category and pick the other 2.
        $this->copurchaseCalc->method('computeFullGraph')->willReturn([
            100 => [
                ['recommended_product_id' => 200, 'score' => '10.0000'],
            ],
        ]);
        $this->categoryCalc->method('computeFullGraph')->willReturn([
            100 => [
                ['recommended_product_id' => 200, 'score' => '1.0000'],  // duplicate
                ['recommended_product_id' => 400, 'score' => '1.0000'],
                ['recommended_product_id' => 500, 'score' => '1.0000'],
            ],
        ]);

        $tester = $this->makeTester();
        $tester->execute([]);

        $insert = $this->findInsert();
        self::assertNotNull($insert);

        // Expect 3 rows (1 copurchase + 2 unique category), not 4
        self::assertCount(18, $insert['params']);  // 3 rows × 6 params

        // The recommended_product_ids in order
        $recommendedIds = [
            $insert['params'][1],
            $insert['params'][7],
            $insert['params'][13],
        ];
        self::assertSame([200, 400, 500], $recommendedIds);
    }

    #[Test]
    public function topNCapRespectedPerSourceProduct(): void
    {
        // Product 100 has TOP_N_TARGET + 5 = 25 copurchase recs.
        // Cron should cap at TOP_N_TARGET=20.
        $copurchaseRecs = [];
        for ($i = 1; $i <= 25; $i++) {
            $copurchaseRecs[] = [
                'recommended_product_id' => 200 + $i,
                'score' => sprintf('%d.0000', 30 - $i),
            ];
        }
        $this->copurchaseCalc->method('computeFullGraph')->willReturn([100 => $copurchaseRecs]);
        $this->categoryCalc->method('computeFullGraph')->willReturn([]);

        $tester = $this->makeTester();
        $tester->execute([]);

        $insert = $this->findInsert();
        self::assertNotNull($insert);

        // Expect exactly TOP_N_TARGET = 20 rows × 6 params = 120
        self::assertCount(120, $insert['params']);
    }

    #[Test]
    public function failureDuringInsertRollsBackTransaction(): void
    {
        $this->copurchaseCalc->method('computeFullGraph')->willReturn([
            100 => [
                ['recommended_product_id' => 200, 'score' => '5.0000'],
            ],
        ]);
        $this->categoryCalc->method('computeFullGraph')->willReturn([]);

        // Replace the connection to throw on the INSERT
        $brokenConnection = $this->createMock(Connection::class);
        $brokenConnection->method('beginTransaction')
            ->willReturnCallback(function (): bool {
                $this->beginCalled = true;
                return true;
            });
        $brokenConnection->method('rollBack')
            ->willReturnCallback(function (): bool {
                $this->rollbackCalled = true;
                return true;
            });
        $brokenConnection->method('executeStatement')
            ->willReturnCallback(function (string $sql): int {
                if (str_contains($sql, 'INSERT')) {
                    throw new \RuntimeException('DB write failed');
                }
                return 1;  // DELETE succeeds
            });

        $em = $this->createMock(EntityManagerInterface::class);
        $em->method('getConnection')->willReturn($brokenConnection);

        $cmd = new BuildRecommendationsCommand(
            $em,
            $this->copurchaseCalc,
            $this->categoryCalc,
            $this->logger,
        );
        $app = new Application();
        $app->add($cmd);
        $tester = new CommandTester($app->find('recommendations:build'));

        $this->expectException(\RuntimeException::class);
        $tester->execute([]);

        // Rollback was called even though execute threw
        self::assertTrue($this->rollbackCalled);
    }

    #[Test]
    public function observabilityLogEmittedOnSuccess(): void
    {
        $this->copurchaseCalc->method('computeFullGraph')->willReturn([
            100 => [['recommended_product_id' => 200, 'score' => '5.0000']],
        ]);
        $this->categoryCalc->method('computeFullGraph')->willReturn([]);

        $tester = $this->makeTester();
        $tester->execute([]);

        $records = $this->logger->findByMessage('recommendations.build.completed');
        self::assertCount(1, $records);
        self::assertSame('info', $records[0]['level']);
        self::assertSame(1, $records[0]['context']['source_products']);
        self::assertSame(1, $records[0]['context']['total_rows']);
        self::assertFalse($records[0]['context']['dry_run']);
        self::assertArrayHasKey('duration_ms', $records[0]['context']);
    }

    // =================================================================
    // Helpers
    // =================================================================

    private function makeTester(): CommandTester
    {
        $cmd = new BuildRecommendationsCommand(
            $this->em,
            $this->copurchaseCalc,
            $this->categoryCalc,
            $this->logger,
        );
        $app = new Application();
        $app->add($cmd);
        return new CommandTester($app->find('recommendations:build'));
    }

    /**
     * @return array{sql: string, params: list<mixed>}|null
     */
    private function findInsert(): ?array
    {
        foreach ($this->executedStatements as $stmt) {
            if (str_contains($stmt['sql'], 'INSERT INTO product_recommendations')) {
                return $stmt;
            }
        }
        return null;
    }
}
