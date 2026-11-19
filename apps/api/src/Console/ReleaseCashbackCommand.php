<?php

declare(strict_types=1);

namespace Bayti\Api\Console;

use Bayti\Api\Domain\Cashback\CashbackService;
use DateTimeImmutable;
use DateTimeZone;
use Psr\Log\LoggerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Release due cashback awards (cashback programme).
 *
 * A pending award is due when its order is DELIVERED and delivered_at +
 * the award's hold_days has passed (and no return is still open). The
 * command recalculates the amount (cancelled / returned items and partial
 * refunds reduce it), issues the cashback gift card into the customer's
 * wallet and notifies them (push + email). Awards whose order ended
 * cancelled / refunded / failed are cancelled.
 *
 * Safe to re-run and to overlap with an admin "release now": each award is
 * row-locked and re-checked inside its own transaction, so only one card
 * is ever issued per award.
 *
 * Run hourly as www:
 *   su -s /bin/sh www -c 'cd /www/wwwroot/3bayti/apps/api && php bin/console cashback:release'
 */
#[AsCommand(
    name: 'cashback:release',
    description: 'Issue due cashback awards as gift cards and cancel awards whose order died',
)]
final class ReleaseCashbackCommand extends Command
{
    private const DEFAULT_LIMIT = 200;
    private const MAX_LIMIT = 2000;

    public function __construct(
        private readonly CashbackService $cashback,
        private readonly LoggerInterface $logger,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Report what would be issued/cancelled without changing anything')
            ->addOption('limit', null, InputOption::VALUE_REQUIRED, 'Max awards to process this run', (string) self::DEFAULT_LIMIT);
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $dryRun = (bool) $input->getOption('dry-run');
        $limitRaw = $input->getOption('limit');
        $limit = max(1, min(self::MAX_LIMIT, is_numeric($limitRaw) ? (int) $limitRaw : self::DEFAULT_LIMIT));
        $now = new DateTimeImmutable('now', new DateTimeZone('UTC'));

        $io->title(sprintf('Releasing due cashback awards (limit %d)%s', $limit, $dryRun ? ' [DRY RUN]' : ''));

        $stats = $this->cashback->releaseDue(
            $now,
            $limit,
            $dryRun,
            static function (int $awardId, string $outcome) use ($io): void {
                $io->writeln(sprintf('  Award #%d → %s', $awardId, $outcome));
            },
        );

        $io->newLine();
        $io->writeln(sprintf(
            'Summary: <info>%d</info> due, <info>%d</info> issued, <comment>%d</comment> cancelled, %d skipped, <error>%d</error> errors%s',
            $stats['due'],
            $stats['issued'],
            $stats['cancelled'],
            $stats['skipped'],
            $stats['errors'],
            $dryRun ? ' [DRY RUN — nothing changed]' : '',
        ));

        $this->logger->info('cashback.release_run', $stats + ['dry_run' => $dryRun]);

        return $stats['errors'] > 0 ? Command::FAILURE : Command::SUCCESS;
    }
}
