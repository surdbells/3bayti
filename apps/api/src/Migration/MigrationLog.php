<?php

declare(strict_types=1);

namespace Bayti\Api\Migration;

use Doctrine\DBAL\Connection;
use DateTimeImmutable;

/**
 * Structured logger for migration runs.
 *
 * Writes to BOTH:
 *  - stdout (so the operator running the script sees progress in real-time)
 *  - the `migration_log` Postgres table (so we have a forensic record
 *    of what was migrated, skipped, or failed — accessible after the
 *    migration script finishes)
 *
 * Each entry has:
 *  - level: 'info' | 'warn' | 'error' | 'skip'
 *  - phase: 'categories' | 'users' | 'vendors' | 'products' | 'reviews'
 *  - legacy_id: the source row id (for traceability)
 *  - message: human description
 *  - context: arbitrary JSON for additional data
 *
 * Schema is created on first construction if it doesn't exist —
 * intentional because this is a one-shot tool and we don't want to
 * bloat the regular migrations directory with a logger table.
 */
final class MigrationLog
{
    private Connection $conn;
    private string $runId;

    /** @var array<string, int> Stats counter per (phase, level). */
    private array $stats = [];

    public function __construct(Connection $conn)
    {
        $this->conn = $conn;
        $this->runId = (new DateTimeImmutable())->format('Ymd-His');
        $this->ensureTable();
    }

    /**
     * @param array<string, mixed> $context
     */
    public function info(string $phase, ?int $legacyId, string $message, array $context = []): void
    {
        $this->log('info', $phase, $legacyId, $message, $context);
    }

    /**
     * @param array<string, mixed> $context
     */
    public function warn(string $phase, ?int $legacyId, string $message, array $context = []): void
    {
        $this->log('warn', $phase, $legacyId, $message, $context);
    }

    /**
     * @param array<string, mixed> $context
     */
    public function error(string $phase, ?int $legacyId, string $message, array $context = []): void
    {
        $this->log('error', $phase, $legacyId, $message, $context);
    }

    /**
     * @param array<string, mixed> $context
     */
    public function skip(string $phase, ?int $legacyId, string $reason, array $context = []): void
    {
        $this->log('skip', $phase, $legacyId, $reason, $context);
    }

    /**
     * Run summary printed at the end of a migration phase.
     */
    public function summary(string $phase): void
    {
        $tag = '[' . $phase . ']';
        $created = $this->stats[$phase . '.info'] ?? 0;
        $skipped = $this->stats[$phase . '.skip'] ?? 0;
        $warned = $this->stats[$phase . '.warn'] ?? 0;
        $errors = $this->stats[$phase . '.error'] ?? 0;
        echo sprintf(
            "%s SUMMARY  created=%d  skipped=%d  warned=%d  errors=%d\n",
            $tag,
            $created,
            $skipped,
            $warned,
            $errors,
        );
    }

    public function getRunId(): string
    {
        return $this->runId;
    }

    /**
     * @param array<string, mixed> $context
     */
    private function log(string $level, string $phase, ?int $legacyId, string $message, array $context): void
    {
        $tag = '[' . $phase;
        if ($legacyId !== null) {
            $tag .= ' #' . $legacyId;
        }
        $tag .= ']';

        $emoji = match ($level) {
            'info' => '✓',
            'warn' => '⚠',
            'error' => '✗',
            'skip' => '↷',
            default => '·',
        };

        echo sprintf("  %s %s %s\n", $emoji, $tag, $message);

        $this->stats[$phase . '.' . $level] = ($this->stats[$phase . '.' . $level] ?? 0) + 1;

        try {
            $this->conn->executeStatement(
                "INSERT INTO migration_log (run_id, level, phase, legacy_id, message, context, created_at)
                 VALUES (:run_id, :level, :phase, :legacy_id, :message, :context, NOW())",
                [
                    'run_id' => $this->runId,
                    'level' => $level,
                    'phase' => $phase,
                    'legacy_id' => $legacyId,
                    'message' => $message,
                    'context' => json_encode($context, JSON_UNESCAPED_UNICODE),
                ],
            );
        } catch (\Throwable $e) {
            // Don't let logging failures crash the migration. Stdout still shows the message.
            fwrite(STDERR, "  (migration_log insert failed: " . $e->getMessage() . ")\n");
        }
    }

    private function ensureTable(): void
    {
        $this->conn->executeStatement(<<<SQL
            CREATE TABLE IF NOT EXISTS migration_log (
                id          BIGSERIAL    PRIMARY KEY,
                run_id      VARCHAR(20)  NOT NULL,
                level       VARCHAR(10)  NOT NULL,
                phase       VARCHAR(40)  NOT NULL,
                legacy_id   INTEGER      NULL,
                message     TEXT         NOT NULL,
                context     JSONB        NULL,
                created_at  TIMESTAMPTZ  NOT NULL DEFAULT NOW()
            )
        SQL);

        $this->conn->executeStatement(
            'CREATE INDEX IF NOT EXISTS migration_log_run_phase_idx ON migration_log (run_id, phase, level)'
        );
    }
}
