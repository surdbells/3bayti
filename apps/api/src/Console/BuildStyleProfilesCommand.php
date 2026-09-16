<?php

declare(strict_types=1);

namespace Bayti\Api\Console;

use Bayti\Api\Ai\Personalization\CustomerStyleProfileBuilder;
use Bayti\Api\Ai\Personalization\CustomerStyleProfileStore;
use Bayti\Api\Ai\Personalization\CustomerStyleSignals;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * ai:build-style-profiles — (re)compute the Personal Style Profile for every
 * customer with behavioural signals (wishlist / follows / paid orders / product
 * views) into customer_style_profiles, powering the personalised For-You rails.
 *
 * Independent of AI_ENABLED: colours/categories/vendors/budget come straight from
 * the catalogue, so the profiles are useful even before AI enrichment runs (the
 * style/occasion tags simply layer in once ai:build-product-attributes has run).
 * Run nightly via aaPanel cron, like recommendations:build.
 */
#[AsCommand(
    name: 'ai:build-style-profiles',
    description: 'Compute per-customer style profiles for the Ain For-You rails.',
)]
final class BuildStyleProfilesCommand extends Command
{
    private const DEFAULT_BATCH = 200;

    public function __construct(
        private readonly CustomerStyleSignals $signals,
        private readonly CustomerStyleProfileBuilder $builder,
        private readonly CustomerStyleProfileStore $store,
        private readonly EntityManagerInterface $em,
        private readonly LoggerInterface $logger,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('limit', null, InputOption::VALUE_REQUIRED, 'Max customers to process (0 = all).', '0')
            ->addOption('batch', null, InputOption::VALUE_REQUIRED, 'Page size.', (string) self::DEFAULT_BATCH)
            ->addOption('user', null, InputOption::VALUE_REQUIRED, 'Rebuild a single user id only.')
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Compute but do not write.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $limit = max(0, (int) $input->getOption('limit'));
        $batch = max(1, (int) $input->getOption('batch'));
        $dryRun = (bool) $input->getOption('dry-run');
        $singleUser = $input->getOption('user');

        if (is_string($singleUser) && is_numeric($singleUser)) {
            $built = $this->processUser((int) $singleUser, $dryRun);
            $io->success(sprintf('%s: user %d %s.', $dryRun ? 'Dry run' : 'Done', (int) $singleUser, $built ? 'rebuilt' : 'skipped'));
            return Command::SUCCESS;
        }

        $processed = 0;
        $built = 0;
        $offset = 0;

        while (true) {
            $userIds = $this->signals->candidateUserIds($batch, $offset);
            if ($userIds === []) {
                break;
            }
            $offset += count($userIds);

            foreach ($userIds as $userId) {
                if ($this->processUser($userId, $dryRun)) {
                    $built++;
                }
                $processed++;
                if ($limit > 0 && $processed >= $limit) {
                    break 2;
                }
            }

            // Release hydrated products between pages to bound memory.
            $this->em->clear();
        }

        $io->success(sprintf(
            '%s: %d customers processed, %d profiles %s.',
            $dryRun ? 'Dry run' : 'Done',
            $processed,
            $built,
            $dryRun ? 'computed' : 'written',
        ));
        return Command::SUCCESS;
    }

    private function processUser(int $userId, bool $dryRun): bool
    {
        try {
            $profile = $this->builder->build($userId);
            if ($profile->isEmpty()) {
                return false;
            }
            if (!$dryRun) {
                $this->store->upsert($userId, $profile);
            }
            return true;
        } catch (\Throwable $e) {
            $this->logger->error('style_profile.build_failed', ['user_id' => $userId, 'error' => $e->getMessage()]);
            return false;
        }
    }
}
