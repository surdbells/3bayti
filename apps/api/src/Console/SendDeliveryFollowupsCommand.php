<?php

declare(strict_types=1);

namespace Bayti\Api\Console;

use Bayti\Api\Domain\Order\Order;
use Bayti\Api\Domain\Order\OrderRepository;
use Bayti\Api\Notification\Push\PushNotificationLogger;
use Bayti\Api\Notification\Push\PushNotificationService;
use DateTimeImmutable;
use DateTimeZone;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Post-delivery review-prompt PUSH follow-up (marketing).
 *
 * Why this exists
 * ===============
 * A couple of days after an order lands, a gentle "how was it?" push
 * is the highest-converting moment to ask for a review. This cron
 * finds delivered orders past a settle window and sends exactly one
 * review-prompt push each.
 *
 * What it does
 * ============
 *   1. OrderRepository::findDeliveredForFollowUp(now - settleDays)
 *      selects DELIVERED orders whose delivered_at is old enough and
 *      that have no prior review-prompt PUSH log row.
 *   2. For each, PushNotificationService::orderReviewPrompt() fans the
 *      push out to the customer's devices (fire-and-forget; internally
 *      honors marketing_push_opt_out — the THREE marketing pushes skip
 *      opted-out users, unlike transactional order-lifecycle pushes).
 *   3. Logs the attempt to notification_logs with
 *      template='order.review_prompt', channel='push', order_id set —
 *      that row makes the order ineligible on the next run.
 *
 * Idempotency
 * ===========
 * Re-running is safe. The finder's channel-scoped NOT EXISTS guard
 * excludes any order that already has an order.review_prompt push log
 * row, so each delivered order is prompted at most once.
 *
 * Failure isolation
 * =================
 * Each order is processed in its own try/catch — one order's failure
 * never aborts the batch.
 */
#[AsCommand(
    name: 'orders:send-delivery-followups',
    description: 'Send post-delivery review-prompt push notifications for settled deliveries',
)]
final class SendDeliveryFollowupsCommand extends Command
{
    private const DEFAULT_SETTLE_DAYS = 2;
    private const MIN_SETTLE_DAYS = 1;
    private const MAX_SETTLE_DAYS = 60;
    private const DEFAULT_BATCH_SIZE = 100;
    private const MAX_BATCH_SIZE = 500;

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly PushNotificationService $push,
        private readonly PushNotificationLogger $pushLog,
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
                'List eligible orders without sending pushes',
            )
            ->addOption(
                'settle-days',
                null,
                InputOption::VALUE_REQUIRED,
                'Wait this many days after delivery before prompting (default ' . self::DEFAULT_SETTLE_DAYS . ')',
                (string) self::DEFAULT_SETTLE_DAYS,
            )
            ->addOption(
                'batch-size',
                null,
                InputOption::VALUE_REQUIRED,
                'Max orders to process this run (default ' . self::DEFAULT_BATCH_SIZE . ', max ' . self::MAX_BATCH_SIZE . ')',
                (string) self::DEFAULT_BATCH_SIZE,
            );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $dryRun = (bool) $input->getOption('dry-run');
        $settleDays = $this->clamp(
            (int) $input->getOption('settle-days'),
            self::MIN_SETTLE_DAYS,
            self::MAX_SETTLE_DAYS,
        );
        $batchSize = max(1, min(self::MAX_BATCH_SIZE, (int) $input->getOption('batch-size')));

        $now = new DateTimeImmutable('now', new DateTimeZone('UTC'));
        $deliveredBefore = $now->sub(new \DateInterval('P' . $settleDays . 'D'));

        $io->title(sprintf(
            'Post-delivery review prompts (settle %dd, batch %d)%s',
            $settleDays,
            $batchSize,
            $dryRun ? ' [DRY RUN]' : '',
        ));

        /** @var OrderRepository $repo */
        $repo = $this->em->getRepository(Order::class);
        $orders = $repo->findDeliveredForFollowUp($deliveredBefore, $batchSize);

        $totalFound = count($orders);
        $io->writeln(sprintf('Found <info>%d</info> delivered order(s) to prompt.', $totalFound));

        if ($totalFound === 0) {
            $io->success('Nothing to send.');
            return Command::SUCCESS;
        }

        if ($dryRun) {
            $io->writeln('');
            $io->writeln('Eligible order IDs (no pushes sent):');
            $ids = array_map(static fn (Order $o): string => (string) $o->getId(), $orders);
            $io->writeln('  ' . implode(', ', $ids));
            $io->success(sprintf('[DRY RUN] %d order(s) would be processed.', $totalFound));
            return Command::SUCCESS;
        }

        $processed = 0;
        $errors = 0;

        foreach ($orders as $order) {
            $orderId = $order->getId();
            try {
                if ($orderId !== null && $this->pushLog->pushSentForOrder($orderId, 'order.review_prompt')) {
                    continue;
                }

                $this->push->orderReviewPrompt($order);

                $this->pushLog->log(
                    template: 'order.review_prompt',
                    status: PushNotificationLogger::STATUS_SENT,
                    orderId: $orderId,
                    userId: $order->getUser()->getId(),
                );
                $processed++;
            } catch (\Throwable $e) {
                $errors++;
                $io->writeln(sprintf(
                    '<error>Order #%s failed: %s</error>',
                    (string) $orderId,
                    $e->getMessage(),
                ));
                $this->logger->error('delivery_followup.order_failed', [
                    'order_id' => $orderId,
                    'error' => $e->getMessage(),
                    'class' => $e::class,
                ]);
            }
        }

        $io->section('Summary');
        $io->table(
            ['Outcome', 'Count'],
            [
                ['Found', (string) $totalFound],
                ['Processed', (string) $processed],
                ['Errors', (string) $errors],
            ],
        );

        $this->logger->info('delivery_followup.batch_complete', [
            'found' => $totalFound,
            'processed' => $processed,
            'errors' => $errors,
            'settle_days' => $settleDays,
            'batch_size' => $batchSize,
        ]);

        return $errors === 0 ? Command::SUCCESS : Command::FAILURE;
    }

    private function clamp(int $value, int $min, int $max): int
    {
        return max($min, min($max, $value));
    }
}
