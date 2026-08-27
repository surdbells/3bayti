<?php

declare(strict_types=1);

namespace Bayti\Api\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Heal device_tokens rows written with microsecond timestamps.
 *
 * The register() UPSERT briefly wrote its timestamp columns with now(), which
 * stores microsecond precision (e.g. "2026-08-15 17:33:14.295927+02"). Doctrine's
 * DateTimeTzImmutableType hydrates those columns with the format "Y-m-d H:i:sO"
 * (seconds only), so reading such a row threw InvalidFormat, a 500 on any full
 * DeviceToken hydration (findOneByToken, findActiveForUser), surfacing as Sentry
 * PHP-1P. The repository now writes date_trunc('second', now()); this backfill
 * truncates the already-written rows to second precision so they round-trip.
 */
final class Version20260815000002 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Truncate device_tokens timestamps to second precision (heal PHP-1P microsecond rows).';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            UPDATE device_tokens SET
                created_at   = date_trunc('second', created_at),
                updated_at   = date_trunc('second', updated_at),
                last_seen_at = date_trunc('second', last_seen_at)
            WHERE created_at   <> date_trunc('second', created_at)
               OR updated_at   <> date_trunc('second', updated_at)
               OR last_seen_at IS DISTINCT FROM date_trunc('second', last_seen_at)
            SQL);
    }

    public function down(Schema $schema): void
    {
        // Irreversible: truncated sub-second precision cannot be restored. No-op.
    }
}
