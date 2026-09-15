<?php

declare(strict_types=1);

namespace Bayti\Api\Console;

use Bayti\Api\Domain\GiftReminder\GiftReminder;
use Bayti\Api\Domain\GiftReminder\GiftReminderDispatchFinder;
use Bayti\Api\Notification\GiftReminderMailer;
use Bayti\Api\Notification\Push\PushNotificationService;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Gift-reminder nudge cron. Finds reminders due for a 14/7/2-day nudge, sends
 * push + email, and advances each reminder's last_notified_stage (the
 * idempotency marker) so the same stage never fires twice. Idempotent, bounded,
 * per-row failure-isolated. Schedule once daily (early AM UAE time).
 */
#[AsCommand(
    name: 'gift-reminders:dispatch',
    description: 'Send push + email nudges for gift reminders due at 14/7/2 days out',
)]
final class SendGiftRemindersCommand extends Command
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly GiftReminderDispatchFinder $finder,
        private readonly PushNotificationService $push,
        private readonly GiftReminderMailer $mailer,
        private readonly LoggerInterface $logger,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'List due reminders without sending anything')
            ->addOption(
                'batch-size',
                null,
                InputOption::VALUE_REQUIRED,
                'Max reminders to process this run (default ' . GiftReminderDispatchFinder::DEFAULT_BATCH_SIZE . ')',
                (string) GiftReminderDispatchFinder::DEFAULT_BATCH_SIZE,
            );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $dryRun = (bool) $input->getOption('dry-run');
        $batchSize = max(1, (int) $input->getOption('batch-size'));

        $io->title('Gift-reminder nudges' . ($dryRun ? ' [DRY RUN]' : ''));

        $due = $this->finder->findDue($batchSize);
        $total = count($due);
        $io->writeln(sprintf('Found <info>%d</info> reminder(s) due.', $total));

        if ($total === 0) {
            $io->success('Nothing to send.');
            return Command::SUCCESS;
        }

        if ($dryRun) {
            foreach ($due as $row) {
                $io->writeln(sprintf('  reminder #%d → stage %d', $row['id'], $row['stage']));
            }
            $io->success(sprintf('[DRY RUN] %d reminder(s) would be nudged.', $total));
            return Command::SUCCESS;
        }

        $processed = 0;
        $errors = 0;

        foreach ($due as $row) {
            try {
                $reminder = $this->em->find(GiftReminder::class, $row['id']);
                if (!$reminder instanceof GiftReminder) {
                    continue;
                }
                $stage = $row['stage'];

                $this->push->giftReminderNudge($reminder, $stage);
                $this->mailer->sendNudge($reminder->getUser(), $reminder, $stage);

                $reminder->markStageNotified($stage);
                $this->em->flush();
                $processed++;
            } catch (\Throwable $e) {
                $errors++;
                $io->writeln(sprintf('<error>Reminder #%d failed: %s</error>', $row['id'], $e->getMessage()));
                $this->logger->error('gift_reminder.dispatch_failed', [
                    'gift_reminder_id' => $row['id'],
                    'error' => $e->getMessage(),
                ]);
            }
        }

        $io->section('Summary');
        $io->table(['Outcome', 'Count'], [
            ['Found', (string) $total],
            ['Processed', (string) $processed],
            ['Errors', (string) $errors],
        ]);

        $this->logger->info('gift_reminder.batch_complete', [
            'found' => $total,
            'processed' => $processed,
            'errors' => $errors,
        ]);

        return $errors === 0 ? Command::SUCCESS : Command::FAILURE;
    }
}
