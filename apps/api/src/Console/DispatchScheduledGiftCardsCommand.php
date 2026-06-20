<?php

declare(strict_types=1);

namespace Bayti\Api\Console;

use Bayti\Api\Domain\GiftCard\GiftCard;
use Bayti\Api\Domain\GiftCard\GiftCardRepository;
use Bayti\Api\Notification\GiftCardDeliveryService;
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
 * Dispatch due gift-card recipient deliveries (email + SMS).
 *
 * Why this exists
 * ===============
 * Gift cards may carry a future scheduled_delivery_at (e.g. "send on
 * the recipient's birthday"). The activation webhook only delivers
 * cards whose schedule is null/already-due; future-dated cards wait
 * for this cron.
 *
 * It ALSO acts as a safety net: a card whose immediate (on-activation)
 * delivery failed leaves its *_delivered_at timestamp null, so it
 * remains "due" and is retried here on the next run.
 *
 * What it does
 * ============
 *   1. GiftCardRepository::findDueForDelivery(now, batchSize) selects
 *      active/partially_used cards with a pending email or SMS channel
 *      whose schedule is null or has arrived.
 *   2. For each card, GiftCardDeliveryService::deliver() renders +
 *      sends the pending channel(s), marks each delivered, and flushes.
 *   3. Reports sent/processed/error counts for cron observability.
 *
 * Idempotency
 * ===========
 * Re-running is safe. deliver()'s needs* guards + the *_delivered_at
 * timestamps prevent any double-send; a card with both channels
 * delivered no longer appears in findDueForDelivery.
 *
 * Failure isolation
 * =================
 * Each card is processed in its own try/catch — one card's failure
 * never aborts the batch. deliver() is itself non-blocking, so this is
 * belt-and-braces against unexpected repository errors.
 *
 * Run hourly (or every 15 minutes for tighter scheduled-delivery
 * granularity). See the operator runbook for the crontab line.
 */
#[AsCommand(
    name: 'gift-cards:dispatch-scheduled',
    description: 'Deliver due gift cards to recipients by email and/or SMS',
)]
final class DispatchScheduledGiftCardsCommand extends Command
{
    private const DEFAULT_BATCH_SIZE = 100;
    private const MAX_BATCH_SIZE = 500;

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly GiftCardDeliveryService $deliveryService,
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
                'List due gift cards without delivering them',
            )
            ->addOption(
                'batch-size',
                null,
                InputOption::VALUE_REQUIRED,
                'Max gift cards to process this run (default ' . self::DEFAULT_BATCH_SIZE . ', max ' . self::MAX_BATCH_SIZE . ')',
                (string) self::DEFAULT_BATCH_SIZE,
            );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $dryRun = (bool) $input->getOption('dry-run');
        $batchSize = max(1, min(self::MAX_BATCH_SIZE, (int) $input->getOption('batch-size')));

        $now = new DateTimeImmutable('now', new DateTimeZone('UTC'));

        $io->title(sprintf(
            'Dispatching scheduled gift cards (batch %d)%s',
            $batchSize,
            $dryRun ? ' [DRY RUN]' : '',
        ));

        /** @var GiftCardRepository $repo */
        $repo = $this->em->getRepository(GiftCard::class);
        $due = $repo->findDueForDelivery($now, $batchSize);

        $totalFound = count($due);
        $io->writeln(sprintf('Found <info>%d</info> gift card(s) due for delivery.', $totalFound));

        if ($totalFound === 0) {
            $io->success('Nothing to deliver.');
            return Command::SUCCESS;
        }

        if ($dryRun) {
            $io->writeln('');
            $io->writeln('Due gift card IDs (nothing delivered):');
            $ids = array_map(static fn (GiftCard $c) => (string) $c->getId(), $due);
            $io->writeln('  ' . implode(', ', $ids));
            $io->success(sprintf('[DRY RUN] %d gift card(s) would be processed.', $totalFound));
            return Command::SUCCESS;
        }

        $processed = 0;
        $errors = 0;

        foreach ($due as $card) {
            try {
                $this->deliveryService->deliver($card);
                $processed++;
            } catch (\Throwable $e) {
                // deliver() is non-blocking, so reaching here is rare —
                // but a per-card guard keeps the batch alive regardless.
                $errors++;
                $io->writeln(sprintf(
                    '<error>Gift card #%d failed: %s</error>',
                    (int) $card->getId(),
                    $e->getMessage(),
                ));
                $this->logger->error('gift_card.dispatch.card_failed', [
                    'gift_card_id' => $card->getId(),
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

        $this->logger->info('gift_card.dispatch.batch_complete', [
            'found' => $totalFound,
            'processed' => $processed,
            'errors' => $errors,
            'batch_size' => $batchSize,
        ]);

        return $errors === 0 ? Command::SUCCESS : Command::FAILURE;
    }
}
