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
 * production server). All queries are read-only — this class deliberately
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
     * Eager fetch — load all rows into memory. Use only for small tables
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
     * Streaming fetch — yields one row at a time without loading all
     * rows into memory. Required for the users (9316 rows) and products
     * (2165 rows) iterations.
     *
     * @return iterable<array<string, mixed>>
     */
    public function iterate(string $sql): iterable
    {
        // MYSQLI_USE_RESULT = unbuffered — server holds the rows
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
     * Quick count helper — wraps "SELECT COUNT(*) FROM ...".
     */
    public function count(string $table, string $where = '1=1'): int
    {
        $row = $this->fetchOne("SELECT COUNT(*) AS c FROM `{$table}` WHERE {$where}");
        return (int) ($row['c'] ?? 0);
    }

    public function close(): void
    {
        $this->conn->close();
    }
}
