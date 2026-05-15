<?php

declare(strict_types=1);

namespace Bayti\Api\Console;

use Bayti\Api\Domain\Order\Order;
use Bayti\Api\Domain\Order\OrderRepository;
use Bayti\Api\Domain\Payment\PaymentTransaction;
use Bayti\Api\Domain\Payment\PaymentTransactionRepository;
use Bayti\Api\Payment\OrderStatusResponse;
use Bayti\Api\Payment\PaymentGatewayException;
use Bayti\Api\Payment\PaymentGatewayInterface;
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
 * Reconciles orders stuck in `pending_payment` state past a cutoff.
 *
 * Why this exists
 * ===============
 * M3.1.6 ships a webhook receiver that moves orders from pending_payment
 * to paid/failed when Noon notifies us. In practice, webhooks can fail
 * to arrive for many reasons (DNS hiccup at the moment of delivery,
 * downstream queue backup at Noon, our own backend being briefly down,
 * etc.). Without reconciliation, a customer who paid successfully but
 * whose webhook didn't arrive would see their order stuck forever.
 *
 * This command runs every 5 minutes via cron / systemd timer / k8s
 * CronJob (whichever the operator picks). For each pending_payment
 * order older than the cutoff, it:
 *
 *   1. Looks up the order's INITIATE PaymentTransaction to get
 *      provider_order_ref (Noon's order id)
 *   2. Calls gateway.retrieveOrder(provider_order_ref) — the SAME
 *      authoritative call the webhook controller uses (Noon GET_ORDER)
 *   3. If Noon says paid: marks the order paid (idempotent)
 *   4. If Noon says failed: marks failed
 *   5. If still transient: leaves alone for the next run
 *   6. On network/gateway error: logs and continues to next order
 *
 * Critical safety property: this command uses the SAME retrieve-order-
 * before-acting pattern as the webhook receiver. The state change is
 * driven by Noon's authoritative response, not by any local inference.
 * Re-running the command is safe — markPaid/markFailed are idempotent.
 *
 * Rate-limit awareness
 * ====================
 * Noon imposes rate limits on GET_ORDER. To avoid hammering them:
 *   - Default batch size: 50 orders per run
 *   - Operator should run at most every 5 minutes (cadence dictated
 *     by the cron schedule, not this command)
 *   - 50 orders/5min = ~10/min sustained, well under Noon's threshold
 *
 * Larger batch sizes are allowed via --batch-size but operators
 * should monitor for 429 responses in the gateway logs.
 *
 * Usage
 * =====
 *
 *   php bin/console orders:reconcile-pending             # apply changes
 *   php bin/console orders:reconcile-pending --dry-run   # report only
 *   php bin/console orders:reconcile-pending --cutoff-min=30  # custom cutoff
 *   php bin/console orders:reconcile-pending --batch-size=100 # larger batch
 *
 * Recommended cron schedule:
 *
 *   *\/5 * * * * php /path/to/3bayti/apps/api/bin/console orders:reconcile-pending
 *
 * Operators can pipe stdout/stderr to a log aggregator. The command
 * exits with code 0 on success regardless of how many orders were
 * processed; non-zero exit means a fatal error (e.g. missing
 * gateway config).
 */
#[AsCommand(
    name: 'orders:reconcile-pending',
    description: 'Reconcile orders stuck in pending_payment via Noon GET_ORDER',
)]
final class ReconcilePendingOrdersCommand extends Command
{
    /** Default cutoff: orders older than 15min are considered stuck */
    private const DEFAULT_CUTOFF_MINUTES = 15;

    /** Default batch size: 50 orders per run */
    private const DEFAULT_BATCH_SIZE = 50;

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly PaymentGatewayInterface $gateway,
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
                'Report what would happen without mutating any orders'
            )
            ->addOption(
                'cutoff-min',
                null,
                InputOption::VALUE_REQUIRED,
                'Consider orders stuck after this many minutes (default ' . self::DEFAULT_CUTOFF_MINUTES . ')',
                (string) self::DEFAULT_CUTOFF_MINUTES,
            )
            ->addOption(
                'batch-size',
                null,
                InputOption::VALUE_REQUIRED,
                'Max orders to process this run (default ' . self::DEFAULT_BATCH_SIZE . ')',
                (string) self::DEFAULT_BATCH_SIZE,
            );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $dryRun = (bool) $input->getOption('dry-run');
        $cutoffMin = max(1, (int) $input->getOption('cutoff-min'));
        $batchSize = max(1, min(500, (int) $input->getOption('batch-size')));

        $cutoff = (new DateTimeImmutable())->modify("-{$cutoffMin} minutes");

        $io->title(sprintf(
            'Reconciling pending_payment orders older than %s (batch %d)%s',
            $cutoff->format('Y-m-d H:i:s'),
            $batchSize,
            $dryRun ? ' [DRY RUN]' : '',
        ));

        /** @var OrderRepository $orders */
        $orders = $this->em->getRepository(Order::class);
        /** @var PaymentTransactionRepository $txns */
        $txns = $this->em->getRepository(PaymentTransaction::class);

        $candidates = $orders->findStuckPendingPayment($cutoff, $batchSize);
        $io->writeln(sprintf('Found <info>%d</info> stuck order(s).', count($candidates)));

        if (count($candidates) === 0) {
            $io->success('Nothing to reconcile.');
            return Command::SUCCESS;
        }

        $resolved = 0;
        $deferred = 0;
        $errors = 0;
        $paid = 0;
        $failed = 0;

        foreach ($candidates as $order) {
            $orderId = $order->getId();
            $reference = $order->getOrderReference();

            // Look up the initiate transaction to get provider_order_ref.
            $initiate = $txns->findLatestInitiateForOrder($order);
            if ($initiate === null) {
                $io->warning(sprintf(
                    'Order #%d (%s): no INITIATE transaction found; skipping (data integrity issue)',
                    $orderId ?? 0,
                    $reference,
                ));
                $this->logger->warning('reconcile_pending.no_initiate_transaction', [
                    'order_id' => $orderId,
                    'order_reference' => $reference,
                ]);
                $deferred++;
                continue;
            }

            $providerOrderRef = $initiate->getProviderOrderRef();
            if ($providerOrderRef === null || $providerOrderRef === '') {
                $io->warning(sprintf(
                    'Order #%d (%s): initiate transaction has no provider_order_ref; skipping',
                    $orderId ?? 0,
                    $reference,
                ));
                $deferred++;
                continue;
            }

            // Ask Noon — authoritative response. This is the same call
            // the webhook receiver uses; same safety semantics.
            try {
                $status = $this->gateway->retrieveOrder($providerOrderRef);
            } catch (PaymentGatewayException $e) {
                $io->writeln(sprintf(
                    '  <comment>Order #%d (%s): retrieveOrder failed (%s); will retry next run</comment>',
                    $orderId ?? 0,
                    $reference,
                    $e->getMessage(),
                ));
                $this->logger->warning('reconcile_pending.retrieve_failed', [
                    'order_id' => $orderId,
                    'provider_order_ref' => $providerOrderRef,
                    'error' => $e->getMessage(),
                    'kind' => $e->kind,
                ]);
                $errors++;
                continue;
            }

            $outcome = $this->applyAuthoritativeStatus($order, $status, $dryRun);
            $io->writeln(sprintf(
                '  Order #%d (%s) → Noon says <info>%s</info> (terminal=%s, paid=%s) → %s',
                $orderId ?? 0,
                $reference,
                $status->status,
                $status->terminal ? 'yes' : 'no',
                $status->paid ? 'yes' : 'no',
                $outcome,
            ));

            switch ($outcome) {
                case 'marked_paid':
                    $paid++;
                    $resolved++;
                    break;
                case 'marked_failed':
                    $failed++;
                    $resolved++;
                    break;
                case 'still_transient':
                    $deferred++;
                    break;
            }
        }

        // Persist all changes in one flush — keeps the cron's DB churn
        // bounded. markPaid/markFailed are idempotent so re-running
        // is safe.
        if (!$dryRun && $resolved > 0) {
            try {
                $this->em->flush();
            } catch (\Throwable $e) {
                $io->error('Flush failed: ' . $e->getMessage());
                $this->logger->error('reconcile_pending.flush_failed', [
                    'error' => $e->getMessage(),
                ]);
                return Command::FAILURE;
            }
        }

        $io->newLine();
        $io->writeln(sprintf(
            'Summary: <info>%d</info> resolved (<info>%d</info> paid + <info>%d</info> failed), '
            . '<comment>%d</comment> still transient, <comment>%d</comment> errors%s',
            $resolved,
            $paid,
            $failed,
            $deferred,
            $errors,
            $dryRun ? ' [DRY RUN — no changes persisted]' : '',
        ));

        return Command::SUCCESS;
    }

    /**
     * Apply Noon's authoritative status to the order.
     *
     * Returns a short outcome label for the caller's reporting.
     * Same state machine as NoonWebhookController:
     *   terminal + paid    → markPaid()
     *   terminal + !paid   → markFailed()
     *   !terminal          → no-op (will retry next run)
     *
     * markPaid/markFailed are idempotent at the entity level —
     * calling them on an already-paid/failed order is a no-op,
     * not an error.
     */
    private function applyAuthoritativeStatus(
        Order $order,
        OrderStatusResponse $status,
        bool $dryRun,
    ): string {
        if (!$status->terminal) {
            return 'still_transient';
        }

        if ($status->paid) {
            if ($dryRun) {
                return 'would_mark_paid';
            }
            try {
                $order->markPaid();
            } catch (\Throwable $e) {
                $this->logger->warning('reconcile_pending.mark_paid_failed', [
                    'order_id' => $order->getId(),
                    'error' => $e->getMessage(),
                ]);
                return 'mark_paid_error';
            }
            return 'marked_paid';
        }

        if ($dryRun) {
            return 'would_mark_failed';
        }
        try {
            $order->markFailed();
        } catch (\Throwable $e) {
            $this->logger->warning('reconcile_pending.mark_failed_failed', [
                'order_id' => $order->getId(),
                'error' => $e->getMessage(),
            ]);
            return 'mark_failed_error';
        }
        return 'marked_failed';
    }
}
