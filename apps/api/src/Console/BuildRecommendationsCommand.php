<?php

declare(strict_types=1);

namespace Bayti\Api\Console;

use Bayti\Api\Domain\Catalog\CategoryAffinityCalculator;
use Bayti\Api\Domain\Catalog\CoPurchaseAffinityCalculator;
use Bayti\Api\Domain\Catalog\ProductRecommendation;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Build the product_recommendations denormalized table from
 * scratch (M3.2.X.12-E).
 *
 * Why this exists
 * ===============
 * Q-Caching = B locked: denormalized table populated nightly.
 * Per-request co-purchase computation would be far too slow
 * (full order_items table scan on every catalog page view).
 * Instead, this cron rebuilds the recommendation index once per
 * night and the read path becomes a single indexed lookup on
 * (product_id, rank).
 *
 * Algorithm: Q-Algorithm = B locked
 *   1. Compute co-purchase affinity graph (X.12-C calculator)
 *   2. Compute category affinity graph (X.12-D calculator)
 *   3. For each product:
 *      a. Take all its co-purchase recommendations
 *      b. Top up from category recommendations (excluding any
 *         products already recommended via co-purchase) until
 *         we reach TOP_N_TARGET total recommendations OR run out
 *         of category candidates
 *      c. If still under TOP_N_TARGET, the product gets
 *         fallback_popular rows (added by X.12-F at read time
 *         from a marketplace-wide popularity ranking) — we do
 *         NOT persist fallback_popular rows here; they're
 *         dynamic at the read tier
 *   4. Bulk-delete all existing rows + bulk-insert the fresh
 *      batch in a single transaction so the read tier never
 *      sees a partial state.
 *
 * Idempotency
 * ===========
 * Each run truncates the entire table before re-populating.
 * No need for upsert logic — clean slate every time. The
 * read tier serves stale data during the cron run only for
 * the duration of the transaction (typically <30s).
 *
 * Dry-run
 * =======
 * --dry-run computes both graphs + the merge but does NOT
 * touch the product_recommendations table. Useful for
 * pre-deployment sanity checks against staging data.
 *
 * Schedule
 * ========
 * Recommended cron: daily at 02:00 UTC (off-peak).
 * Operator follow-up #39 (added in X.12-I) covers wiring
 * the cron entry into the production scheduler.
 */
#[AsCommand(
    name: 'recommendations:build',
    description: 'Rebuild the product_recommendations denormalized table from order history and category data.',
)]
final class BuildRecommendationsCommand extends Command
{
    /**
     * Target number of recommendations per product. The X.12-G
     * read endpoint defaults to limit=10 and clamps to [3, 20];
     * we persist headroom to 20 so the read tier always has
     * enough rows without re-running the cron at higher limits.
     */
    public const TOP_N_TARGET = 20;

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly CoPurchaseAffinityCalculator $copurchaseCalc,
        private readonly CategoryAffinityCalculator $categoryCalc,
        private readonly LoggerInterface $logger,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption(
                'dry-run',
                null,
                InputOption::VALUE_NONE,
                'Compute the recommendation graphs but do not modify the database.',
            );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $dryRun = (bool) $input->getOption('dry-run');

        $io->title('Build product recommendations');
        $io->writeln($dryRun ? '<comment>DRY RUN — no database changes</comment>' : '<info>LIVE RUN</info>');

        $start = microtime(true);

        // 1. Compute both affinity graphs
        $io->section('Computing co-purchase affinity graph');
        $copurchaseGraph = $this->copurchaseCalc->computeFullGraph();
        $io->writeln(sprintf('  %d source products with co-purchase data', count($copurchaseGraph)));

        $io->section('Computing category affinity graph');
        $categoryGraph = $this->categoryCalc->computeFullGraph();
        $io->writeln(sprintf('  %d source products with category data', count($categoryGraph)));

        // 2. Merge per product
        $io->section('Merging recommendation lists');
        $merged = $this->mergeGraphs($copurchaseGraph, $categoryGraph);
        $totalRows = array_sum(array_map('count', $merged));
        $io->writeln(sprintf(
            '  %d source products × ~%.1f recs avg = %d total rows',
            count($merged),
            count($merged) > 0 ? $totalRows / count($merged) : 0.0,
            $totalRows,
        ));

        if ($dryRun) {
            $duration = (int) ((microtime(true) - $start) * 1000);
            $io->success(sprintf('DRY RUN complete in %d ms — would write %d rows', $duration, $totalRows));
            return Command::SUCCESS;
        }

        // 3. Persist in a single transaction
        $io->section('Writing to product_recommendations table');
        $deleted = $this->persistRecommendations($merged);

        $duration = (int) ((microtime(true) - $start) * 1000);
        $this->logger->info('recommendations.build.completed', [
            'source_products' => count($merged),
            'total_rows' => $totalRows,
            'deleted_rows' => $deleted,
            'duration_ms' => $duration,
            'dry_run' => false,
        ]);

        $io->success(sprintf(
            'Build complete in %d ms — deleted %d old rows, inserted %d new rows for %d source products',
            $duration,
            $deleted,
            $totalRows,
            count($merged),
        ));

        return Command::SUCCESS;
    }

    /**
     * Merge co-purchase + category graphs per product. Co-purchase
     * rows go first (they have higher scores); category rows top
     * up the rest, excluding any product_id already present via
     * co-purchase.
     *
     * @param array<int, list<array{recommended_product_id: int, score: string}>> $copurchase
     * @param array<int, list<array{recommended_product_id: int, score: string}>> $category
     * @return array<int, list<array{recommended_product_id: int, score: string, source: string, rank: int}>>
     */
    private function mergeGraphs(array $copurchase, array $category): array
    {
        $merged = [];
        $allSourceIds = array_unique(array_merge(
            array_keys($copurchase),
            array_keys($category),
        ));

        foreach ($allSourceIds as $sid) {
            $list = [];
            $seen = [];  // recommended_product_id set
            $rank = 1;

            // Co-purchase rows first
            foreach ($copurchase[$sid] ?? [] as $rec) {
                if ($rank > self::TOP_N_TARGET) {
                    break;
                }
                $list[] = [
                    'recommended_product_id' => $rec['recommended_product_id'],
                    'score' => $rec['score'],
                    'source' => ProductRecommendation::SOURCE_COPURCHASE,
                    'rank' => $rank,
                ];
                $seen[$rec['recommended_product_id']] = true;
                $rank++;
            }

            // Top up with category rows, skipping already-recommended
            foreach ($category[$sid] ?? [] as $rec) {
                if ($rank > self::TOP_N_TARGET) {
                    break;
                }
                if (isset($seen[$rec['recommended_product_id']])) {
                    continue;
                }
                $list[] = [
                    'recommended_product_id' => $rec['recommended_product_id'],
                    'score' => $rec['score'],
                    'source' => ProductRecommendation::SOURCE_CATEGORY,
                    'rank' => $rank,
                ];
                $seen[$rec['recommended_product_id']] = true;
                $rank++;
            }

            if ($list !== []) {
                $merged[$sid] = $list;
            }
        }

        return $merged;
    }

    /**
     * Truncate the table + bulk-insert the merged graph in a
     * single transaction.
     *
     * @param array<int, list<array{recommended_product_id: int, score: string, source: string, rank: int}>> $merged
     * @return int Number of rows deleted (size of the prior batch)
     */
    private function persistRecommendations(array $merged): int
    {
        $conn = $this->em->getConnection();
        $conn->beginTransaction();
        try {
            // 1. Full truncate (much faster than per-product delete
            //    when rebuilding the whole graph)
            $deleted = (int) $conn->executeStatement('DELETE FROM product_recommendations');

            // 2. Bulk insert via batched INSERT statements
            $computedAt = (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))
                ->format('Y-m-d H:i:sP');

            $batchSize = 500;
            $batchValues = [];
            $batchParams = [];

            foreach ($merged as $sourceProductId => $list) {
                foreach ($list as $rec) {
                    $batchValues[] = '(?, ?, ?, ?, ?, ?)';
                    $batchParams[] = $sourceProductId;
                    $batchParams[] = $rec['recommended_product_id'];
                    $batchParams[] = $rec['score'];
                    $batchParams[] = $rec['source'];
                    $batchParams[] = $rec['rank'];
                    $batchParams[] = $computedAt;

                    if (count($batchValues) >= $batchSize) {
                        $this->flushBatch($batchValues, $batchParams);
                        $batchValues = [];
                        $batchParams = [];
                    }
                }
            }
            if ($batchValues !== []) {
                $this->flushBatch($batchValues, $batchParams);
            }

            $conn->commit();
            return $deleted;
        } catch (\Throwable $e) {
            $conn->rollBack();
            throw $e;
        }
    }

    /**
     * @param list<string> $valuesPlaceholders
     * @param list<mixed> $params
     */
    private function flushBatch(array $valuesPlaceholders, array $params): void
    {
        if ($valuesPlaceholders === []) {
            return;
        }
        $sql = 'INSERT INTO product_recommendations '
            . '(product_id, recommended_product_id, score, source, rank, computed_at) '
            . 'VALUES ' . implode(', ', $valuesPlaceholders);
        $this->em->getConnection()->executeStatement($sql, $params);
    }
}
