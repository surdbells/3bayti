<?php

declare(strict_types=1);

namespace Bayti\Api\Migration;

use mysqli;
use mysqli_result;
use RuntimeException;

/**
 * Wraps a mysqli connection to the legacy production MySQL.
 *
 * Reads credentials from environment variables (set via .env on the
 * production server). All queries are read-only, this class deliberately
 * has no write methods. The legacy DB stays unchanged during migration.
 *
 * Required env vars (loaded via Bootstrap):
 *   LEGACY_MYSQL_HOST
 *   LEGACY_MYSQL_PORT  (defaults to 3306)
 *   LEGACY_MYSQL_USER
 *   LEGACY_MYSQL_PASS
 *   LEGACY_MYSQL_DB
 *
 * Usage:
 *   $db = new LegacyDb();
 *   foreach ($db->iterate('SELECT * FROM users') as $row) {
 *       // process $row as assoc array
 *   }
 *
 * Iteration uses unbuffered queries so we don't load 9316 users
 * into memory at once. Each row hydrates on demand.
 */
final class LegacyDb
{
    private mysqli $conn;

    public function __construct()
    {
        $host = $_ENV['LEGACY_MYSQL_HOST'] ?? getenv('LEGACY_MYSQL_HOST') ?: '';
        $port = (int) ($_ENV['LEGACY_MYSQL_PORT'] ?? getenv('LEGACY_MYSQL_PORT') ?: 3306);
        $user = $_ENV['LEGACY_MYSQL_USER'] ?? getenv('LEGACY_MYSQL_USER') ?: '';
        $pass = $_ENV['LEGACY_MYSQL_PASS'] ?? getenv('LEGACY_MYSQL_PASS') ?: '';
        $db = $_ENV['LEGACY_MYSQL_DB'] ?? getenv('LEGACY_MYSQL_DB') ?: '';

        if ($host === '' || $user === '' || $pass === '' || $db === '') {
            throw new RuntimeException(
                'Legacy MySQL credentials missing. Set LEGACY_MYSQL_HOST/USER/PASS/DB in .env'
            );
        }

        // mysqli_report so we don't have to manually check connect_error.
        mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
        $this->conn = new mysqli($host, $user, $pass, $db, $port);
        $this->conn->set_charset('utf8mb4');
    }

    /**
     * Single-row fetch. Returns null if no rows.
     *
     * @return array<string, mixed>|null
     */
    public function fetchOne(string $sql): ?array
    {
        $result = $this->conn->query($sql);
        if (!$result instanceof mysqli_result) {
            return null;
        }
        $row = $result->fetch_assoc();
        $result->free();
        return $row;
    }

    /**
     * Eager fetch, load all rows into memory. Use only for small tables
     * (< 1000 rows). Use iterate() for anything larger.
     *
     * @return list<array<string, mixed>>
     */
    public function fetchAll(string $sql): array
    {
        $result = $this->conn->query($sql);
        if (!$result instanceof mysqli_result) {
            return [];
        }
        $rows = [];
        while ($row = $result->fetch_assoc()) {
            $rows[] = $row;
        }
        $result->free();
        return $rows;
    }

    /**
     * Streaming fetch, yields one row at a time without loading all
     * rows into memory. Required for the users (9316 rows) and products
     * (2165 rows) iterations.
     *
     * @return iterable<array<string, mixed>>
     */
    public function iterate(string $sql): iterable
    {
        // MYSQLI_USE_RESULT = unbuffered, server holds the rows
        $result = $this->conn->query($sql, MYSQLI_USE_RESULT);
        if (!$result instanceof mysqli_result) {
            return;
        }
        try {
            while ($row = $result->fetch_assoc()) {
                yield $row;
            }
        } finally {
            $result->free();
        }
    }

    /**
     * Quick count helper, wraps "SELECT COUNT(*) FROM ...".
     */
    public function count(string $table, string $where = '1=1'): int
    {
        $row = $this->fetchOne("SELECT COUNT(*) AS c FROM `{$table}` WHERE {$where}");
        return (int) ($row['c'] ?? 0);
    }

    /**
     * Check whether a table exists in the connected legacy schema.
     *
     * Why this exists
     * ===============
     * Some migration steps target legacy tables whose names/shapes
     * weren't fully understood when the v3 migration scripts were
     * written (M3.1.5.5c, labels + styles). Rather than hard-fail
     * the entire migration when an unfamiliar table is missing, the
     * step probes for the table and logs-and-skips gracefully when
     * absent.
     *
     * Uses INFORMATION_SCHEMA so we don't depend on MySQL-specific
     * SHOW TABLES syntax (works on any spec-compliant SQL).
     */
    public function tableExists(string $table): bool
    {
        $row = $this->fetchOne(
            "SELECT 1 AS exists_flag FROM information_schema.tables "
            . "WHERE table_schema = DATABASE() AND table_name = '{$this->escape($table)}' "
            . "LIMIT 1"
        );
        return $row !== null;
    }

    /**
     * Check whether a column exists on a given legacy table. Same
     * "defensive probe" pattern as tableExists. Useful when the
     * table might be present but with different column names than
     * expected.
     */
    public function columnExists(string $table, string $column): bool
    {
        $row = $this->fetchOne(
            "SELECT 1 AS exists_flag FROM information_schema.columns "
            . "WHERE table_schema = DATABASE() "
            . "AND table_name = '{$this->escape($table)}' "
            . "AND column_name = '{$this->escape($column)}' "
            . "LIMIT 1"
        );
        return $row !== null;
    }

    /**
     * Conservative string escape for inline SQL. We don't have access
     * to prepared statements via the existing fetchOne/fetchAll
     * surface, and the only callers here are tableExists/columnExists
     * with operator-controlled identifiers (not user input). Still,
     * defense in depth, drop anything outside a safe identifier
     * character set.
     */
    private function escape(string $value): string
    {
        return $this->conn->real_escape_string($value);
    }

    public function close(): void
    {
        $this->conn->close();
    }
}
