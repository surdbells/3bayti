<?php

declare(strict_types=1);

namespace Bayti\Api\Console;

use Bayti\Api\Domain\Notification\NotificationBroadcast;
use Bayti\Api\Domain\Notification\NotificationSchedule;
use Bayti\Api\Domain\Notification\NotificationScheduleRepository;
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
 * Emit due scheduled / recurring notifications.
 *
 * For each schedule whose next_run_at has passed, creates a QUEUED broadcast
 * (which notifications:dispatch-broadcasts then sends, resolving variables +
 * the current audience at that moment — dynamic audience), then advances the
 * schedule to its next occurrence (or completes it). Run every minute:
 *
 *   * * * * *  cd /path/to/api && php bin/console notifications:dispatch-scheduled >> var/log/notif.log 2>&1
 *
 * Each schedule is claimed FOR UPDATE SKIP LOCKED inside a transaction and
 * advanced before commit, so overlapping runs never double-emit and a lagging
 * cron skips missed occurrences rather than flooding.
 */
#[AsCommand(
    name: 'notifications:dispatch-scheduled',
    description: 'Emit due scheduled/recurring notifications as queued broadcasts',
)]
final class DispatchScheduledNotificationsCommand extends Command
{
    private const DEFAULT_LIMIT = 100;

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly LoggerInterface $logger,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('limit', null, InputOption::VALUE_REQUIRED, 'Max schedules to process this run', (string) self::DEFAULT_LIMIT)
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'List due schedules without emitting');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $limit = max(1, (int) $input->getOption('limit'));
        $dryRun = (bool) $input->getOption('dry-run');
        $now = new DateTimeImmutable('now', new DateTimeZone('UTC'));

        /** @var NotificationScheduleRepository $repo */
        $repo = $this->em->getRepository(NotificationSchedule::class);

        if ($dryRun) {
            $due = $repo->findForList(['status' => NotificationSchedule::STATUS_SCHEDULED, 'limit' => $limit]);
            $count = 0;
            foreach ($due['items'] as $s) {
                if ($s->getNextRunAt() !== null && $s->getNextRunAt() <= $now) {
                    $io->writeln(sprintf('  #%d "%s" due %s', (int) $s->getId(), (string) ($s->getName() ?? $s->getTitle()), $s->getNextRunAt()->format('c')));
                    $count++;
                }
            }
            $io->success(sprintf('[DRY RUN] %d schedule(s) due.', $count));
            return Command::SUCCESS;
        }

        $conn = $this->em->getConnection();
        $emitted = 0;
        $errors = 0;

        while ($emitted < $limit) {
            $conn->beginTransaction();
            try {
                $id = $repo->claimDueId($now);
                if ($id === null) {
                    $conn->rollBack();
                    break;
                }

                $schedule = $this->em->find(NotificationSchedule::class, $id);
                if (!$schedule instanceof NotificationSchedule) {
                    $conn->rollBack();
                    continue;
                }

                $broadcast = new NotificationBroadcast(
                    title: $schedule->getTitle(),
                    body: $schedule->getBody(),
                    audience: $schedule->getAudience(),
                    sentByUserId: $schedule->getCreatedByUserId(),
                    imageUrl: $schedule->getImageUrl(),
                    deepLink: $schedule->getDeepLink(),
                    data: $schedule->getData(),
                );
                $broadcast->setTemplateId($schedule->getTemplateId());
                $broadcast->setScheduleId($schedule->getId());
                $this->em->persist($broadcast);

                $schedule->advanceAfterRun($now);
                $this->em->flush();
                $conn->commit();

                $emitted++;
                $io->writeln(sprintf('  #%d "%s" → broadcast queued; next run %s', $id, (string) ($schedule->getName() ?? $schedule->getTitle()), $schedule->getNextRunAt()?->format('c') ?? 'none (completed)'));
            } catch (\Throwable $e) {
                if ($conn->isTransactionActive()) {
                    $conn->rollBack();
                }
                $errors++;
                $this->logger->error('scheduled notification dispatch failed', [
                    'error' => $e->getMessage(),
                ]);
                // Avoid a tight error loop on a persistently bad row.
                break;
            }
        }

        $io->success(sprintf('Emitted %d broadcast(s) from schedules, %d error(s).', $emitted, $errors));
        return $errors > 0 ? Command::FAILURE : Command::SUCCESS;
    }
}
