<?php

declare(strict_types=1);

namespace Bayti\Api\Migrations;

use Bayti\Api\Domain\Authz\PermissionCatalog;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Admin vendor password reset — seed the `vendors.reset_password` permission.
 *
 * Gates POST /v3/admin/vendors/{id}/reset-password. Idempotent insert
 * (matching the Version20260915000003 seed) and granted to super_admin only;
 * other roles receive it explicitly through role management since it hands
 * the operator control over a seller's credentials.
 */
final class Version20260929000001 extends AbstractMigration
{
    private const KEY = 'vendors.reset_password';

    public function getDescription(): string
    {
        return 'Seed vendors.reset_password permission + super_admin grant.';
    }

    public function up(Schema $schema): void
    {
        $this->abortIf(
            !$this->connection->getDatabasePlatform() instanceof \Doctrine\DBAL\Platforms\PostgreSQLPlatform,
            'Migration can only be executed safely on PostgreSQL.'
        );

        $labels = PermissionCatalog::flat();
        $this->addSql(
            'INSERT INTO permissions (permission_key, module, label) VALUES (?, ?, ?)
             ON CONFLICT (permission_key) DO NOTHING',
            [self::KEY, PermissionCatalog::moduleOf(self::KEY), $labels[self::KEY] ?? self::KEY],
        );

        $this->addSql(
            'INSERT INTO role_permission (role_id, permission_id)
             SELECT r.id, p.id FROM roles r, permissions p
             WHERE r.slug = ? AND p.permission_key = ?
             ON CONFLICT DO NOTHING',
            ['super_admin', self::KEY],
        );
    }

    public function down(Schema $schema): void
    {
        $this->abortIf(
            !$this->connection->getDatabasePlatform() instanceof \Doctrine\DBAL\Platforms\PostgreSQLPlatform,
            'Migration can only be executed safely on PostgreSQL.'
        );

        $this->addSql(
            'DELETE FROM role_permission WHERE permission_id IN (SELECT id FROM permissions WHERE permission_key = ?)',
            [self::KEY],
        );
        $this->addSql('DELETE FROM permissions WHERE permission_key = ?', [self::KEY]);
    }
}
