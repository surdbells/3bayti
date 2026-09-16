<?php

declare(strict_types=1);

namespace Bayti\Api\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Virtual Try-On (Phase 4, feature 1).
 *
 * `tryon_jobs` is the async generation queue: POST /v3/ai/try-on enqueues a row,
 * the `ai:process-tryon-jobs` cron claims it (FOR UPDATE SKIP LOCKED), calls the
 * image model, stores the result and finalises status; the customer polls
 * GET /v3/ai/try-on/{reference} by the unguessable job_reference. `source_image_path`
 * is the PRIVATE key of the uploaded photo (deleted once processing finishes);
 * `result_image_url`/`result_image_path` hold the generated image (retained per the
 * TTL sweep + account-deletion hook).
 *
 * `users.tryon_consent_granted_at` is the durable consent record — a person's photo
 * is processed by a third-party image model, so try-on refuses until it is set.
 */
final class Version20260916000004 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add tryon_jobs queue table and users.tryon_consent_granted_at consent column.';
    }

    public function up(Schema $schema): void
    {
        $this->abortIf(
            !$this->connection->getDatabasePlatform() instanceof \Doctrine\DBAL\Platforms\PostgreSQLPlatform,
            'Migration can only be executed safely on PostgreSQL.'
        );

        $this->addSql(<<<SQL
            CREATE TABLE tryon_jobs (
                id BIGSERIAL PRIMARY KEY,
                job_reference VARCHAR(32) NOT NULL,
                user_id BIGINT NOT NULL REFERENCES users(id) ON DELETE CASCADE,
                product_id BIGINT NOT NULL REFERENCES products(id) ON DELETE CASCADE,
                source_image_path VARCHAR(1000) NOT NULL,
                result_image_url VARCHAR(1000) DEFAULT NULL,
                result_image_path VARCHAR(1000) DEFAULT NULL,
                status VARCHAR(24) NOT NULL DEFAULT 'queued',
                attempts INT NOT NULL DEFAULT 0,
                error_sample TEXT DEFAULT NULL,
                started_at TIMESTAMP(0) WITH TIME ZONE DEFAULT NULL,
                finished_at TIMESTAMP(0) WITH TIME ZONE DEFAULT NULL,
                created_at TIMESTAMP(0) WITH TIME ZONE NOT NULL,
                updated_at TIMESTAMP(0) WITH TIME ZONE NOT NULL
            )
            SQL);

        $this->addSql('CREATE UNIQUE INDEX uniq_tryon_jobs_reference ON tryon_jobs (job_reference)');
        $this->addSql('CREATE INDEX idx_tryon_jobs_status ON tryon_jobs (status)');
        $this->addSql('CREATE INDEX idx_tryon_jobs_status_created ON tryon_jobs (status, created_at)');
        $this->addSql('CREATE INDEX idx_tryon_jobs_user ON tryon_jobs (user_id)');

        $this->addSql('ALTER TABLE users ADD COLUMN tryon_consent_granted_at TIMESTAMP(0) WITH TIME ZONE DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE users DROP COLUMN IF EXISTS tryon_consent_granted_at');
        $this->addSql('DROP TABLE IF EXISTS tryon_jobs');
    }
}
