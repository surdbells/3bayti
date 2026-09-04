<?php

declare(strict_types=1);

namespace Bayti\Api\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Data cleanup: canonicalise vendors.contact_phone to E.164.
 *
 * Store contact phones were persisted verbatim, so a locally-entered UAE number
 * ("0552900789") was stored as-is. The admin vendor-edit form re-submits that
 * value and it failed the strict E.164 assertion — blocking the save of ANY
 * field (even just the email). The input DTOs now canonicalise on write; this
 * backfills the already-stored rows so existing vendors edit cleanly without an
 * admin having to re-save each one first.
 *
 * Safety (mirrors the gift-card recipient_phone backfill):
 *   - Only rows whose canonical form DIFFERS are touched.
 *   - A value that yields no usable canonical form is LEFT UNCHANGED — the
 *     backfill never destroys data, it only improves it.
 *   - Runs inside the migration's PostgreSQL transaction, parameterised.
 *
 * The canonicalisation is inlined (rather than importing Domain\Common\
 * PhoneNumber) so the migration is self-contained; the algorithm mirrors
 * PhoneNumber::toE164.
 *
 * Irreversible by design: the pre-canonical form held no extra information, so
 * down() is a no-op.
 */
final class Version20260904000001 extends AbstractMigration
{
    /** GCC dial codes the platform serves. */
    private const GCC_DIAL_CODES = ['971', '966', '965', '974', '973', '968'];

    public function getDescription(): string
    {
        return 'Canonicalise existing vendors.contact_phone values to E.164 (drop stray trunk zero).';
    }

    public function up(Schema $schema): void
    {
        $this->abortIf(
            !$this->connection->getDatabasePlatform() instanceof \Doctrine\DBAL\Platforms\PostgreSQLPlatform,
            'Migration can only be executed safely on PostgreSQL.'
        );

        $rows = $this->connection->fetchAllAssociative(
            "SELECT id, contact_phone FROM vendors WHERE contact_phone IS NOT NULL AND contact_phone <> ''"
        );

        $updated = 0;
        foreach ($rows as $row) {
            $current = (string) $row['contact_phone'];
            $canonical = $this->toE164($current);

            if ($canonical === null || $canonical === $current) {
                continue;
            }

            $this->connection->executeStatement(
                'UPDATE vendors SET contact_phone = :phone WHERE id = :id',
                ['phone' => $canonical, 'id' => $row['id']],
            );
            $updated++;
        }

        $this->write(sprintf('Canonicalised %d of %d vendor contact phone(s).', $updated, count($rows)));
    }

    public function down(Schema $schema): void
    {
        // No-op: the pre-canonical form held no extra information; there is
        // nothing meaningful to restore. Doctrine requires the method to exist.
    }

    /**
     * Canonicalise a raw phone to E.164, or null when it carries no usable
     * digits. Mirrors Domain\Common\PhoneNumber::toE164 (kept inline so the
     * migration is self-contained).
     */
    private function toE164(string $raw): ?string
    {
        $digits = preg_replace('/\D/', '', $raw) ?? '';
        if ($digits === '') {
            return null;
        }
        if (str_starts_with($digits, '00')) {
            $digits = substr($digits, 2);
        }

        $dial = null;
        $national = $digits;
        foreach (self::GCC_DIAL_CODES as $dc) {
            if (str_starts_with($digits, $dc) && strlen($digits) - strlen($dc) >= 6) {
                $dial = $dc;
                $national = substr($digits, strlen($dc));
                break;
            }
        }
        if ($dial === null) {
            $dial = '971';
        }

        $national = ltrim($national, '0');
        if ($national === '') {
            return null;
        }

        return '+' . $dial . $national;
    }
}
