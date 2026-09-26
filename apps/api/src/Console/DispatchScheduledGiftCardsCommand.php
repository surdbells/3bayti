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
 * Each card is processed in its own try/catch, one card's failure
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

        // The cron APPENDS this command's output to a shared log file
        // (>> var/logs/gift-cards-dispatch.log), so every run must be
        // self-delimiting and carry its own UTC timestamp — otherwise the log
        // is an undated wall of text and you cannot tell one run from the next
        // (the very thing the operator hit: "the log just says it ran").
        $io->writeln(sprintf(
            '=== gift-cards:dispatch-scheduled @ %s%s (batch %d) ===',
            $now->format(DateTimeImmutable::ATOM),
            $dryRun ? ' [DRY RUN]' : '',
            $batchSize,
        ));

        /** @var GiftCardRepository $repo */
        $repo = $this->em->getRepository(GiftCard::class);
        // Gate the SMS channel: with no real SMS provider wired, a phone-only
        // (or already-emailed two-channel) card would otherwise be re-fetched
        // as "due" on every run forever and never actually delivered.
        $smsEnabled = $this->deliveryService->isSmsEnabled();
        $io->writeln(sprintf('SMS channel: %s', $smsEnabled ? 'enabled' : 'disabled (email only)'));

        $due = $repo->findDueForDelivery($now, $batchSize, $smsEnabled);
        $totalFound = count($due);
        $io->writeln(sprintf('Found <info>%d</info> gift card(s) due for delivery.', $totalFound));

        if ($dryRun) {
            if ($totalFound > 0) {
                $io->writeln('Due gift card IDs (nothing delivered):');
                $ids = array_map(static fn (GiftCard $c) => (string) $c->getId(), $due);
                $io->writeln('  ' . implode(', ', $ids));
                foreach ($due as $card) {
                    $io->writeln('  ' . $this->describeCard($card));
                }
            }
            $this->reportUndeliverable($io, $repo, $now, $smsEnabled);
            $io->success(sprintf('[DRY RUN] %d gift card(s) would be processed.', $totalFound));
            return Command::SUCCESS;
        }

        if ($totalFound === 0) {
            $io->success('Nothing to deliver.');
        }

        if (!$smsEnabled && $totalFound > 0) {
            $io->writeln('<comment>SMS is not configured; only email is delivered. Phone-only cards are skipped until an SMS provider is enabled.</comment>');
        }

        $processed = 0;   // cards deliver() ran against without throwing
        $emailSent = 0;
        $smsSent = 0;
        $smsSkipped = 0;  // phone channel present but SMS not configured
        $sendFailures = 0; // a channel had a recipient but the send errored
        $errors = 0;      // deliver() itself threw (rare, non-delivery infra)

        foreach ($due as $card) {
            try {
                $report = $this->deliveryService->deliver($card);
                $processed++;

                if ($report['email'] === 'sent') {
                    $emailSent++;
                }
                if ($report['sms'] === 'sent') {
                    $smsSent++;
                }
                if ($report['sms'] === 'skipped_not_configured') {
                    $smsSkipped++;
                }

                // One line per card, EVERY card (delivered or skipped), so the
                // log answers "what happened to which card" at a glance.
                $io->writeln(sprintf(
                    '  #%d %s -> email:%s sms:%s%s',
                    (int) $card->getId(),
                    $card->formattedCode(),
                    $report['email'],
                    $report['sms'],
                    $this->recipientHint($card),
                ));

                if ($report['email'] === 'failed' || $report['sms'] === 'failed') {
                    $sendFailures++;
                    $io->writeln(sprintf(
                        '<error>Gift card #%d send failed (email=%s, sms=%s) — see the application log for the cause.</error>',
                        (int) $card->getId(),
                        $report['email'],
                        $report['sms'],
                    ));
                }
            } catch (\Throwable $e) {
                // deliver() is non-blocking, so reaching here is rare -
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

        if ($totalFound > 0) {
            $io->section('Summary');
            $io->table(
                ['Outcome', 'Count'],
                [
                    ['Found', (string) $totalFound],
                    ['Email sent', (string) $emailSent],
                    ['SMS sent', (string) $smsSent],
                    ['SMS skipped (not configured)', (string) $smsSkipped],
                    ['Send failures', (string) $sendFailures],
                    ['Errors (exceptions)', (string) $errors],
                ],
            );
        }

        // ALWAYS check for scheduled cards whose delivery moment has passed but
        // that we cannot deliver on any channel — the exact silent failure the
        // operator hit. They never appear in "Found N", so surface them as a
        // warning so someone can reach out manually.
        $undeliverable = $this->reportUndeliverable($io, $repo, $now, $smsEnabled);

        $this->logger->info('gift_card.dispatch.batch_complete', [
            'found' => $totalFound,
            'processed' => $processed,
            'email_sent' => $emailSent,
            'sms_sent' => $smsSent,
            'sms_skipped' => $smsSkipped,
            'send_failures' => $sendFailures,
            'errors' => $errors,
            'sms_enabled' => $smsEnabled,
            'undeliverable_scheduled' => count($undeliverable),
            'batch_size' => $batchSize,
        ]);

        // A card whose only pending channel is an unconfigured SMS leg is not
        // a failure — it's expected until SMS is wired. Undeliverable scheduled
        // cards are a WARNING, not a run failure (nothing the cron can do about
        // them). Fail the run only when a real send errored or deliver() threw,
        // so cron alerting stays honest.
        return ($errors === 0 && $sendFailures === 0) ? Command::SUCCESS : Command::FAILURE;
    }

    /**
     * Report scheduled cards that are past due but undeliverable (no channel we
     * can send on). Prints a warning + one line each and returns them so the
     * caller can record the count. Empty result = nothing to warn about.
     *
     * @return list<GiftCard>
     */
    private function reportUndeliverable(SymfonyStyle $io, GiftCardRepository $repo, DateTimeImmutable $now, bool $smsEnabled): array
    {
        $stuck = $repo->findUndeliverableScheduled($now, $smsEnabled);
        if ($stuck === []) {
            return [];
        }

        $io->warning(sprintf(
            '%d scheduled gift card(s) are past their delivery date but have NO deliverable channel. '
            . 'Reach out manually (e.g. admin "Send to recipient" / WhatsApp) or wire the missing channel.',
            count($stuck),
        ));
        foreach ($stuck as $card) {
            $io->writeln(sprintf(
                '  <comment>STUCK</comment> #%d %s — %s',
                (int) $card->getId(),
                $card->formattedCode(),
                $this->undeliverableReason($card, $smsEnabled),
            ));
        }

        return $stuck;
    }

    /** Compact recipient hint for a per-card log line (empty when unknown). */
    private function recipientHint(GiftCard $card): string
    {
        $bits = [];
        $email = $card->effectiveRecipientEmail();
        if ($email !== null && $email !== '') {
            $bits[] = $card->recipientEmailIsFromAccount() ? $email . ' (acct)' : $email;
        }
        $phone = $card->effectiveRecipientPhone();
        if ($phone !== null && $phone !== '') {
            $bits[] = $card->recipientPhoneIsFromAccount() ? $phone . ' (acct)' : $phone;
        }
        return $bits === [] ? '' : ' [to: ' . implode(', ', $bits) . ']';
    }

    /** Human-readable id + code + pending channels, for dry-run / warnings. */
    private function describeCard(GiftCard $card): string
    {
        return sprintf('#%d %s%s', (int) $card->getId(), $card->formattedCode(), $this->recipientHint($card));
    }

    /** Why a stuck card can't be delivered, for the operator warning line. */
    private function undeliverableReason(GiftCard $card, bool $smsEnabled): string
    {
        $hasPhone = $card->effectiveRecipientPhone() !== null && $card->effectiveRecipientPhone() !== '';
        if ($hasPhone && !$smsEnabled) {
            return 'phone-only, SMS not configured (no delivery email on file or via claimed account)';
        }
        return 'no reachable email or phone for the recipient';
    }
}
