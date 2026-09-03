<?php

declare(strict_types=1);

namespace Bayti\Api\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Data cleanup: canonicalise gift_cards.recipient_phone to E.164.
 *
 * Recipient phones were persisted verbatim. A client that concatenated the
 * country code onto a LOCAL number still carrying its national trunk zero
 * ("+971" + "0508816837" → "+9710508816837") stored a number whose stray "0"
 * the SMS gateway would send as part of the subscriber number, and which breaks
 * the admin wa.me deep link. PurchaseGiftCardController now normalises on write
 * and MessageCentralSmsSender normalises defensively on send; this backfills the
 * already-stored rows so their admin display + wa.me link agree too.
 *
 * Safety
 * ------
 *   - Only rows whose canonical form DIFFERS from the stored value are touched.
 *   - A value that yields no usable canonical form (e.g. all-zero garbage) is
 *     LEFT UNCHANGED — the backfill never destroys data, it only improves it.
 *   - Runs inside the migration's transaction (PostgreSQL) with parameterised
 *     updates.
 *
 * The canonicalisation is inlined (rather than importing Domain\Common\
 * PhoneNumber) so the migration stays self-contained and reproducible even if
 * that helper later changes; the algorithm mirrors PhoneNumber::toE164 /
 * UserRepository::phoneMatchCandidates.
 *
 * Irreversible by design: the pre-canonical form carried no extra information,
 * so down() is a no-op.
 */
final class Version20260903000001 extends AbstractMigration
{
    /** GCC dial codes the platform serves. */
    private const GCC_DIAL_CODES = ['971', '966', '965', '974', '973', '968'];

    public function getDescription(): string
    {
        return 'Canonicalise existing gift_cards.recipient_phone values to E.164 (drop stray trunk zero).';
    }

    public function up(Schema $schema): void
    {
        $this->abortIf(
            !$this->connection->getDatabasePlatform() instanceof \Doctrine\DBAL\Platforms\PostgreSQLPlatform,
            'Migration can only be executed safely on PostgreSQL.'
        );

        $rows = $this->connection->fetchAllAssociative(
            "SELECT id, recipient_phone FROM gift_cards WHERE recipient_phone IS NOT NULL AND recipient_phone <> ''"
        );

        $updated = 0;
        foreach ($rows as $row) {
            $current = (string) $row['recipient_phone'];
            $canonical = $this->toE164($current);

            // Skip when there's nothing usable to store, or it already matches.
            if ($canonical === null || $canonical === $current) {
                continue;
            }

            $this->connection->executeStatement(
                'UPDATE gift_cards SET recipient_phone = :phone WHERE id = :id',
                ['phone' => $canonical, 'id' => $row['id']],
            );
            $updated++;
        }

        $this->write(sprintf('Canonicalised %d of %d gift-card recipient phone(s).', $updated, count($rows)));
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
