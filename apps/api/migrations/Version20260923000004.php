<?php

declare(strict_types=1);

namespace Bayti\Api\Migrations;

use Bayti\Api\Domain\Chat\PromptCatalog;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Marketplace P1 — chat quick-start prompts. Creates the bilingual prompt
 * catalog tables (customer questions + vendor reply templates), adds a
 * prompt_id reference to chat_messages, and seeds the starter taxonomy from
 * PromptCatalog (idempotent INSERT ... ON CONFLICT, the PermissionCatalog
 * pattern). Purely additive — the free-text chat is unchanged.
 */
final class Version20260923000004 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'P1 — chat_prompt_categories + chat_prompts (+ seed) and chat_messages.prompt_id.';
    }

    public function up(Schema $schema): void
    {
        $this->abortIf(
            !$this->connection->getDatabasePlatform() instanceof \Doctrine\DBAL\Platforms\PostgreSQLPlatform,
            'This migration only supports PostgreSQL.'
        );

        $this->addSql(<<<'SQL'
            CREATE TABLE chat_prompt_categories (
                id         BIGSERIAL   PRIMARY KEY,
                slug       VARCHAR(64) NOT NULL UNIQUE,
                audience   VARCHAR(16) NOT NULL,
                label      VARCHAR(120) NOT NULL,
                label_ar   VARCHAR(120) NOT NULL,
                icon       VARCHAR(64) NULL,
                sort_order INTEGER     NOT NULL DEFAULT 0,
                is_active  BOOLEAN     NOT NULL DEFAULT TRUE
            )
        SQL);
        $this->addSql(
            'ALTER TABLE chat_prompt_categories ADD CONSTRAINT chk_chat_prompt_cat_audience '
            . "CHECK (audience IN ('customer', 'vendor'))"
        );
        $this->addSql('CREATE INDEX idx_chat_prompt_cat_audience ON chat_prompt_categories (audience, is_active, sort_order)');

        $this->addSql(<<<'SQL'
            CREATE TABLE chat_prompts (
                id          BIGSERIAL   PRIMARY KEY,
                category_id BIGINT      NOT NULL
                                REFERENCES chat_prompt_categories(id) ON DELETE CASCADE,
                slug        VARCHAR(96) NOT NULL UNIQUE,
                text        VARCHAR(400) NOT NULL,
                text_ar     VARCHAR(400) NOT NULL,
                sort_order  INTEGER     NOT NULL DEFAULT 0,
                is_active   BOOLEAN     NOT NULL DEFAULT TRUE
            )
        SQL);
        $this->addSql('CREATE INDEX idx_chat_prompt_category ON chat_prompts (category_id, is_active, sort_order)');

        // A prompt-message links back to the catalog prompt it was tapped from.
        $this->addSql('ALTER TABLE chat_messages ADD COLUMN IF NOT EXISTS prompt_id BIGINT DEFAULT NULL');

        // ── Seed the starter taxonomy from PromptCatalog (idempotent) ──
        foreach (PromptCatalog::categories() as $cat) {
            $this->addSql(
                'INSERT INTO chat_prompt_categories (slug, audience, label, label_ar, icon, sort_order) '
                . 'VALUES (?, ?, ?, ?, ?, ?) ON CONFLICT (slug) DO NOTHING',
                [$cat['slug'], $cat['audience'], $cat['label'], $cat['label_ar'], $cat['icon'], $cat['sort']],
            );
            foreach ($cat['prompts'] as $prompt) {
                $this->addSql(
                    'INSERT INTO chat_prompts (category_id, slug, text, text_ar, sort_order) '
                    . 'SELECT c.id, ?, ?, ?, ? FROM chat_prompt_categories c WHERE c.slug = ? '
                    . 'ON CONFLICT (slug) DO NOTHING',
                    [$prompt['slug'], $prompt['text'], $prompt['text_ar'], $prompt['sort'], $cat['slug']],
                );
            }
        }
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE chat_messages DROP COLUMN IF EXISTS prompt_id');
        $this->addSql('DROP TABLE IF EXISTS chat_prompts');
        $this->addSql('DROP TABLE IF EXISTS chat_prompt_categories');
    }
}
