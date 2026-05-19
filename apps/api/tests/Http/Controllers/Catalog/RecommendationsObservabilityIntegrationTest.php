<?php

declare(strict_types=1);

namespace Bayti\Api\Tests\Http\Controllers\Catalog;

use Bayti\Api\Console\BuildRecommendationsCommand;
use Bayti\Api\Domain\Catalog\CategoryAffinityCalculator;
use Bayti\Api\Domain\Catalog\CoPurchaseAffinityCalculator;
use Bayti\Api\Domain\Catalog\Product;
use Bayti\Api\Domain\Catalog\ProductRecommendation;
use Bayti\Api\Domain\Catalog\ProductRecommendationRepository;
use Bayti\Api\Domain\Catalog\ProductRepository;
use Bayti\Api\Domain\Catalog\RecommendationsService;
use Bayti\Api\Domain\Catalog\Vendor;
use Bayti\Api\Http\Controllers\Catalog\GetProductRecommendationsController;
use Bayti\Api\Http\Serializers\ProductSerializer;
use Bayti\Api\Http\Serializers\RecommendationsSerializer;
use Bayti\Api\Tests\Http\HttpTestCase;
use Bayti\Api\Tests\Support\InMemoryLogger;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Result;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\Test;
use Psr\Http\Message\ResponseInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Console\Application;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * X.12-H integration coverage. End-to-end verification that the
 * full X.12 stack wires up correctly with REAL service instances
 * and observability events propagate end-to-end.
 *
 * SIXTH instance of the 'real-service-with-fake-deps integration
 * test for observability' pattern after X.14-E / X.17-E / X.11-H
 * / X.15-G / X.13-F. Firmly locked.
 *
 * Two coverage paths:
 *
 *   1. CRON SIDE — BuildRecommendationsCommand drives both real
 *      calculators + writes the denormalized table via real EM
 *      + capturing Connection. Verifies recommendations.build.completed
 *      INFO log propagates through with all stats. Verifies the
 *      cron writes the EXACT rows in the order the merge logic
 *      produces.
 *
 *   2. READ SIDE — GetProductRecommendationsController drives real
 *      RecommendationsService + real RecommendationsSerializer.
 *      Mocked Connection returns canned popular-fallback rows.
 *      Verifies the recommendations.product.served DEBUG log
 *      fires with used_fallback=true through the full HTTP stack.
 *
 * Catches the failure mode where:
 *   - Unit tests pass (each layer correct in isolation)
 *   - Wiring / DI changes silently break log propagation
 *   - The end-to-end happy path that ops cares about hasn't been
 *     exercised through real composition
 */
final class RecommendationsObservabilityIntegrationTest extends HttpTestCase
{
    private InMemoryLogger $logger;

    /** @var list<array{sql: string, params: list<mixed>}> */
    private array $executedStatements = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->logger = new InMemoryLogger();
        $this->executedStatements = [];
        $this->bind(LoggerInterface::class, $this->logger);
    }

    // =================================================================
    // CRON SIDE: full BuildRecommendationsCommand stack
    // =================================================================

    #[Test]
    public function cronFullStackPropagatesObservability(): void
    {
        // Mock connections for both calculators (each returns its
        // own canned query result) + a write-side connection that
        // captures the truncate + bulk-insert statements.
        //
        // Important: in the real DI container, both calculators
        // AND the command share the SAME EntityManager instance,
        // so the SAME Connection. That means the call-sequence is:
        //   1. setStatementTimeout for copurchase
        //   2. copurchase computeFullGraph query
        //   3. setStatementTimeout for category
        //   4. category computeFullGraph query
        //   5. beginTransaction
        //   6. DELETE FROM product_recommendations
        //   7. INSERT batch(es)
        //   8. commit
        $queryCallIdx = 0;
        $connection = $this->createMock(Connection::class);
        $connection->method('executeStatement')
            ->willReturnCallback(function (string $sql, array $params = []): int {
                $this->executedStatements[] = ['sql' => $sql, 'params' => $params];
                return 1;
            });

        // Calculator queries: copurchase first, then category.
        $connection->method('executeQuery')->willReturnCallback(
            function () use (&$queryCallIdx): Result {
                $queryCallIdx++;
                $r = $this->createMock(Result::class);
                if ($queryCallIdx === 1) {
                    // copurchase: 1 source product with 2 recs
                    $r->method('fetchAllAssociative')->willReturn([
                        ['source_product_id' => 100, 'target_product_id' => 200, 'pair_count' => 23],
                        ['source_product_id' => 100, 'target_product_id' => 300, 'pair_count' => 15],
                    ]);
                } elseif ($queryCallIdx === 2) {
                    // category: same source product with 1 more rec
                    $r->method('fetchAllAssociative')->willReturn([
                        ['source_product_id' => 100, 'target_product_id' => 400],
                    ]);
                } else {
                    $r->method('fetchAllAssociative')->willReturn([]);
                }
                return $r;
            },
        );

        $em = $this->createMock(EntityManagerInterface::class);
        $em->method('getConnection')->willReturn($connection);

        // REAL calculators + REAL command — all use the same EM
        $copurchaseCalc = new CoPurchaseAffinityCalculator($em, $this->logger);
        $categoryCalc = new CategoryAffinityCalculator($em, $this->logger);
        $cmd = new BuildRecommendationsCommand(
            $em,
            $copurchaseCalc,
            $categoryCalc,
            $this->logger,
        );

        $app = new Application();
        $app->add($cmd);
        $tester = new CommandTester($app->find('recommendations:build'));
        $exitCode = $tester->execute([]);

        self::assertSame(0, $exitCode, $tester->getDisplay());

        // Observability fired through the full stack from BOTH
        // calculators + the command
        $copurchaseLog = $this->logger->findByMessage('copurchase_affinity.computed');
        self::assertCount(1, $copurchaseLog);
        self::assertSame(1, $copurchaseLog[0]['context']['source_products']);
        self::assertSame(2, $copurchaseLog[0]['context']['total_pairs']);
        self::assertSame(365, $copurchaseLog[0]['context']['window_days']);

        $categoryLog = $this->logger->findByMessage('category_affinity.computed');
        self::assertCount(1, $categoryLog);
        self::assertSame(1, $categoryLog[0]['context']['source_products']);

        $buildLog = $this->logger->findByMessage('recommendations.build.completed');
        self::assertCount(1, $buildLog);
        self::assertSame('info', $buildLog[0]['level']);
        self::assertSame(1, $buildLog[0]['context']['source_products']);
        // 2 copurchase + 1 category = 3 total rows
        self::assertSame(3, $buildLog[0]['context']['total_rows']);
        self::assertFalse($buildLog[0]['context']['dry_run']);

        // The DELETE + INSERT pair was emitted
        $delete = array_filter($this->executedStatements,
            fn ($s) => str_contains($s['sql'], 'DELETE FROM product_recommendations'));
        self::assertCount(1, $delete);

        $insert = array_filter($this->executedStatements,
            fn ($s) => str_contains($s['sql'], 'INSERT INTO product_recommendations'));
        self::assertCount(1, $insert);

        $insertStmt = array_values($insert)[0];
        // 3 rows × 6 params each = 18 params
        self::assertCount(18, $insertStmt['params']);

        // Verify the merge produced: copurchase first (200, 300),
        // then category (400)
        self::assertSame(200, $insertStmt['params'][1]);   // row 1: recommended_id
        self::assertSame('copurchase', $insertStmt['params'][3]); // row 1: source
        self::assertSame(300, $insertStmt['params'][7]);   // row 2: recommended_id
        self::assertSame('copurchase', $insertStmt['params'][9]); // row 2: source
        self::assertSame(400, $insertStmt['params'][13]);  // row 3: recommended_id
        self::assertSame('category', $insertStmt['params'][15]); // row 3: source
    }

    // =================================================================
    // READ SIDE: full GetProductRecommendationsController stack
    // =================================================================

    #[Test]
    public function readFullStackUsesFallbackAndEmitsDebugLog(): void
    {
        // Bind a Product so the controller's slug lookup succeeds.
        $vendor = new Vendor('test-vendor', 'Test Vendor', 'tv@example.com');
        $vRef = new \ReflectionProperty(Vendor::class, 'id');
        $vRef->setAccessible(true);
        $vRef->setValue($vendor, 5);

        $source = new Product($vendor, 'lonely-product', 'Lonely Product');
        $srcIdRef = new \ReflectionProperty(Product::class, 'id');
        $srcIdRef->setAccessible(true);
        $srcIdRef->setValue($source, 100);
        $source->setPrice('365.00');

        $popular = new Product($vendor, 'crowd-favourite', 'Crowd Favourite');
        $popIdRef = new \ReflectionProperty(Product::class, 'id');
        $popIdRef->setAccessible(true);
        $popIdRef->setValue($popular, 200);
        $popular->setPrice('99.00');

        // The pre-computed rec repo returns EMPTY → service falls
        // back to popular query.
        $recRepo = $this->createMock(ProductRecommendationRepository::class);
        $recRepo->method('findTopForProduct')->willReturn([]);

        $productRepo = $this->createMock(ProductRepository::class);
        $productRepo->method('findBySlug')->willReturn($source);
        $productRepo->method('findBy')->willReturn([$popular]);

        // Popular fallback Connection
        $connection = $this->createMock(Connection::class);
        $connection->method('executeStatement')->willReturn(0);
        $popularResult = $this->createMock(Result::class);
        $popularResult->method('fetchAllAssociative')->willReturn([
            ['product_id' => 200, 'units_sold' => 50],
        ]);
        $connection->method('executeQuery')->willReturn($popularResult);

        $em = $this->createMock(EntityManagerInterface::class);
        $em->method('getRepository')->willReturnCallback(
            fn (string $class) => match ($class) {
                ProductRecommendation::class => $recRepo,
                Product::class => $productRepo,
                default => throw new \LogicException("Unexpected: {$class}"),
            },
        );
        $em->method('getConnection')->willReturn($connection);
        $this->bind(EntityManagerInterface::class, $em);

        // REAL service + REAL serializer
        $this->bind(
            RecommendationsService::class,
            new RecommendationsService($em, $this->logger),
        );
        $this->bind(
            RecommendationsSerializer::class,
            new RecommendationsSerializer(new ProductSerializer()),
        );

        $response = $this->handle(
            $this->jsonRequest('GET', '/v3/products/lonely-product/recommendations', []),
        );

        self::assertSame(200, $response->getStatusCode());
        $body = $this->jsonBody($response);

        // Fallback row landed in the response
        self::assertCount(1, $body['data']);
        self::assertSame(200, $body['data'][0]['product']['id']);
        self::assertSame('fallback_popular', $body['data'][0]['source']);
        self::assertSame('50.0000', $body['data'][0]['score']);

        // Service's observability log propagated through the
        // controller stack
        $served = $this->logger->findByMessage('recommendations.product.served');
        self::assertCount(1, $served);
        self::assertSame('debug', $served[0]['level']);
        self::assertSame(100, $served[0]['context']['product_id']);
        self::assertTrue($served[0]['context']['used_fallback']);
        self::assertSame(1, $served[0]['context']['returned']);
    }
}
