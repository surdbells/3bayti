<?php

declare(strict_types=1);

namespace Bayti\Api\Console;

use Bayti\Api\Domain\User\User;
use Bayti\Api\Domain\User\UserRepository;
use Bayti\Api\Notification\Push\PushNotificationLogger;
use Bayti\Api\Notification\Push\PushNotificationService;
use DateInterval;
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
 * Re-engagement PUSH nudge for lapsed users (balanced cadence).
 *
 * Why this exists
 * ===============
 * Users who haven't opened the app in a couple of weeks are at churn
 * risk. A single, well-spaced "we miss you / new arrivals" push wins
 * a meaningful fraction back without becoming spam.
 *
 * Cadence (balanced)
 * ==================
 *   - lapsed window: last_login_at <= now - 14 days
 *   - nudge cadence: at most one re_engagement.nudge push per user per
 *     14 days (the finder excludes anyone nudged inside that window)
 *   - only users with >= 1 active device token (a push can land)
 *   - only users with marketing_push_opt_out = false (consented)
 *
 * What it does
 * ============
 *   1. UserRepository::findLapsedForReengagement(...) returns eligible
 *      user ids (raw SQL, the cadence guard reads the unmapped
 *      notification_logs.user_id / channel columns).
 *   2. For each, PushNotificationService::reEngagementNudge() fans the
 *      push out (fire-and-forget; re-checks marketing_push_opt_out at
 *      dispatch as defence-in-depth).
 *   3. Logs the attempt to notification_logs with
 *      template='re_engagement.nudge', channel='push', user_id set -
 *      that row enforces the 14-day cadence on the next run.
 *
 * Idempotency / failure isolation
 * ===============================
 * Re-running inside the cadence window finds nothing for already-nudged
 * users. Each user is processed in its own try/catch.
 */
#[AsCommand(
    name: 'users:send-reengagement-nudges',
    description: 'Send re-engagement push nudges to lapsed, opted-in users (balanced cadence)',
)]
final class SendReengagementNudgesCommand extends Command
{
    private const DEFAULT_LAPSED_DAYS = 14;
    private const DEFAULT_CADENCE_DAYS = 14;
    private const MIN_DAYS = 1;
    private const MAX_DAYS = 365;
    private const DEFAULT_BATCH_SIZE = 200;
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
                'List eligible users without sending pushes',
            )
            ->addOption(
                'lapsed-days',
                null,
                InputOption::VALUE_REQUIRED,
                'Consider a user lapsed after this many days since last login (default ' . self::DEFAULT_LAPSED_DAYS . ')',
                (string) self::DEFAULT_LAPSED_DAYS,
            )
            ->addOption(
                'cadence-days',
                null,
                InputOption::VALUE_REQUIRED,
                'Minimum days between nudges to the same user (default ' . self::DEFAULT_CADENCE_DAYS . ')',
                (string) self::DEFAULT_CADENCE_DAYS,
            )
            ->addOption(
                'batch-size',
                null,
                InputOption::VALUE_REQUIRED,
                'Max users to process this run (default ' . self::DEFAULT_BATCH_SIZE . ', max ' . self::MAX_BATCH_SIZE . ')',
                (string) self::DEFAULT_BATCH_SIZE,
            );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $dryRun = (bool) $input->getOption('dry-run');
        $lapsedDays = $this->clamp((int) $input->getOption('lapsed-days'), self::MIN_DAYS, self::MAX_DAYS);
        $cadenceDays = $this->clamp((int) $input->getOption('cadence-days'), self::MIN_DAYS, self::MAX_DAYS);
        $batchSize = max(1, min(self::MAX_BATCH_SIZE, (int) $input->getOption('batch-size')));

        $now = new DateTimeImmutable('now', new DateTimeZone('UTC'));

        $io->title(sprintf(
            'Re-engagement nudges (lapsed %dd, cadence %dd, batch %d)%s',
            $lapsedDays,
            $cadenceDays,
            $batchSize,
            $dryRun ? ' [DRY RUN]' : '',
        ));

        /** @var UserRepository $repo */
        $repo = $this->em->getRepository(User::class);
        $userIds = $repo->findLapsedForReengagement(
            now: $now,
            lapsedThreshold: new DateInterval('P' . $lapsedDays . 'D'),
            cadence: new DateInterval('P' . $cadenceDays . 'D'),
            batchLimit: $batchSize,
        );

        $totalFound = count($userIds);
        $io->writeln(sprintf('Found <info>%d</info> lapsed user(s) to nudge.', $totalFound));

        if ($totalFound === 0) {
            $io->success('Nothing to send.');
            return Command::SUCCESS;
        }

        if ($dryRun) {
            $io->writeln('');
            $io->writeln('Eligible user IDs (no pushes sent):');
            $io->writeln('  ' . implode(', ', array_map(strval(...), $userIds)));
            $io->success(sprintf('[DRY RUN] %d user(s) would be processed.', $totalFound));
            return Command::SUCCESS;
        }

        $cadenceSince = $now->sub(new DateInterval('P' . $cadenceDays . 'D'));

        $processed = 0;
        $errors = 0;

        foreach ($userIds as $userId) {
            try {
                $user = $repo->find($userId);
                if (!$user instanceof User) {
                    $this->logger->info('reengagement.user_disappeared', ['user_id' => $userId]);
                    continue;
                }

                // Belt-and-braces: re-check the cadence guard in case the
                // same id surfaced twice in a huge batch.
                if ($this->pushLog->pushSentForUserSince($userId, 're_engagement.nudge', $cadenceSince)) {
                    continue;
                }

                $this->push->reEngagementNudge($user);

                $this->pushLog->log(
                    template: 're_engagement.nudge',
                    status: PushNotificationLogger::STATUS_SENT,
                    userId: $userId,
                );
                $processed++;
            } catch (\Throwable $e) {
                $errors++;
                $io->writeln(sprintf(
                    '<error>User #%d failed: %s</error>',
                    $userId,
                    $e->getMessage(),
                ));
                $this->logger->error('reengagement.user_failed', [
                    'user_id' => $userId,
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

        $this->logger->info('reengagement.batch_complete', [
            'found' => $totalFound,
            'processed' => $processed,
            'errors' => $errors,
            'lapsed_days' => $lapsedDays,
            'cadence_days' => $cadenceDays,
            'batch_size' => $batchSize,
        ]);

        return $errors === 0 ? Command::SUCCESS : Command::FAILURE;
    }

    private function clamp(int $value, int $min, int $max): int
    {
        return max($min, min($max, $value));
    }
}
