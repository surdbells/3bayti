<?php

declare(strict_types=1);

namespace Bayti\Api\Console;

use Bayti\Api\Domain\Catalog\Vendor;
use Bayti\Api\Domain\Compliance\ComplianceDocumentService;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Localize vendor KYC documents held inline in the DB.
 *
 * Why this exists
 * ===============
 * Legacy vendor rows (and the users→vendors backfill,
 * Version20260826000001) hold the ID front/back + trade-licence images as
 * base64 in the vendors.id_front/id_back/license_doc TEXT columns. That
 * bloats row scans and keeps large blobs in Postgres. New uploads already
 * store the bytes as a PRIVATE file and keep only the storage PATH in the
 * column (ComplianceDocumentService::store); this command brings the legacy
 * rows to the same shape.
 *
 * What it does
 * ============
 *   1. Scans vendors that have any non-null KYC column.
 *   2. For each doc, ComplianceDocumentService::localizeInline() stores an
 *      inline base64/data-URL value as a private file and returns the new
 *      path; a value that's already a storage path (or a remote URL) is left
 *      untouched (returns null).
 *   3. Writes the new path back with the plain setter (NOT submitCompliance,
 *      so compliance_status/approval is preserved).
 *
 * Idempotency
 * ===========
 * Re-running is safe: once a column holds a storage path, localizeInline()
 * returns null for it, so it's skipped. Run repeatedly with --limit to work
 * through a large backlog in batches.
 *
 * Failure isolation
 * =================
 * Each vendor is processed in its own try/catch and flushed independently —
 * one oversized/corrupt document never aborts the batch or rolls back others.
 *
 * Options
 * =======
 *   --dry-run   Report what WOULD be localized without storing/writing.
 *   --limit=N   Process at most N vendors this run (0 = all). Default 0.
 */
#[AsCommand(
    name: 'compliance:localize-documents',
    description: 'Move inline base64 vendor KYC documents out of the DB into private storage.',
)]
final class LocalizeComplianceDocumentsCommand extends Command
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly ComplianceDocumentService $docs,
        private readonly LoggerInterface $logger,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Report without storing or writing.')
            ->addOption('limit', null, InputOption::VALUE_REQUIRED, 'Max vendors to process (0 = all).', '0');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $dryRun = (bool) $input->getOption('dry-run');
        $limit = max(0, (int) $input->getOption('limit'));

        /** @var list<Vendor> $vendors */
        $vendors = $this->fetchVendorsWithDocuments($limit);

        $io->title('Localize vendor KYC documents' . ($dryRun ? ' (dry run)' : ''));
        $io->text(sprintf('Scanning %d vendor(s) with at least one document…', count($vendors)));

        $localized = 0;
        $vendorsChanged = 0;
        $errors = 0;

        // front/back/license_doc → the store() "type" segment + entity accessors.
        $fields = [
            ['type' => 'front',   'get' => 'getIdFront',    'set' => 'setIdFront'],
            ['type' => 'back',    'get' => 'getIdBack',     'set' => 'setIdBack'],
            ['type' => 'license', 'get' => 'getLicenseDoc', 'set' => 'setLicenseDoc'],
        ];

        foreach ($vendors as $vendor) {
            $vendorId = (int) $vendor->getId();
            $changed = false;
            try {
                foreach ($fields as $f) {
                    /** @var string|null $current */
                    $current = $vendor->{$f['get']}();
                    if ($dryRun) {
                        // Detect-only: a value that isn't a storage path/URL is inline.
                        if ($this->looksInline($current)) {
                            $localized++;
                            $changed = true;
                        }
                        continue;
                    }
                    $newPath = $this->docs->localizeInline($vendorId, $f['type'], $current);
                    if ($newPath !== null) {
                        $vendor->{$f['set']}($newPath);
                        $localized++;
                        $changed = true;
                    }
                }

                if ($changed) {
                    $vendorsChanged++;
                    if (!$dryRun) {
                        $this->em->flush();
                    }
                }
            } catch (\Throwable $e) {
                $errors++;
                $this->logger->error('compliance.localize.vendor_failed', [
                    'vendor_id' => $vendorId,
                    'error' => $e->getMessage(),
                    'class' => $e::class,
                ]);
                $io->warning(sprintf('Vendor #%d skipped: %s', $vendorId, $e->getMessage()));
            }
        }

        $io->success(sprintf(
            '%s %d document(s) across %d vendor(s)%s.',
            $dryRun ? 'Would localize' : 'Localized',
            $localized,
            $vendorsChanged,
            $errors > 0 ? sprintf(' — %d vendor(s) errored (see logs)', $errors) : '',
        ));

        return $errors > 0 ? Command::FAILURE : Command::SUCCESS;
    }

    /**
     * Vendors with at least one non-null KYC column. Ordered by id for stable,
     * resumable batching with --limit.
     *
     * @return list<Vendor>
     */
    private function fetchVendorsWithDocuments(int $limit): array
    {
        $qb = $this->em->getRepository(Vendor::class)->createQueryBuilder('v')
            ->where('v.idFront IS NOT NULL')
            ->orWhere('v.idBack IS NOT NULL')
            ->orWhere('v.licenseDoc IS NOT NULL')
            ->orderBy('v.id', 'ASC');
        if ($limit > 0) {
            $qb->setMaxResults($limit);
        }

        /** @var list<Vendor> $result */
        $result = $qb->getQuery()->getResult();
        return $result;
    }

    /** True for an inline value (data URL or raw base64) — NOT a path/URL/empty. */
    private function looksInline(?string $value): bool
    {
        if ($value === null || $value === '') {
            return false;
        }
        if (str_starts_with($value, 'http://') || str_starts_with($value, 'https://')) {
            return false;
        }
        if (str_starts_with($value, 'data:')) {
            return true;
        }
        // Raw base64: long + only the base64 alphabet (storage paths have '/-.').
        return strlen($value) >= 100 && preg_match('#^[A-Za-z0-9+/]+={0,2}$#', $value) === 1;
    }
}
