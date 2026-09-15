<?php

declare(strict_types=1);

namespace Bayti\Api\Migrations;

use Bayti\Api\Domain\Authz\PermissionCatalog;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Ain AI concierge — seed the `ai` permission module.
 *
 * Adds `ai.view` / `ai.manage` to the permissions table (idempotent, matching
 * the Version20260614000008 seed) so the admin AI analytics panel can be gated.
 * Grants `ai.view` to the operations + finance system roles and both keys to
 * super_admin, mirroring how those presets are composed in PermissionCatalog.
 */
final class Version20260915000003 extends AbstractMigration
{
    /** Permission keys seeded by this migration. */
    private const KEYS = ['ai.view', 'ai.manage'];

    public function getDescription(): string
    {
        return 'Ain AI: seed ai.view / ai.manage permissions + role grants.';
    }

    public function up(Schema $schema): void
    {
        $this->abortIf(
            !$this->connection->getDatabasePlatform() instanceof \Doctrine\DBAL\Platforms\PostgreSQLPlatform,
            'Migration can only be executed safely on PostgreSQL.'
        );

        $labels = PermissionCatalog::flat();
        foreach (self::KEYS as $key) {
            $this->addSql(
                'INSERT INTO permissions (permission_key, module, label) VALUES (?, ?, ?) ON CONFLICT (permission_key) DO NOTHING',
                [$key, PermissionCatalog::moduleOf($key), $labels[$key] ?? $key],
            );
        }

        // Grant to system roles. super_admin sees everything; operations + finance
        // get the read-only analytics view (finance already reads reports/insights).
        $grants = [
            'super_admin' => ['ai.view', 'ai.manage'],
            'operations' => ['ai.view'],
            'finance' => ['ai.view'],
        ];
        foreach ($grants as $role => $keys) {
            foreach ($keys as $key) {
                $this->addSql(
                    'INSERT INTO role_permission (role_id, permission_id)
                     SELECT r.id, p.id FROM roles r, permissions p
                     WHERE r.slug = ? AND p.permission_key = ?
                     ON CONFLICT DO NOTHING',
                    [$role, $key],
                );
            }
        }
    }

    public function down(Schema $schema): void
    {
        $this->abortIf(
            !$this->connection->getDatabasePlatform() instanceof \Doctrine\DBAL\Platforms\PostgreSQLPlatform,
            'Migration can only be executed safely on PostgreSQL.'
        );

        $this->addSql(
            "DELETE FROM role_permission WHERE permission_id IN (SELECT id FROM permissions WHERE permission_key IN ('ai.view', 'ai.manage'))"
        );
        $this->addSql("DELETE FROM permissions WHERE permission_key IN ('ai.view', 'ai.manage')");
    }
}
