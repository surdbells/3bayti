<?php

declare(strict_types=1);

namespace Bayti\Api\Domain\GiftReminder;

use DateTimeImmutable;
use DateTimeZone;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;

/**
 * Raw-DBAL eligibility finder for the gift-reminder nudge cron.
 *
 * For each reminder within the 14-day horizon it computes the day-distance to
 * the event (in a FIXED timezone so "N days before" never slips) and the
 * most-urgent nudge stage (14 → 7 → 2) whose threshold has been reached and not
 * yet fired. Using a RANGE window (not exact-day equality) means a missed cron
 * run catches up on the next run, and a reminder created between thresholds
 * still gets its nearest nudge.
 *
 * The idempotency guard is numeric on a SMALLINT column: stages fire in
 * DECREASING day order, so a stage fires only when last_notified_stage is null
 * or strictly greater than the stage day-count.
 */
class GiftReminderDispatchFinder
{
    /** Nudge thresholds, in days before the event. */
    public const STAGE_DAYS = [14, 7, 2];

    /** The widest threshold — the horizon of the eligibility scan. */
    private const MAX_STAGE = 14;

    /** "N days before" is computed in UAE local time so it never slips a day. */
    private const TZ = 'Asia/Dubai';

    public const DEFAULT_BATCH_SIZE = 200;
    public const MAX_BATCH_SIZE = 500;

    public function __construct(private readonly Connection $connection)
    {
    }

    /**
     * Reminders due for a nudge now, each with the stage (day-count) to fire.
     *
     * @return list<array{id: int, stage: int}>
     */
    public function findDue(int $batchSize = self::DEFAULT_BATCH_SIZE): array
    {
        $batch = max(1, min(self::MAX_BATCH_SIZE, $batchSize));
        $tz = new DateTimeZone(self::TZ);
        $today = (new DateTimeImmutable('now', $tz))->setTime(0, 0, 0);
        $todayStr = $today->format('Y-m-d');
        $horizonStr = $today->modify('+' . self::MAX_STAGE . ' days')->format('Y-m-d');

        try {
            $rows = $this->connection->fetchAllAssociative(
                'SELECT id, remind_date, last_notified_stage
                 FROM gift_reminders
                 WHERE remind_date >= :today AND remind_date <= :horizon
                 ORDER BY remind_date ASC
                 LIMIT :batch',
                ['today' => $todayStr, 'horizon' => $horizonStr, 'batch' => $batch],
                ['today' => ParameterType::STRING, 'horizon' => ParameterType::STRING, 'batch' => ParameterType::INTEGER],
            );
        } catch (\Throwable) {
            return [];
        }

        $out = [];
        foreach ($rows as $row) {
            $remind = (new DateTimeImmutable((string) $row['remind_date'], $tz))->setTime(0, 0, 0);
            $daysUntil = (int) $today->diff($remind)->format('%r%a');
            if ($daysUntil < 0 || $daysUntil > self::MAX_STAGE) {
                continue;
            }
            $stage = $this->stageFor($daysUntil);
            if ($stage === null) {
                continue;
            }
            $lastStage = $row['last_notified_stage'] !== null ? (int) $row['last_notified_stage'] : null;
            // Fire only when we haven't already sent this (or a more-urgent) stage.
            if ($lastStage !== null && $lastStage <= $stage) {
                continue;
            }
            $out[] = ['id' => (int) $row['id'], 'stage' => $stage];
        }

        return $out;
    }

    /** The nearest threshold at or beyond the current distance (smallest stage >= daysUntil). */
    private function stageFor(int $daysUntil): ?int
    {
        $best = null;
        foreach (self::STAGE_DAYS as $stage) {
            if ($stage >= $daysUntil && ($best === null || $stage < $best)) {
                $best = $stage;
            }
        }
        return $best;
    }
}
