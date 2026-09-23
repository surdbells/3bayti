<?php

declare(strict_types=1);

namespace Bayti\Api\Migrations;

use Bayti\Api\Domain\Authz\PermissionCatalog;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * P9 — seed the `hotlinks` permission module.
 *
 * Adds `hotlinks.view` to the permissions table (idempotent, matching the
 * Version20260614000008 seed) so the admin hotlink-analytics panel can be
 * gated. Grants it to operations + finance and super_admin, mirroring the
 * ai.view seeder (Version20260915000003).
 */
final class Version20260923000006 extends AbstractMigration
{
    /** Permission keys seeded by this migration. */
    private const KEYS = ['hotlinks.view'];

    public function getDescription(): string
    {
        return 'P9: seed hotlinks.view permission + role grants.';
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

        $grants = [
            'super_admin' => ['hotlinks.view'],
            'operations' => ['hotlinks.view'],
            'finance' => ['hotlinks.view'],
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
            "DELETE FROM role_permission WHERE permission_id IN (SELECT id FROM permissions WHERE permission_key IN ('hotlinks.view'))"
        );
        $this->addSql("DELETE FROM permissions WHERE permission_key IN ('hotlinks.view')");
    }
}
