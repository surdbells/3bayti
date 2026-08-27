#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * 3bayti API, Dispute eventType audit (M3.2.X.5-A)
 * ==================================================
 *
 * Read-only operator script. Enumerates every distinct `event_type`
 * value stored in `payment_webhook_events` and tells the operator
 * whether any of them look like dispute / chargeback events that
 * AREN'T in `NoonWebhookController::DISPUTE_EVENT_TYPES`.
 *
 * Why this script exists
 * -----------------------
 * The four placeholder strings in DISPUTE_EVENT_TYPES
 *   - CHARGEBACK_OPENED
 *   - CHARGEBACK_RECEIVED
 *   - DISPUTE_OPENED
 *   - DISPUTE_RECEIVED
 * are based on Noon's published API contract docs, not on what Noon
 * actually emits. Until we observe a real dispute event from Noon,
 * we don't know whether our constant is right. This script lets
 * operators check that question retrospectively against production
 * data, if real disputes have already arrived using different
 * strings, the warning hook in NoonWebhookController would have
 * surfaced them, but this script gives a one-shot audit of the
 * full history.
 *
 * Output
 * ------
 * Prints three sections:
 *
 *   1. Every distinct event_type seen, with count + first/last seen
 *   2. Any event_type containing 'dispute' or 'chargeback' substring
 *      that's NOT in our recognized list (these are the action items)
 *   3. Summary: total events, distinct types, suspicious types
 *
 * Read-only
 * ---------
 * Performs SELECT queries only. Safe to run against production at
 * any time. No locks, no writes, single read query.
 *
 * Usage (server)
 * --------------
 *   cd /www/wwwroot/3bayti/apps/api
 *   /www/server/php/83/bin/php bin/audit-dispute-event-types.php
 *
 * Usage (local/staging)
 * ---------------------
 *   cd apps/api
 *   php bin/audit-dispute-event-types.php
 *
 * Exit codes:
 *   0 , no suspicious unknown dispute-shaped types found
 *   1 , bootstrapping failure
 *   2 , suspicious dispute-shaped types found (operator should
 *        add them to DISPUTE_EVENT_TYPES + backfill OrderDispute
 *        rows for the affected events)
 */

require_once __DIR__ . '/../vendor/autoload.php';

use Bayti\Api\Bootstrap;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;

$app = Bootstrap::createApp();
$container = $app->getContainer();
if ($container === null) {
    fwrite(STDERR, "DI container not available\n");
    exit(1);
}

/** @var EntityManagerInterface $em */
$em = $container->get(EntityManagerInterface::class);
$conn = $em->getConnection();

// Mirror of NoonWebhookController::DISPUTE_EVENT_TYPES. Keep in sync
// if that constant changes. We can't import it directly because it's
// private to the controller class.
$recognizedDisputeTypes = [
    'CHARGEBACK_OPENED',
    'CHARGEBACK_RECEIVED',
    'DISPUTE_OPENED',
    'DISPUTE_RECEIVED',
];

echo "============================================================\n";
echo "Dispute eventType audit — M3.2.X.5-A\n";
echo "============================================================\n\n";

// Section 1: every distinct event_type with counts + first/last seen.
$rows = fetchEventTypeStats($conn);

if ($rows === []) {
    echo "No webhook events found in payment_webhook_events.\n";
    echo "(Either this is a fresh deploy or no webhooks have arrived yet.)\n\n";
    echo "============================================================\n";
    echo "Result: NO DATA — nothing to audit.\n";
    echo "============================================================\n";
    exit(0);
}

echo "Section 1 — All distinct event_type values\n";
echo "------------------------------------------------------------\n";
printf("%-40s %10s   %s — %s\n", 'event_type', 'count', 'first seen', 'last seen');
echo str_repeat('-', 100) . "\n";

$totalEvents = 0;
foreach ($rows as $row) {
    $displayType = $row['event_type'] ?? '(null)';
    printf(
        "%-40s %10d   %s — %s\n",
        $displayType,
        (int) $row['cnt'],
        $row['first_seen'],
        $row['last_seen'],
    );
    $totalEvents += (int) $row['cnt'];
}
echo "\n";

// Section 2: suspicious unknown dispute-shaped types.
$suspicious = [];
foreach ($rows as $row) {
    $type = $row['event_type'];
    if ($type === null || $type === '') {
        continue;
    }
    if (in_array($type, $recognizedDisputeTypes, true)) {
        continue;
    }
    $lower = strtolower($type);
    if (str_contains($lower, 'dispute') || str_contains($lower, 'chargeback')) {
        $suspicious[] = $row;
    }
}

echo "Section 2 — Suspicious dispute-shaped event_type values\n";
echo "------------------------------------------------------------\n";
if ($suspicious === []) {
    echo "None found. Either no dispute events have arrived, or every\n";
    echo "dispute-shaped event_type is already in DISPUTE_EVENT_TYPES.\n";
} else {
    echo "Found " . count($suspicious) . " unrecognized dispute-shaped event_type(s):\n\n";
    foreach ($suspicious as $row) {
        printf(
            "  ⚠ %-40s count=%d  first_seen=%s\n",
            $row['event_type'],
            (int) $row['cnt'],
            $row['first_seen'],
        );
    }
    echo "\nACTION REQUIRED:\n";
    echo "  1. Verify each ⚠ string above is a real Noon dispute eventType\n";
    echo "     (cross-check against Noon merchant portal / API docs).\n";
    echo "  2. Add the confirmed dispute eventType strings to\n";
    echo "     NoonWebhookController::DISPUTE_EVENT_TYPES.\n";
    echo "  3. Backfill any historical OrderDispute rows that would have\n";
    echo "     been created if the constant had been correct at the time.\n";
    echo "     Query template:\n\n";
    echo "       SELECT id, provider_order_ref, event_type, payload, received_at\n";
    echo "       FROM payment_webhook_events\n";
    echo "       WHERE event_type IN (...your new strings...)\n";
    echo "       AND NOT EXISTS (\n";
    echo "         SELECT 1 FROM order_disputes od\n";
    echo "         WHERE od.provider_dispute_id = ...\n";
    echo "       );\n\n";
}

// Section 3: summary.
echo "\nSection 3 — Summary\n";
echo "------------------------------------------------------------\n";
echo "Total webhook events:       $totalEvents\n";
echo "Distinct event_type values: " . count($rows) . "\n";
echo "Recognized dispute types:   " . count($recognizedDisputeTypes) . "\n";
echo "Suspicious unknown types:   " . count($suspicious) . "\n\n";

echo "============================================================\n";
if ($suspicious === []) {
    echo "Result: CLEAN — no action required.\n";
    echo "============================================================\n";
    exit(0);
} else {
    echo "Result: ACTION REQUIRED — see Section 2 above.\n";
    echo "============================================================\n";
    exit(2);
}

/**
 * @return list<array{event_type: ?string, cnt: int, first_seen: string, last_seen: string}>
 */
function fetchEventTypeStats(Connection $conn): array
{
    // PostgreSQL, uses standard SQL aggregates. The received_at
    // column is the canonical insert timestamp on payment_webhook_events.
    $sql = <<<SQL
        SELECT
            event_type,
            COUNT(*) AS cnt,
            MIN(received_at) AS first_seen,
            MAX(received_at) AS last_seen
        FROM payment_webhook_events
        GROUP BY event_type
        ORDER BY COUNT(*) DESC, event_type
        SQL;

    /** @var list<array{event_type: ?string, cnt: int, first_seen: string, last_seen: string}> */
    return $conn->fetchAllAssociative($sql);
}
