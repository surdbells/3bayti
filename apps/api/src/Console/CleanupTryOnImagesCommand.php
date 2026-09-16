<?php

declare(strict_types=1);

namespace Bayti\Api\Console;

use Bayti\Api\Domain\Media\ImageStorageService;
use Bayti\Api\Domain\TryOn\TryOnJob;
use Bayti\Api\Domain\TryOn\TryOnJobRepository;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * ai:cleanup-tryon-images — enforce the virtual-try-on retention policy.
 *
 * A try-on result is an image of a real person, so it is not kept forever:
 * generated outputs older than the retention window are deleted from storage
 * and their references cleared (the job row stays as a record; the image simply
 * no longer resolves). This sweeps the generated OUTPUT only; the raw customer
 * photo is deleted at generation time by the worker (and any crash-orphaned raw
 * photo is swept by the ai:process-tryon-jobs worker each minute).
 *
 * Idempotent and batched. Safe to run when the feature is off.
 *
 * Scheduling (aaPanel cron)
 * =========================
 *   php /www/wwwroot/<api>/bin/console ai:cleanup-tryon-images
 * Recommended cadence: daily (off-peak). --days overrides the window;
 * --dry-run reports what would be deleted without touching storage.
 */
#[AsCommand(
    name: 'ai:cleanup-tryon-images',
    description: 'Delete virtual-try-on result images past the retention window',
)]
final class CleanupTryOnImagesCommand extends Command
{
    private const DEFAULT_RETENTION_DAYS = 30;
    private const DEFAULT_BATCH = 500;

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly ImageStorageService $imageStorage,
        private readonly LoggerInterface $logger,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('days', null, InputOption::VALUE_REQUIRED, 'Retention window in days', (string) self::DEFAULT_RETENTION_DAYS)
            ->addOption('batch-size', null, InputOption::VALUE_REQUIRED, 'Max rows this run', (string) self::DEFAULT_BATCH)
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Report without deleting');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $days = max(1, (int) $input->getOption('days'));
        $batchSize = max(1, (int) $input->getOption('batch-size'));
        $dryRun = (bool) $input->getOption('dry-run');

        /** @var TryOnJobRepository $repo */
        $repo = $this->em->getRepository(TryOnJob::class);
        $cutoff = new \DateTimeImmutable(sprintf('-%d days', $days));
        $expired = $repo->findExpiredWithResult($cutoff, $batchSize);

        if ($expired === []) {
            $io->success('No try-on images past the retention window.');
            return Command::SUCCESS;
        }

        if ($dryRun) {
            $io->writeln(sprintf('<info>%d</info> try-on image(s) would be deleted (older than %d days).', count($expired), $days));
            return Command::SUCCESS;
        }

        $deleted = 0;
        foreach ($expired as $job) {
            $path = $job->getResultImagePath();
            try {
                if ($path !== null && $path !== '') {
                    $this->imageStorage->delete($path);
                }
                $job->clearResultImage();
                $deleted++;
            } catch (\Throwable $e) {
                $this->logger->warning('tryon image cleanup failed', [
                    'job_reference' => $job->getJobReference(),
                    'error' => $e->getMessage(),
                ]);
            }
        }
        $this->em->flush();

        $this->logger->info('tryon.cleanup_complete', ['deleted' => $deleted, 'days' => $days]);
        $io->success(sprintf('Deleted %d try-on image(s) older than %d days.', $deleted, $days));
        return Command::SUCCESS;
    }
}
