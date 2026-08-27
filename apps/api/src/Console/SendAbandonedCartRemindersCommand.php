<?php

declare(strict_types=1);

namespace Bayti\Api\Console;

use Bayti\Api\Domain\Cart\Cart;
use Bayti\Api\Domain\Cart\CartAbandonmentFinder;
use Bayti\Api\Domain\Cart\CartRepository;
use Bayti\Api\Notification\CartNotificationService;
use Bayti\Api\Notification\Push\PushNotificationLogger;
use Bayti\Api\Notification\Push\PushNotificationService;
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
 * This command runs on a cron schedule (operator-configurable -
 * recommended every 1-2 hours). It:
 *   1. Finds eligible carts via CartAbandonmentFinder
 *      (status=active, items present, updated_at past threshold,
 *      user has email, no prior reminder)
 *   2. For each cart, calls CartNotificationService::cartAbandoned
 *      which respects the marketing-opt-out flag and writes a
 *      notification_log row (SENT/FAILED/SKIPPED) with cart_id
 *      populated, that row makes the cart ineligible for future
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
 * batch, the next cart is still processed. Matches the
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
        private readonly PushNotificationService $push,
        private readonly PushNotificationLogger $pushLog,
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

        // Email-eligible carts (idempotent on channel='email' via the
        // email template log) and push-eligible carts (idempotent on
        // channel='push') are found INDEPENDENTLY. A cart can appear in
        // one set, both, or neither, an email log never suppresses a
        // push and vice-versa (the new notification_logs.channel column
        // is what keeps the two streams separate).
        $cartIds = $this->finder->findEligibleCartIds(
            now: $now,
            threshold: $threshold,
            limit: $batchSize,
        );
        $pushCartIds = $this->finder->findPushEligibleCartIds(
            now: $now,
            threshold: $threshold,
            limit: $batchSize,
        );

        $totalFound = count($cartIds);
        $totalPush = count($pushCartIds);
        $io->writeln(sprintf(
            'Found <info>%d</info> email-eligible and <info>%d</info> push-eligible cart(s).',
            $totalFound,
            $totalPush,
        ));

        if ($totalFound === 0 && $totalPush === 0) {
            $io->success('Nothing to send.');
            return Command::SUCCESS;
        }

        if ($dryRun) {
            $io->writeln('');
            $io->writeln('Email-eligible cart IDs (nothing sent):');
            $io->writeln('  ' . ($cartIds === [] ? '(none)' : implode(', ', array_map(strval(...), $cartIds))));
            $io->writeln('Push-eligible cart IDs (nothing sent):');
            $io->writeln('  ' . ($pushCartIds === [] ? '(none)' : implode(', ', array_map(strval(...), $pushCartIds))));
            $io->success(sprintf(
                '[DRY RUN] %d email + %d push cart(s) would be processed.',
                $totalFound,
                $totalPush,
            ));
            return Command::SUCCESS;
        }

        $stats = $this->dispatch($cartIds, $io);
        $pushStats = $this->dispatchPush($pushCartIds, $io);

        $io->section('Summary');
        $io->table(
            ['Channel', 'Found', 'Processed', 'Errors'],
            [
                ['Email', (string) $totalFound, (string) $stats['processed'], (string) $stats['errors']],
                ['Push', (string) $totalPush, (string) $pushStats['processed'], (string) $pushStats['errors']],
            ],
        );

        $errors = $stats['errors'] + $pushStats['errors'];

        $this->logger->info('cart_reminders.batch_complete', [
            'found' => $totalFound,
            'processed' => $stats['processed'],
            'errors' => $stats['errors'],
            'push_found' => $totalPush,
            'push_processed' => $pushStats['processed'],
            'push_errors' => $pushStats['errors'],
            'threshold_hours' => $hours,
            'batch_size' => $batchSize,
        ]);

        return $errors === 0 ? Command::SUCCESS : Command::FAILURE;
    }

    /**
     * Dispatch abandoned-cart PUSH notifications for a list of cart ids.
     * Each push is logged to notification_logs with channel='push' so
     * the cart becomes ineligible on subsequent runs (independent of the
     * email reminder's idempotency).
     *
     * @param list<int> $cartIds
     * @return array{processed: int, errors: int}
     */
    private function dispatchPush(array $cartIds, SymfonyStyle $io): array
    {
        if ($cartIds === []) {
            return ['processed' => 0, 'errors' => 0];
        }

        /** @var CartRepository $carts */
        $carts = $this->em->getRepository(Cart::class);

        $processed = 0;
        $errors = 0;

        foreach ($cartIds as $cartId) {
            try {
                $cart = $carts->find($cartId);
                if (!$cart instanceof Cart) {
                    $this->logger->info('cart_reminders.push.cart_disappeared', [
                        'cart_id' => $cartId,
                    ]);
                    continue;
                }

                // Belt-and-braces re-check the per-cart push log in case
                // the same cart id appeared twice in a very large batch.
                if ($this->pushLog->pushSentForCart($cartId, 'cart.abandoned')) {
                    continue;
                }

                // PushNotificationService::cartAbandoned is fire-and-forget
                // (never throws) and internally honors marketing_push_opt_out.
                $this->push->cartAbandoned($cart);

                // Log the attempt with channel='push'. We write a 'sent'
                // row even when the user opted out / had no token: the row
                // is a 'we evaluated this cart for push' marker, mirroring
                // the email side's persistent-suppression semantics, so we
                // don't re-evaluate the same cart every cron run.
                $this->pushLog->log(
                    template: 'cart.abandoned',
                    status: PushNotificationLogger::STATUS_SENT,
                    cartId: $cartId,
                    userId: $cart->getUser()?->getId(),
                );
                $processed++;
            } catch (\Throwable $e) {
                $errors++;
                $io->writeln(sprintf(
                    '<error>Cart #%d push failed: %s</error>',
                    $cartId,
                    $e->getMessage(),
                ));
                $this->logger->error('cart_reminders.push.cart_failed', [
                    'cart_id' => $cartId,
                    'error' => $e->getMessage(),
                    'class' => $e::class,
                ]);
            }
        }

        return ['processed' => $processed, 'errors' => $errors];
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
                    // Rare race, log and continue.
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
