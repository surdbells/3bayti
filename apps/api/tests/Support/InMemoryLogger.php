<?php

declare(strict_types=1);

namespace Bayti\Api\Tests\Support;

use Psr\Log\AbstractLogger;
use Stringable;

/**
 * PSR-3 logger that captures every log call in memory for test
 * inspection. Used by FacetAggregatorTest (M3.2.X.10-D) and any
 * future test that needs to assert on log output.
 *
 * Each captured record is a 3-key array:
 *   - level: string (debug|info|notice|warning|error|critical|alert|emergency)
 *   - message: string (the original $message)
 *   - context: array (the original $context)
 *
 * Inspection helpers:
 *   - records(), full list
 *   - findByMessage(string), filter to records whose message matches
 *   - findByLevel(string), filter by level
 *   - clear(), reset between cases
 */
final class InMemoryLogger extends AbstractLogger
{
    /**
     * @var list<array{level: string, message: string, context: array<string, mixed>}>
     */
    private array $records = [];

    /**
     * @param array<string, mixed> $context
     */
    public function log($level, string|Stringable $message, array $context = []): void
    {
        $this->records[] = [
            'level' => (string) $level,
            'message' => (string) $message,
            'context' => $context,
        ];
    }

    /**
     * @return list<array{level: string, message: string, context: array<string, mixed>}>
     */
    public function records(): array
    {
        return $this->records;
    }

    /**
     * @return list<array{level: string, message: string, context: array<string, mixed>}>
     */
    public function findByMessage(string $message): array
    {
        return array_values(array_filter(
            $this->records,
            fn(array $r): bool => $r['message'] === $message,
        ));
    }

    /**
     * @return list<array{level: string, message: string, context: array<string, mixed>}>
     */
    public function findByLevel(string $level): array
    {
        return array_values(array_filter(
            $this->records,
            fn(array $r): bool => $r['level'] === $level,
        ));
    }

    public function clear(): void
    {
        $this->records = [];
    }
}
