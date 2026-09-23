<?php

declare(strict_types=1);

namespace Bayti\Api\Console;

use Bayti\Api\Domain\Catalog\Product;
use Bayti\Api\Domain\Following\FollowerProductAlertFinder;
use Bayti\Api\Domain\Following\VendorFollow;
use Bayti\Api\Domain\Following\VendorFollowRepository;
use Bayti\Api\Notification\Push\PushNotificationService;
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
 * Store-follower new-product alert cron.
 *
 * For each newly-published product (FollowerProductAlertFinder), push a
 * "new from a store you follow" notification to the store's followers, then
 * stamp the product followers_notified_at so it never fires again.
 *
 * Ordering: the product is CLAIMED (marked notified + flushed) BEFORE the
 * fan-out, so a crash mid-fan-out or an overlapping run never re-sends to the
 * whole follower list (push fatigue is the bigger risk than a rare miss). The
 * per-store fan-out is paged and hard-capped; a store past the cap is logged,
 * never silently truncated. Per-product failure isolation keeps the batch
 * alive. Schedule every ~15 minutes.
 */
#[AsCommand(
    name: 'stores:send-follower-alerts',
    description: 'Push "new product" alerts to a store\'s followers when it publishes a product',
)]
final class SendFollowerProductAlertsCommand extends Command
{
    /** Max followers pushed per product per run (fan-out safety cap). */
    private const FOLLOWER_FANOUT_CAP = 5000;

    /** Followers fetched per page during the fan-out. */
    private const FOLLOWER_PAGE_SIZE = 500;

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly FollowerProductAlertFinder $finder,
        private readonly PushNotificationService $push,
        private readonly LoggerInterface $logger,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'List due products without notifying anyone')
            ->addOption(
                'batch-size',
                null,
                InputOption::VALUE_REQUIRED,
                'Max products to process this run (default ' . FollowerProductAlertFinder::DEFAULT_BATCH_SIZE . ')',
                (string) FollowerProductAlertFinder::DEFAULT_BATCH_SIZE,
            );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $dryRun = (bool) $input->getOption('dry-run');
        $batchSize = max(1, (int) $input->getOption('batch-size'));

        $io->title('Store-follower new-product alerts' . ($dryRun ? ' [DRY RUN]' : ''));

        $due = $this->finder->findDue($batchSize);
        $total = count($due);
        $io->writeln(sprintf('Found <info>%d</info> newly-published product(s) with pending alerts.', $total));

        if ($total === 0) {
            $io->success('Nothing to send.');
            return Command::SUCCESS;
        }

        if ($dryRun) {
            foreach ($due as $row) {
                $io->writeln(sprintf('  product #%d (vendor #%d)', $row['product_id'], $row['vendor_id']));
            }
            $io->success(sprintf('[DRY RUN] %d product(s) would alert their store followers.', $total));
            return Command::SUCCESS;
        }

        /** @var VendorFollowRepository $followRepo */
        $followRepo = $this->em->getRepository(VendorFollow::class);

        $processed = 0;
        $pushed = 0;
        $errors = 0;

        foreach ($due as $row) {
            try {
                $product = $this->em->find(Product::class, $row['product_id']);
                if (!$product instanceof Product) {
                    continue;
                }
                $vendor = $product->getVendor();

                // CLAIM the product first (idempotent against concurrent runs /
                // a crash mid-fan-out), THEN fan out — better a rare miss than
                // re-pushing to every follower.
                $product->markFollowersNotified(new DateTimeImmutable('now', new DateTimeZone('UTC')));
                $this->em->flush();

                $followerCount = $followRepo->countFollowersOfVendor($vendor);
                $cap = min($followerCount, self::FOLLOWER_FANOUT_CAP);
                $sent = 0;
                for ($offset = 0; $offset < $cap; $offset += self::FOLLOWER_PAGE_SIZE) {
                    $followers = $followRepo->findFollowersOfVendor($vendor, self::FOLLOWER_PAGE_SIZE, $offset);
                    if ($followers === []) {
                        break;
                    }
                    foreach ($followers as $follower) {
                        $this->push->newProductFromFollowedStore($follower, $vendor, $product);
                        $sent++;
                        if ($sent >= self::FOLLOWER_FANOUT_CAP) {
                            break 2;
                        }
                    }
                }

                if ($followerCount > self::FOLLOWER_FANOUT_CAP) {
                    // No silent truncation: record that some followers were skipped.
                    $io->writeln(sprintf(
                        '<comment>Product #%d: capped fan-out at %d of %d followers.</comment>',
                        $row['product_id'],
                        self::FOLLOWER_FANOUT_CAP,
                        $followerCount,
                    ));
                    $this->logger->warning('store.follower_alert.capped', [
                        'product_id' => $row['product_id'],
                        'vendor_id' => $vendor->getId(),
                        'cap' => self::FOLLOWER_FANOUT_CAP,
                        'followers' => $followerCount,
                    ]);
                }

                $pushed += $sent;
                $processed++;
            } catch (\Throwable $e) {
                $errors++;
                $io->writeln(sprintf('<error>Product #%d failed: %s</error>', $row['product_id'], $e->getMessage()));
                $this->logger->error('store.follower_alert.failed', [
                    'product_id' => $row['product_id'],
                    'error' => $e->getMessage(),
                    'class' => $e::class,
                ]);
            }
        }

        $io->section('Summary');
        $io->table(['Outcome', 'Count'], [
            ['Products found', (string) $total],
            ['Products processed', (string) $processed],
            ['Follower pushes sent', (string) $pushed],
            ['Errors', (string) $errors],
        ]);

        $this->logger->info('store.follower_alert.batch_complete', [
            'found' => $total,
            'processed' => $processed,
            'pushes' => $pushed,
            'errors' => $errors,
        ]);

        return $errors === 0 ? Command::SUCCESS : Command::FAILURE;
    }
}
