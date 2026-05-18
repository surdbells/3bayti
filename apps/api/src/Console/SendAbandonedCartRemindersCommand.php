<?php

declare(strict_types=1);

namespace Bayti\Api\Console;

use Bayti\Api\Domain\Cart\Cart;
use Bayti\Api\Domain\Cart\CartAbandonmentFinder;
use Bayti\Api\Domain\Cart\CartRepository;
use Bayti\Api\Notification\CartNotificationService;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Send abandoned cart recovery reminders (M3.2.X.11-F).
 *
 * Why this exists
 * ===============
 * Customers add items to carts and walk away. A well-timed reminder
 * email recovers a meaningful fraction of these as completed orders.
 * Industry conversion uplift from cart-abandonment recovery typically
 * runs 5-15% of abandoned carts.
 *
 * This command runs on a cron schedule (operator-configurable —
 * recommended every 1-2 hours). It:
 *   1. Finds eligible carts via CartAbandonmentFinder
 *      (status=active, items present, updated_at past threshold,
 *      user has email, no prior reminder)
 *   2. For each cart, calls CartNotificationService::cartAbandoned
 *      which respects the marketing-opt-out flag and writes a
 *      notification_log row (SENT/FAILED/SKIPPED) with cart_id
 *      populated — that row makes the cart ineligible for future
 *      runs (Q-MaxRemindersPerCart = A locked: exactly one reminder
 *      per cart, even if the customer later goes opted-out)
 *   3. Reports counts of sent/skipped/failed/errors for ops
 *
 * Idempotency
 * ===========
 * Re-running this command is safe. The Finder's NOT EXISTS guard
 * (notification_logs.cart_id = c.id AND template = ...) excludes
 * any cart that's already been evaluated, regardless of the outcome
 * status. The first run sends reminders; subsequent runs find
 * nothing for those carts.
 *
 * Dry-run support
 * ===============
 * --dry-run lists eligible carts without invoking the notification
 * service. Useful for capacity planning and pre-deployment
 * validation. Does NOT write any notification_log rows.
 *
 * Failure isolation
 * =================
 * Each cart processed in its own try/catch. One cart's failure
 * (e.g. transient DB error during persist) does NOT abort the
 * batch — the next cart is still processed. Matches the
 * established CartNotificationService::safeSend / safePersist
 * resilience pattern.
 *
 * Threshold configuration
 * ========================
 * Default 24 hours (Q-AbandonmentWindow = B locked: single
 * threshold). Operator can override via --threshold-hours. Range
 * is 1-168 (one hour to one week); values outside clamp to
 * boundaries.
 *
 * Operator-facing summary table at the end has the exact counts
 * needed for cron observability dashboards.
 */
#[AsCommand(
    name: 'carts:send-abandonment-reminders',
    description: 'Send abandoned cart recovery emails to eligible customers',
)]
final class SendAbandonedCartRemindersCommand extends Command
{
    private const MIN_THRESHOLD_HOURS = 1;
    private const MAX_THRESHOLD_HOURS = 168;

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly CartAbandonmentFinder $finder,
        private readonly CartNotificationService $notifications,
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
                'List eligible carts without sending emails',
            )
            ->addOption(
                'threshold-hours',
                null,
                InputOption::VALUE_REQUIRED,
                'Consider carts abandoned after this many hours (default ' . CartAbandonmentFinder::DEFAULT_THRESHOLD_HOURS . ')',
                (string) CartAbandonmentFinder::DEFAULT_THRESHOLD_HOURS,
            )
            ->addOption(
                'batch-size',
                null,
                InputOption::VALUE_REQUIRED,
                'Max carts to process this run (default ' . CartAbandonmentFinder::DEFAULT_BATCH_SIZE . ', max ' . CartAbandonmentFinder::MAX_BATCH_SIZE . ')',
                (string) CartAbandonmentFinder::DEFAULT_BATCH_SIZE,
            );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $dryRun = (bool) $input->getOption('dry-run');
        $hours = $this->clampThreshold((int) $input->getOption('threshold-hours'));
        $batchSize = max(1, min(
            CartAbandonmentFinder::MAX_BATCH_SIZE,
            (int) $input->getOption('batch-size'),
        ));

        $now = new DateTimeImmutable();
        $threshold = new \DateInterval('PT' . $hours . 'H');

        $io->title(sprintf(
            'Sending abandoned cart reminders (threshold %dh, batch %d)%s',
            $hours,
            $batchSize,
            $dryRun ? ' [DRY RUN]' : '',
        ));

        $cartIds = $this->finder->findEligibleCartIds(
            now: $now,
            threshold: $threshold,
            limit: $batchSize,
        );

        $totalFound = count($cartIds);
        $io->writeln(sprintf('Found <info>%d</info> eligible cart(s).', $totalFound));

        if ($totalFound === 0) {
            $io->success('Nothing to send.');
            return Command::SUCCESS;
        }

        if ($dryRun) {
            $io->writeln('');
            $io->writeln('Eligible cart IDs (no emails sent):');
            $io->writeln('  ' . implode(', ', array_map(strval(...), $cartIds)));
            $io->success(sprintf('[DRY RUN] %d cart(s) would be processed.', $totalFound));
            return Command::SUCCESS;
        }

        $stats = $this->dispatch($cartIds, $io);

        $io->section('Summary');
        $io->table(
            ['Outcome', 'Count'],
            [
                ['Found', (string) $totalFound],
                ['Processed', (string) $stats['processed']],
                ['Errors', (string) $stats['errors']],
            ],
        );

        $this->logger->info('cart_reminders.batch_complete', [
            'found' => $totalFound,
            'processed' => $stats['processed'],
            'errors' => $stats['errors'],
            'threshold_hours' => $hours,
            'batch_size' => $batchSize,
        ]);

        return $stats['errors'] === 0 ? Command::SUCCESS : Command::FAILURE;
    }

    /**
     * Dispatch reminders for a list of cart ids.
     *
     * @param list<int> $cartIds
     * @return array{processed: int, errors: int}
     */
    private function dispatch(array $cartIds, SymfonyStyle $io): array
    {
        /** @var CartRepository $carts */
        $carts = $this->em->getRepository(Cart::class);

        $processed = 0;
        $errors = 0;

        foreach ($cartIds as $cartId) {
            try {
                // Hydrate one at a time to keep memory bounded over
                // very large batches.
                $cart = $carts->find($cartId);
                if (!$cart instanceof Cart) {
                    // Cart was deleted between Finder query and now.
                    // Rare race — log and continue.
                    $this->logger->info('cart_reminders.cart_disappeared', [
                        'cart_id' => $cartId,
                    ]);
                    continue;
                }

                $this->notifications->cartAbandoned($cart);
                $processed++;

                // Flush after each cart so the notification_log row
                // becomes visible immediately. The CartNotification
                // Service writes the row but Doctrine doesn't push
                // until flush(). Per-cart flush keeps the 'persistent
                // suppression marker' guarantee intact even if a
                // later cart in the batch crashes the process.
                $this->em->flush();
            } catch (\Throwable $e) {
                // Per-cart failure must not abort the batch.
                $errors++;
                $io->writeln(sprintf(
                    '<error>Cart #%d failed: %s</error>',
                    $cartId,
                    $e->getMessage(),
                ));
                $this->logger->error('cart_reminders.cart_failed', [
                    'cart_id' => $cartId,
                    'error' => $e->getMessage(),
                    'class' => $e::class,
                ]);
            }
        }

        return ['processed' => $processed, 'errors' => $errors];
    }

    private function clampThreshold(int $hours): int
    {
        if ($hours < self::MIN_THRESHOLD_HOURS) {
            return self::MIN_THRESHOLD_HOURS;
        }
        if ($hours > self::MAX_THRESHOLD_HOURS) {
            return self::MAX_THRESHOLD_HOURS;
        }
        return $hours;
    }
}
