<?php

declare(strict_types=1);

namespace Bayti\Api\Migrations;

use Bayti\Api\Domain\Authz\PermissionCatalog;
use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Phase C1 — RBAC schema. Adds true role/permission tables:
 *   permissions, roles, role_permission (M2M), user_role (M2M).
 *
 * Seeds the full permission catalog and the system roles (Super Admin,
 * Operations, Finance, Support) with their permission sets, then maps existing
 * flagged users onto roles so nobody loses access:
 *   is_admin -> Super Admin, is_finance -> Finance, is_support -> Support,
 *   is_sub_admin -> Operations.
 *
 * Additive only — no enforcement is wired here, so this migration cannot lock
 * anyone out on its own.
 */
final class Version20260614000008 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'RBAC: permissions, roles, role_permission, user_role + catalog/role seed';
    }

    public function up(Schema $schema): void
    {
        $this->abortIf(
            !$this->connection->getDatabasePlatform() instanceof PostgreSQLPlatform,
            'This migration targets PostgreSQL.',
        );

        $this->addSql('CREATE TABLE IF NOT EXISTS permissions (
            id SERIAL PRIMARY KEY,
            permission_key VARCHAR(100) NOT NULL UNIQUE,
            module VARCHAR(50) NOT NULL,
            label VARCHAR(150) NOT NULL
        )');

        $this->addSql('CREATE TABLE IF NOT EXISTS roles (
            id SERIAL PRIMARY KEY,
            slug VARCHAR(80) NOT NULL UNIQUE,
            name VARCHAR(120) NOT NULL,
            description TEXT DEFAULT NULL,
            is_system BOOLEAN NOT NULL DEFAULT FALSE
        )');

        $this->addSql('CREATE TABLE IF NOT EXISTS role_permission (
            role_id INTEGER NOT NULL,
            permission_id INTEGER NOT NULL,
            PRIMARY KEY (role_id, permission_id),
            CONSTRAINT fk_rp_role FOREIGN KEY (role_id) REFERENCES roles(id) ON DELETE CASCADE,
            CONSTRAINT fk_rp_perm FOREIGN KEY (permission_id) REFERENCES permissions(id) ON DELETE CASCADE
        )');
        $this->addSql('CREATE INDEX IF NOT EXISTS idx_rp_permission ON role_permission (permission_id)');

        $this->addSql('CREATE TABLE IF NOT EXISTS user_role (
            user_id INTEGER NOT NULL,
            role_id INTEGER NOT NULL,
            PRIMARY KEY (user_id, role_id),
            CONSTRAINT fk_ur_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
            CONSTRAINT fk_ur_role FOREIGN KEY (role_id) REFERENCES roles(id) ON DELETE CASCADE
        )');
        $this->addSql('CREATE INDEX IF NOT EXISTS idx_ur_role ON user_role (role_id)');

        // ── Seed the permission catalog ───────────────────────────────
        foreach (PermissionCatalog::flat() as $key => $label) {
            $this->addSql(
                'INSERT INTO permissions (permission_key, module, label) VALUES (?, ?, ?) ON CONFLICT (permission_key) DO NOTHING',
                [$key, PermissionCatalog::moduleOf($key), $label],
            );
        }

        // ── Seed system roles + their permissions ─────────────────────
        foreach (PermissionCatalog::systemRoles() as $slug => $def) {
            $this->addSql(
                'INSERT INTO roles (slug, name, description, is_system) VALUES (?, ?, ?, TRUE) ON CONFLICT (slug) DO NOTHING',
                [$slug, $def['name'], $def['description']],
            );
            foreach ($def['permissions'] as $permKey) {
                $this->addSql(
                    'INSERT INTO role_permission (role_id, permission_id)
                     SELECT r.id, p.id FROM roles r, permissions p
                     WHERE r.slug = ? AND p.permission_key = ?
                     ON CONFLICT DO NOTHING',
                    [$slug, $permKey],
                );
            }
        }

        // ── Map existing flagged users onto roles ─────────────────────
        $map = [
            'is_admin' => 'super_admin',
            'is_finance' => 'finance',
            'is_support' => 'support',
            'is_sub_admin' => 'operations',
        ];
        foreach ($map as $flag => $slug) {
            $this->addSql(
                "INSERT INTO user_role (user_id, role_id)
                 SELECT u.id, r.id FROM users u, roles r
                 WHERE u.{$flag} = TRUE AND r.slug = ?
                 ON CONFLICT DO NOTHING",
                [$slug],
            );
        }
    }

    public function down(Schema $schema): void
    {
        $this->abortIf(
            !$this->connection->getDatabasePlatform() instanceof PostgreSQLPlatform,
            'This migration targets PostgreSQL.',
        );

        $this->addSql('DROP TABLE IF EXISTS user_role');
        $this->addSql('DROP TABLE IF EXISTS role_permission');
        $this->addSql('DROP TABLE IF EXISTS roles');
        $this->addSql('DROP TABLE IF EXISTS permissions');
    }
}
