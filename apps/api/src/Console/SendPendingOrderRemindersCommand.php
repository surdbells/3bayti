<?php

declare(strict_types=1);

namespace Bayti\Api\Console;

use Bayti\Api\Domain\Order\Order;
use Bayti\Api\Domain\Order\PendingOrderReminderFinder;
use Bayti\Api\Notification\OrderNotificationService;
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
 * Send automated "complete your payment" reminders for pending / failed
 * orders (email + push).
 *
 * Why this exists
 * ===============
 * Customers reach the payment gateway and drop off (order stays
 * `pending_payment`), or their card is declined (order goes `failed`).
 * Both are recoverable, a timely reminder brings a share of them back to
 * a completed sale, the order equivalent of abandoned-cart recovery.
 *
 * What it does (each run)
 * =======================
 *   1. Finds email-eligible + push-eligible order ids via
 *      PendingOrderReminderFinder (status pending_payment/failed, aged into
 *      the [minAge, maxAge] window, not already reminded on that channel).
 *   2. Emails each via OrderNotificationService::orderPaymentReminder, the
 *      service writes a notification_logs row (sent/failed/skipped) keyed to
 *      the order, which makes it ineligible on later runs.
 *   3. Pushes each via PushNotificationService::orderPaymentReminder and
 *      records a channel='push' notification_logs row via
 *      PushNotificationLogger, the independent push idempotency marker.
 *   4. Prints an ops summary and emits a structured batch log.
 *
 * The reminder copy adapts to the order's status: `failed` → retry prompt,
 * anything else (`pending_payment`) → gentle "finish paying" nudge.
 *
 * Idempotency & isolation
 * =======================
 * One email + one push per order, each guarded by its own NOT EXISTS in the
 * finder, so re-running never double-nudges. Every order is processed in its
 * own try/catch, one failure never aborts the batch.
 *
 * Scheduling (aaPanel cron)
 * =========================
 *   php /www/wwwroot/<api>/bin/console orders:send-payment-reminders
 * Recommended cadence: every 30–60 minutes. --dry-run lists eligible orders
 * without sending or writing any log rows.
 */
#[AsCommand(
    name: 'orders:send-payment-reminders',
    description: 'Email + push reminders for pending_payment / failed orders',
)]
final class SendPendingOrderRemindersCommand extends Command
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly PendingOrderReminderFinder $finder,
        private readonly OrderNotificationService $notifications,
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
                'List eligible orders without sending anything',
            )
            ->addOption(
                'min-age-hours',
                null,
                InputOption::VALUE_REQUIRED,
                'Only remind orders at least this many hours old (default ' . PendingOrderReminderFinder::DEFAULT_MIN_AGE_HOURS . ')',
                (string) PendingOrderReminderFinder::DEFAULT_MIN_AGE_HOURS,
            )
            ->addOption(
                'max-age-hours',
                null,
                InputOption::VALUE_REQUIRED,
                'Stop reminding orders older than this many hours (default ' . PendingOrderReminderFinder::DEFAULT_MAX_AGE_HOURS . ')',
                (string) PendingOrderReminderFinder::DEFAULT_MAX_AGE_HOURS,
            )
            ->addOption(
                'followup-after-hours',
                null,
                InputOption::VALUE_REQUIRED,
                'Send the second (follow-up) reminder this many hours after the first (default ' . PendingOrderReminderFinder::DEFAULT_FOLLOWUP_AFTER_HOURS . ')',
                (string) PendingOrderReminderFinder::DEFAULT_FOLLOWUP_AFTER_HOURS,
            )
            ->addOption(
                'batch-size',
                null,
                InputOption::VALUE_REQUIRED,
                'Max orders per channel this run (default ' . PendingOrderReminderFinder::DEFAULT_BATCH_SIZE . ', max ' . PendingOrderReminderFinder::MAX_BATCH_SIZE . ')',
                (string) PendingOrderReminderFinder::DEFAULT_BATCH_SIZE,
            );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $dryRun = (bool) $input->getOption('dry-run');
        $minAge = max(0, (int) $input->getOption('min-age-hours'));
        $maxAge = (int) $input->getOption('max-age-hours');
        $followupAfter = max(1, (int) $input->getOption('followup-after-hours'));
        $batchSize = max(1, min(
            PendingOrderReminderFinder::MAX_BATCH_SIZE,
            (int) $input->getOption('batch-size'),
        ));

        $now = new DateTimeImmutable();

        $io->title(sprintf(
            'Sending payment reminders (1st at %d–%dh, follow-up +%dh, batch %d)%s',
            $minAge,
            $maxAge,
            $followupAfter,
            $batchSize,
            $dryRun ? ' [DRY RUN]' : '',
        ));

        // Stage 1, first reminder for freshly-abandoned/failed orders.
        $emailIds = $this->finder->findEmailEligibleOrderIds($now, $minAge, $maxAge, $batchSize);
        $pushIds = $this->finder->findPushEligibleOrderIds($now, $minAge, $maxAge, $batchSize);
        // Stage 2, follow-up for orders whose first reminder went out ≥ followupAfter ago.
        $emailFollowupIds = $this->finder->findEmailFollowupEligibleOrderIds($now, $followupAfter, $maxAge, $batchSize);
        $pushFollowupIds = $this->finder->findPushFollowupEligibleOrderIds($now, $followupAfter, $maxAge, $batchSize);

        $io->writeln(sprintf(
            'First: <info>%d</info> email, <info>%d</info> push. Follow-up: <info>%d</info> email, <info>%d</info> push.',
            count($emailIds),
            count($pushIds),
            count($emailFollowupIds),
            count($pushFollowupIds),
        ));

        if ($emailIds === [] && $pushIds === [] && $emailFollowupIds === [] && $pushFollowupIds === []) {
            $io->success('Nothing to send.');
            return Command::SUCCESS;
        }

        if ($dryRun) {
            $fmt = static fn (array $ids): string => $ids === [] ? '(none)' : implode(', ', array_map(strval(...), $ids));
            $io->writeln('');
            $io->writeln('First email IDs:     ' . $fmt($emailIds));
            $io->writeln('First push IDs:      ' . $fmt($pushIds));
            $io->writeln('Follow-up email IDs: ' . $fmt($emailFollowupIds));
            $io->writeln('Follow-up push IDs:  ' . $fmt($pushFollowupIds));
            $io->success('[DRY RUN] nothing sent.');
            return Command::SUCCESS;
        }

        $emailStats = $this->dispatchEmail($emailIds, $io, false);
        $emailFollowupStats = $this->dispatchEmail($emailFollowupIds, $io, true);
        $pushStats = $this->dispatchPush($pushIds, $io, false);
        $pushFollowupStats = $this->dispatchPush($pushFollowupIds, $io, true);

        $io->section('Summary');
        $io->table(
            ['Channel', 'Stage', 'Found', 'Processed', 'Errors'],
            [
                ['Email', 'first', (string) count($emailIds), (string) $emailStats['processed'], (string) $emailStats['errors']],
                ['Email', 'follow-up', (string) count($emailFollowupIds), (string) $emailFollowupStats['processed'], (string) $emailFollowupStats['errors']],
                ['Push', 'first', (string) count($pushIds), (string) $pushStats['processed'], (string) $pushStats['errors']],
                ['Push', 'follow-up', (string) count($pushFollowupIds), (string) $pushFollowupStats['processed'], (string) $pushFollowupStats['errors']],
            ],
        );

        $errors = $emailStats['errors'] + $emailFollowupStats['errors']
            + $pushStats['errors'] + $pushFollowupStats['errors'];

        $this->logger->info('payment_reminders.batch_complete', [
            'email_found' => count($emailIds),
            'email_processed' => $emailStats['processed'],
            'email_errors' => $emailStats['errors'],
            'email_followup_found' => count($emailFollowupIds),
            'email_followup_processed' => $emailFollowupStats['processed'],
            'email_followup_errors' => $emailFollowupStats['errors'],
            'push_found' => count($pushIds),
            'push_processed' => $pushStats['processed'],
            'push_errors' => $pushStats['errors'],
            'push_followup_found' => count($pushFollowupIds),
            'push_followup_processed' => $pushFollowupStats['processed'],
            'push_followup_errors' => $pushFollowupStats['errors'],
            'min_age_hours' => $minAge,
            'max_age_hours' => $maxAge,
            'followup_after_hours' => $followupAfter,
            'batch_size' => $batchSize,
        ]);

        return $errors === 0 ? Command::SUCCESS : Command::FAILURE;
    }

    /**
     * Email one reminder per eligible order. OrderNotificationService writes
     * the notification_logs row (and flushes) itself, so no extra flush is
     * needed here.
     *
     * @param list<int> $orderIds
     * @return array{processed: int, errors: int}
     */
    private function dispatchEmail(array $orderIds, SymfonyStyle $io, bool $followup): array
    {
        $processed = 0;
        $errors = 0;

        foreach ($orderIds as $orderId) {
            try {
                $order = $this->em->find(Order::class, $orderId);
                if (!$order instanceof Order) {
                    $this->logger->info('payment_reminders.email.order_disappeared', ['order_id' => $orderId]);
                    continue;
                }
                $this->notifications->orderPaymentReminder($order, $this->reasonFor($order), $followup);
                $processed++;
            } catch (\Throwable $e) {
                $errors++;
                $io->writeln(sprintf('<error>Order #%d email failed: %s</error>', $orderId, $e->getMessage()));
                $this->logger->error('payment_reminders.email.order_failed', [
                    'order_id' => $orderId,
                    'error' => $e->getMessage(),
                    'class' => $e::class,
                ]);
            }
        }

        return ['processed' => $processed, 'errors' => $errors];
    }

    /**
     * Push one reminder per eligible order, logging a channel='push' row so
     * the order is ineligible on later runs (independent of the email guard).
     *
     * @param list<int> $orderIds
     * @return array{processed: int, errors: int}
     */
    private function dispatchPush(array $orderIds, SymfonyStyle $io, bool $followup): array
    {
        $processed = 0;
        $errors = 0;
        $template = $followup
            ? PendingOrderReminderFinder::PUSH_TEMPLATE_FOLLOWUP
            : PendingOrderReminderFinder::PUSH_TEMPLATE;

        foreach ($orderIds as $orderId) {
            try {
                $order = $this->em->find(Order::class, $orderId);
                if (!$order instanceof Order) {
                    $this->logger->info('payment_reminders.push.order_disappeared', ['order_id' => $orderId]);
                    continue;
                }

                // Belt-and-braces: skip if a push row already exists for this
                // stage (e.g. the same id surfaced twice in one large batch).
                if ($this->pushLog->pushSentForOrder($orderId, $template)) {
                    continue;
                }

                // Fire-and-forget: never throws, honours dead-token pruning,
                // no-ops when the customer has no active device.
                $this->push->orderPaymentReminder($order, $this->reasonFor($order), $followup);

                // Write a 'sent' marker regardless of token presence, like the
                // cart push side, the row means 'we evaluated this order for a
                // push reminder', so it isn't re-evaluated next run.
                $this->pushLog->log(
                    template: $template,
                    status: PushNotificationLogger::STATUS_SENT,
                    orderId: $orderId,
                    userId: $order->getUser()->getId(),
                );
                $processed++;
            } catch (\Throwable $e) {
                $errors++;
                $io->writeln(sprintf('<error>Order #%d push failed: %s</error>', $orderId, $e->getMessage()));
                $this->logger->error('payment_reminders.push.order_failed', [
                    'order_id' => $orderId,
                    'error' => $e->getMessage(),
                    'class' => $e::class,
                ]);
            }
        }

        return ['processed' => $processed, 'errors' => $errors];
    }

    /** 'failed' for a failed charge (retry copy); 'pending' otherwise. */
    private function reasonFor(Order $order): string
    {
        return $order->getStatus() === Order::STATUS_FAILED ? 'failed' : 'pending';
    }
}
