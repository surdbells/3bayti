<?php

declare(strict_types=1);

namespace Bayti\Api\Console;

use Bayti\Api\Domain\Notification\BroadcastSender;
use Bayti\Api\Domain\Notification\NotificationBroadcast;
use Bayti\Api\Domain\Notification\NotificationBroadcastRepository;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Send queued admin push broadcasts in the background.
 *
 * The compose endpoint queues any broadcast larger than a small inline
 * threshold, so a 12k-device send never blocks the HTTP request and doesn't
 * depend on the admin keeping the tab open. Run this every minute via cron:
 *
 *   * * * * *  cd /path/to/api && php bin/console notifications:dispatch-broadcasts >> var/log/notif.log 2>&1
 *
 * Each broadcast is claimed atomically (FOR UPDATE SKIP LOCKED), so
 * overlapping runs never process the same one twice. A broadcast that throws
 * mid-send is marked 'failed' rather than left stuck in 'processing'.
 */
#[AsCommand(
    name: 'notifications:dispatch-broadcasts',
    description: 'Send queued admin push broadcasts',
)]
final class DispatchBroadcastsCommand extends Command
{
    private const DEFAULT_LIMIT = 20;

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly BroadcastSender $sender,
        private readonly LoggerInterface $logger,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('limit', null, InputOption::VALUE_REQUIRED, 'Max broadcasts to process this run', (string) self::DEFAULT_LIMIT)
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'List queued broadcasts without sending');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $limit = max(1, (int) $input->getOption('limit'));
        $dryRun = (bool) $input->getOption('dry-run');

        /** @var NotificationBroadcastRepository $repo */
        $repo = $this->em->getRepository(NotificationBroadcast::class);

        if ($dryRun) {
            $queued = $repo->findForHistory(['status' => NotificationBroadcast::STATUS_QUEUED, 'limit' => $limit]);
            $io->writeln(sprintf('<info>%d</info> queued broadcast(s).', $queued['total']));
            foreach ($queued['items'] as $b) {
                $io->writeln(sprintf('  #%d "%s"', (int) $b->getId(), $b->getTitle()));
            }
            return Command::SUCCESS;
        }

        $processed = 0;
        $errors = 0;

        while ($processed < $limit) {
            $id = $repo->claimNextQueuedId();
            if ($id === null) {
                break;
            }

            $broadcast = $repo->find($id);
            if (!$broadcast instanceof NotificationBroadcast) {
                continue;
            }

            try {
                $this->sender->process($broadcast);
                $processed++;
                $io->writeln(sprintf(
                    '  #%d "%s" — %d sent, %d failed [%s]',
                    $id,
                    $broadcast->getTitle(),
                    $broadcast->getSentCount(),
                    $broadcast->getFailedCount(),
                    $broadcast->getStatus(),
                ));
            } catch (\Throwable $e) {
                $errors++;
                $this->logger->error('broadcast dispatch failed', [
                    'broadcast_id' => $id,
                    'error' => $e->getMessage(),
                ]);
                try {
                    $broadcast->failWith('Dispatch error: ' . $e->getMessage());
                    $this->em->flush();
                } catch (\Throwable) {
                    // best-effort; don't let cleanup failure abort the run
                }
            }
        }

        $io->success(sprintf('Processed %d broadcast(s), %d error(s).', $processed, $errors));
        return $errors > 0 ? Command::FAILURE : Command::SUCCESS;
    }
}
