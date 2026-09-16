<?php

declare(strict_types=1);

namespace Bayti\Api\Console;

use Bayti\Api\Domain\TryOn\TryOnJob;
use Bayti\Api\Domain\TryOn\TryOnJobProcessor;
use Bayti\Api\Domain\TryOn\TryOnJobRepository;
use Bayti\Api\Domain\TryOn\TryOnPhotoStorage;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * ai:process-tryon-jobs — generate queued virtual-try-on images in the
 * background. POST /v3/ai/try-on only enqueues a job (the image-model call is
 * multi-second and must not block the request, nor depend on the customer
 * keeping the app open); this worker does the generation and the customer polls
 * GET /v3/ai/try-on/{reference} for the result.
 *
 * Each job is claimed atomically (FOR UPDATE SKIP LOCKED), so overlapping runs
 * never process the same one twice. A job that throws mid-generation is marked
 * 'failed' rather than left stuck in 'processing'; a stuck-'processing' reaper
 * runs first to release jobs whose worker crashed or whose image call hung.
 * Because a hard crash (OOM/SIGKILL) skips the processor's photo-cleanup, this
 * command also sweeps the raw photos of already-terminal jobs so a person's
 * uploaded photo is never retained.
 *
 * Scheduling (aaPanel cron)
 * =========================
 *   * * * * *  cd /www/wwwroot/<api> && php bin/console ai:process-tryon-jobs >> var/log/tryon.log 2>&1
 * Recommended cadence: every minute. Only runs work when TRYON_ENABLED is on;
 * otherwise each job fails fast as "unavailable". --dry-run lists the queue.
 */
#[AsCommand(
    name: 'ai:process-tryon-jobs',
    description: 'Generate queued virtual-try-on images',
)]
final class ProcessTryOnJobsCommand extends Command
{
    private const DEFAULT_LIMIT = 20;

    /** Floor for the stuck-'processing' reaper window, in seconds. */
    private const STUCK_FLOOR_SECONDS = 600;

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly TryOnJobProcessor $processor,
        private readonly TryOnPhotoStorage $photos,
        private readonly LoggerInterface $logger,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('limit', null, InputOption::VALUE_REQUIRED, 'Max jobs to process this run', (string) self::DEFAULT_LIMIT)
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'List queued jobs without generating');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $limit = max(1, (int) $input->getOption('limit'));
        $dryRun = (bool) $input->getOption('dry-run');

        /** @var TryOnJobRepository $repo */
        $repo = $this->em->getRepository(TryOnJob::class);

        if ($dryRun) {
            $queued = $repo->findBy(['status' => TryOnJob::STATUS_QUEUED], ['id' => 'ASC'], $limit);
            $io->writeln(sprintf('<info>%d</info> queued try-on job(s).', count($queued)));
            foreach ($queued as $job) {
                $io->writeln(sprintf('  %s — product #%d, user #%d', $job->getJobReference(), $job->getProductId(), $job->getUserId()));
            }
            return Command::SUCCESS;
        }

        /* Release jobs whose worker died mid-generation before claiming new
           ones. Derive the window from the provider timeout so an operator who
           raises AI_TRYON_TIMEOUT can't silently make the reaper fire while a
           generation is legitimately in flight. */
        $stuckSeconds = max(self::STUCK_FLOOR_SECONDS, 3 * (int) ($_ENV['AI_TRYON_TIMEOUT'] ?? 120));
        $reaped = $repo->failStuckProcessing($stuckSeconds);
        if ($reaped > 0) {
            $io->writeln(sprintf('<comment>Reaped %d stuck job(s).</comment>', $reaped));
        }

        /* Delete raw photos left behind by hard crashes (the processor's
           finally never ran, so the reaper marked the job terminal but the
           private photo file survives). Honour the "delete on finish" guarantee. */
        $swept = $this->sweepOrphanedSourcePhotos($repo);
        if ($swept > 0) {
            $io->writeln(sprintf('<comment>Cleaned %d orphaned photo(s).</comment>', $swept));
        }

        $processed = 0;
        $errors = 0;

        while ($processed < $limit) {
            $id = $repo->claimNextQueuedId();
            if ($id === null) {
                break;
            }

            $job = $repo->find($id);
            if (!$job instanceof TryOnJob) {
                continue;
            }

            try {
                $this->processor->process($job);
                $processed++;
                $io->writeln(sprintf('  %s — [%s]', $job->getJobReference(), $job->getStatus()));
            } catch (\Throwable $e) {
                $errors++;
                $this->logger->error('tryon dispatch failed', [
                    'job_id' => $id,
                    'error' => $e->getMessage(),
                ]);

                if (!$this->em->isOpen()) {
                    /* A flush failure closed the EM; every further flush() now
                       throws and results would be silently lost. Release the
                       just-claimed row via the still-open DBAL connection and
                       stop — the next cron tick gets a fresh EM. */
                    try {
                        $this->em->getConnection()->executeStatement(
                            "UPDATE tryon_jobs SET status = 'queued', started_at = NULL, updated_at = now() WHERE id = :id AND status = 'processing'",
                            ['id' => $id],
                        );
                    } catch (\Throwable) {
                        // connection itself is gone; the reaper will release it
                    }
                    $io->warning('EntityManager closed after a flush failure; re-queued the job and stopped the run.');
                    break;
                }

                /* Store a generic customer-facing message — never the raw
                   exception text (it can embed storage paths / DB detail). The
                   real cause is in the log above. */
                try {
                    $job->failWith('Try-on could not be completed. Please try again.');
                    $this->em->flush();
                } catch (\Throwable) {
                    // best-effort; don't let cleanup failure abort the run
                }
            }
        }

        $io->success(sprintf('Processed %d job(s), %d error(s).', $processed, $errors));
        return $errors > 0 ? Command::FAILURE : Command::SUCCESS;
    }

    /**
     * Delete the raw photos of terminal jobs that still hold one (a crashed
     * worker never ran its finally). Returns the number cleaned.
     */
    private function sweepOrphanedSourcePhotos(TryOnJobRepository $repo): int
    {
        $stale = $repo->findTerminalWithSourceFile(200);
        if ($stale === []) {
            return 0;
        }
        $cleaned = 0;
        foreach ($stale as $job) {
            $path = $job->getSourceImagePath();
            if ($path === '') {
                continue;
            }
            try {
                $this->photos->deleteInput($path);
                $job->clearSourceImage();
                $cleaned++;
            } catch (\Throwable $e) {
                $this->logger->warning('tryon orphan photo cleanup failed', [
                    'job' => $job->getJobReference(),
                    'error' => $e->getMessage(),
                ]);
            }
        }
        $this->em->flush();
        return $cleaned;
    }
}
