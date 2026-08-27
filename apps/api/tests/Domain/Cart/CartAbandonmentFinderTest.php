<?php

declare(strict_types=1);

namespace Bayti\Api\Tests\Domain\Cart;

use Bayti\Api\Domain\Cart\CartAbandonmentFinder;
use Bayti\Api\Notification\EmailTemplate;
use Bayti\Api\Tests\Support\InMemoryLogger;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Result;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for CartAbandonmentFinder (M3.2.X.11-C).
 *
 * Approach: mock Connection::executeQuery to capture the SQL +
 * params + return canned cart-id rows. Verifies:
 *   - All 5 eligibility filters appear in the SQL
 *   - cutoff = now - threshold, computed correctly
 *   - NOT EXISTS guard references the cart.abandoned.customer template
 *   - LIMIT honors batch sizing + clamping
 *   - Observability fires (timing + slow-response)
 *   - Statement timeout failure is logged not propagated
 */
#[CoversClass(CartAbandonmentFinder::class)]
final class CartAbandonmentFinderTest extends TestCase
{
    // =================================================================
    // SQL shape
    // =================================================================

    #[Test]
    public function queryAppliesAllFiveEligibilityFilters(): void
    {
        $captured = $this->captureSql(rows: []);
        $finder = new CartAbandonmentFinder($captured['em'], new InMemoryLogger());

        $finder->findEligibleCartIds(
            now: new \DateTimeImmutable('2026-05-18 12:00:00+00'),
            threshold: new \DateInterval('PT24H'),
        );

        $sql = $captured['queries'][0]['sql'];

        // 1. status = 'active'
        self::assertStringContainsString("c.status = 'active'", $sql);
        // 2. user_id NOT NULL + email present
        self::assertStringContainsString('c.user_id IS NOT NULL', $sql);
        self::assertStringContainsString("u.email <> ''", $sql);
        // 3. updated_at < cutoff
        self::assertStringContainsString('c.updated_at < :cutoff', $sql);
        // 4. has at least one item
        self::assertStringContainsString('cart_items', $sql);
        self::assertStringContainsString('EXISTS', $sql);
        // 5. NO prior reminder
        self::assertStringContainsString('NOT EXISTS', $sql);
        self::assertStringContainsString('notification_logs', $sql);
    }

    #[Test]
    public function cutoffEqualsNowMinusThreshold(): void
    {
        $captured = $this->captureSql(rows: []);
        $finder = new CartAbandonmentFinder($captured['em'], new InMemoryLogger());

        $now = new \DateTimeImmutable('2026-05-18 12:00:00+00');
        $finder->findEligibleCartIds(
            now: $now,
            threshold: new \DateInterval('PT24H'),
        );

        $params = $captured['queries'][0]['params'];
        // 2026-05-18 12:00:00 minus 24h = 2026-05-17 12:00:00
        self::assertStringStartsWith('2026-05-17 12:00:00', $params['cutoff']);
    }

    #[Test]
    public function templateParamUsesEmailTemplateValue(): void
    {
        $captured = $this->captureSql(rows: []);
        $finder = new CartAbandonmentFinder($captured['em'], new InMemoryLogger());

        $finder->findEligibleCartIds(
            now: new \DateTimeImmutable('2026-05-18 12:00:00+00'),
            threshold: new \DateInterval('PT24H'),
        );

        $params = $captured['queries'][0]['params'];
        self::assertSame(
            EmailTemplate::CART_ABANDONED_CUSTOMER->value,
            $params['template'],
        );
        self::assertSame('cart.abandoned.customer', $params['template']);
    }

    #[Test]
    public function defaultLimitIs100(): void
    {
        $captured = $this->captureSql(rows: []);
        $finder = new CartAbandonmentFinder($captured['em'], new InMemoryLogger());

        $finder->findEligibleCartIds(
            now: new \DateTimeImmutable('2026-05-18 12:00:00+00'),
            threshold: new \DateInterval('PT24H'),
        );

        self::assertSame(100, $captured['queries'][0]['params']['limit']);
    }

    #[Test]
    public function customLimitForwarded(): void
    {
        $captured = $this->captureSql(rows: []);
        $finder = new CartAbandonmentFinder($captured['em'], new InMemoryLogger());

        $finder->findEligibleCartIds(
            now: new \DateTimeImmutable('2026-05-18 12:00:00+00'),
            threshold: new \DateInterval('PT24H'),
            limit: 25,
        );

        self::assertSame(25, $captured['queries'][0]['params']['limit']);
    }

    #[Test]
    public function limitClampedToMax(): void
    {
        $captured = $this->captureSql(rows: []);
        $finder = new CartAbandonmentFinder($captured['em'], new InMemoryLogger());

        $finder->findEligibleCartIds(
            now: new \DateTimeImmutable('2026-05-18 12:00:00+00'),
            threshold: new \DateInterval('PT24H'),
            limit: 99999,
        );

        self::assertSame(500, $captured['queries'][0]['params']['limit']);  // MAX_BATCH_SIZE
    }

    #[Test]
    public function zeroOrNegativeLimitFallsBackToDefault(): void
    {
        $captured = $this->captureSql(rows: []);
        $finder = new CartAbandonmentFinder($captured['em'], new InMemoryLogger());

        $finder->findEligibleCartIds(
            now: new \DateTimeImmutable('2026-05-18 12:00:00+00'),
            threshold: new \DateInterval('PT24H'),
            limit: 0,
        );

        self::assertSame(100, $captured['queries'][0]['params']['limit']);
    }

    // =================================================================
    // Result mapping
    // =================================================================

    #[Test]
    public function returnsCartIdsAsIntList(): void
    {
        $captured = $this->captureSql(rows: [
            ['id' => '101'],
            ['id' => '202'],
            ['id' => '303'],
        ]);
        $finder = new CartAbandonmentFinder($captured['em'], new InMemoryLogger());

        $ids = $finder->findEligibleCartIds(
            now: new \DateTimeImmutable('2026-05-18 12:00:00+00'),
            threshold: new \DateInterval('PT24H'),
        );

        self::assertSame([101, 202, 303], $ids);
    }

    #[Test]
    public function noEligibleCartsReturnsEmptyList(): void
    {
        $captured = $this->captureSql(rows: []);
        $finder = new CartAbandonmentFinder($captured['em'], new InMemoryLogger());

        $ids = $finder->findEligibleCartIds(
            now: new \DateTimeImmutable('2026-05-18 12:00:00+00'),
            threshold: new \DateInterval('PT24H'),
        );

        self::assertSame([], $ids);
    }

    // =================================================================
    // Observability
    // =================================================================

    #[Test]
    public function emitsTimingLogPerCall(): void
    {
        $logger = new InMemoryLogger();
        $captured = $this->captureSql(rows: [['id' => '101']]);
        $finder = new CartAbandonmentFinder($captured['em'], $logger);

        $finder->findEligibleCartIds(
            now: new \DateTimeImmutable('2026-05-18 12:00:00+00'),
            threshold: new \DateInterval('PT24H'),
        );

        $records = $logger->findByMessage('cart_abandonment_finder.computed');
        self::assertCount(1, $records);
        self::assertSame('debug', $records[0]['level']);
        self::assertArrayHasKey('duration_ms', $records[0]['context']);
        self::assertSame(1, $records[0]['context']['matched']);
        self::assertSame(100, $records[0]['context']['limit']);
    }

    #[Test]
    public function emitsSlowResponseWarningWhenOver500Ms(): void
    {
        $logger = new InMemoryLogger();
        $connection = $this->createMock(Connection::class);
        $callIdx = 0;
        $connection->method('executeQuery')->willReturnCallback(
            function () use (&$callIdx): Result {
                if ($callIdx === 0) {
                    usleep(510_000);  // 510ms, over the 500ms threshold
                }
                $callIdx++;
                $r = $this->createMock(Result::class);
                $r->method('fetchAllAssociative')->willReturn([]);
                return $r;
            },
        );
        $em = $this->emWithConnection($connection);

        $finder = new CartAbandonmentFinder($em, $logger);
        $finder->findEligibleCartIds(
            now: new \DateTimeImmutable('2026-05-18 12:00:00+00'),
            threshold: new \DateInterval('PT24H'),
        );

        $slow = $logger->findByMessage('cart_abandonment_finder.slow_response');
        self::assertCount(1, $slow);
        self::assertSame('warning', $slow[0]['level']);
        self::assertGreaterThan(500, $slow[0]['context']['duration_ms']);
        self::assertSame(500, $slow[0]['context']['threshold_ms']);
    }

    #[Test]
    public function timeoutFailureLoggedNotPropagated(): void
    {
        $logger = new InMemoryLogger();
        $connection = $this->createMock(Connection::class);
        $connection->method('executeStatement')->willThrowException(
            new \RuntimeException('SET LOCAL not supported'),
        );
        $connection->method('executeQuery')->willReturnCallback(
            function (): Result {
                $r = $this->createMock(Result::class);
                $r->method('fetchAllAssociative')->willReturn([]);
                return $r;
            },
        );
        $em = $this->emWithConnection($connection);

        $finder = new CartAbandonmentFinder($em, $logger);
        $ids = $finder->findEligibleCartIds(
            now: new \DateTimeImmutable('2026-05-18 12:00:00+00'),
            threshold: new \DateInterval('PT24H'),
        );

        self::assertSame([], $ids);
        $skipped = $logger->findByMessage('cart_abandonment_finder.timeout.skipped');
        self::assertCount(1, $skipped);
    }

    // =================================================================
    // Helpers
    // =================================================================

    /**
     * @param list<array<string, mixed>> $rows
     * @return array{em: EntityManagerInterface, queries: list<array{sql: string, params: array<string, mixed>}>}
     */
    private function captureSql(array $rows): array
    {
        $queries = [];

        $connection = $this->createMock(Connection::class);
        $connection->method('executeQuery')->willReturnCallback(
            function (string $sql, array $params) use (&$queries, $rows): Result {
                $queries[] = ['sql' => $sql, 'params' => $params];
                $r = $this->createMock(Result::class);
                $r->method('fetchAllAssociative')->willReturn($rows);
                return $r;
            },
        );

        $em = $this->emWithConnection($connection);
        return ['em' => $em, 'queries' => &$queries];
    }

    private function emWithConnection(Connection $connection): EntityManagerInterface
    {
        $em = $this->createMock(EntityManagerInterface::class);
        $em->method('getConnection')->willReturn($connection);
        return $em;
    }
}
