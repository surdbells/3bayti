<?php

declare(strict_types=1);

namespace Bayti\Api\Domain\Order;

use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;

/**
 * Aggregate chronological events for an order across multiple
 * sources into a single timeline (M3.2.X.17-A).
 *
 * Event sources (Q-EventSources = A locked):
 *   1. Entity-derived events from the Order itself
 *      (order.created from createdAt; order.paid from paid_at)
 *   2. audit_log table — every recorded admin action against
 *      the order or its items (subject_type IN ('Order','OrderItem',
 *      'OrderReturnRequest'))
 *   3. notification_logs — every email fired for the order
 *      (template + status + sent_at)
 *   4. order_return_requests — return lifecycle timestamps
 *      (requested / decided / picked_up / delivered_to_vendor /
 *      refunded / cancelled)
 *   5. order_disputes — dispute lifecycle (created, resolved)
 *
 * Vendor filter (Q-VendorScope = A locked)
 * =========================================
 * When a vendorId is supplied, the timeline narrows to events
 * the vendor caused OR events affecting their items:
 *
 *   - order.created / order.paid → always shown (order-wide context)
 *   - notification.* with this vendor as recipient → shown
 *   - audit rows where the vendor user is the actor → shown
 *   - audit rows on OrderItem rows owned by this vendor → shown
 *   - return.* where the return touches this vendor's items → shown
 *   - dispute.* → NOT shown (disputes are admin-only forensic data)
 *
 * Canonical event shape (Q-EventShape = A locked)
 * ================================================
 * {
 *   id:          "audit:1234" | "notification:567" | "return:42" |
 *                "dispute:8" | "order:created" | "order:paid"
 *                — namespaced source:id so cross-source uniqueness
 *                  is guaranteed
 *   type:        "order.created" | "order.paid" | "order.status_changed"
 *                | "order.item_status_changed" | "notification.sent"
 *                | "notification.failed" | "return.submitted"
 *                | "return.approved" | "return.denied"
 *                | "return.picked_up" | "return.received_by_vendor"
 *                | "return.refunded" | "dispute.created"
 *                | "dispute.resolved"
 *   occurred_at: ISO-8601 timestamp
 *   actor:       { type: 'admin'|'vendor'|'customer'|'system', id?, label? }
 *   summary:     human-readable one-liner
 *   details:     event-specific structured payload
 * }
 *
 * Ordering (Q-Ordering = C locked)
 * =================================
 * Default newest-first ('desc'). Caller-supplied 'asc' inverts.
 *
 * Pagination (Q-Pagination = A locked)
 * =====================================
 * limit + offset over the merged + sorted list. v1 returns ALL
 * events from each source then slices in PHP — acceptable for
 * typical orders with 10-50 events. Cursor-based deferred.
 *
 * Observability mirrors the X.10-D / X.14-A pattern.
 */
final class OrderTimelineBuilder
{
    private const STATEMENT_TIMEOUT_MS = 2000;
    private const SLOW_THRESHOLD_MS = 300;

    public const DEFAULT_LIMIT = 50;
    public const MAX_LIMIT = 200;

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly LoggerInterface $logger,
    ) {
    }

    /**
     * Build the timeline for a single order.
     *
     * @param int|null $vendorIdFilter When non-null, narrows the
     *        timeline to events the vendor caused or affecting their
     *        items per the Q-VendorScope rules. Pass null for the
     *        admin view.
     *
     * @return array{
     *     events: list<array{
     *         id: string,
     *         type: string,
     *         occurred_at: string,
     *         actor: array{type: string, id?: int, label?: string},
     *         summary: string,
     *         details: array<string, mixed>
     *     }>,
     *     total: int
     * }
     */
    public function build(
        int $orderId,
        ?int $vendorIdFilter = null,
        string $order = 'desc',
        int $limit = self::DEFAULT_LIMIT,
        int $offset = 0,
    ): array {
        $this->applyStatementTimeout();
        $startNs = hrtime(true);

        $events = [];

        // 1. Entity-derived events (always shown)
        $events = array_merge($events, $this->fetchOrderEntityEvents($orderId));

        // 2. Audit log events
        $events = array_merge($events, $this->fetchAuditEvents($orderId, $vendorIdFilter));

        // 3. Notification events
        $events = array_merge($events, $this->fetchNotificationEvents($orderId, $vendorIdFilter));

        // 4. Return request events
        $events = array_merge($events, $this->fetchReturnEvents($orderId, $vendorIdFilter));

        // 5. Dispute events — admin-only (Q-VendorScope = A)
        if ($vendorIdFilter === null) {
            $events = array_merge($events, $this->fetchDisputeEvents($orderId));
        }

        // Merge sort
        $events = $this->sortByOccurredAt($events, $order);

        // Paginate
        $total = count($events);
        $paged = array_slice($events, $offset, $limit);

        $elapsedMs = (int) ((hrtime(true) - $startNs) / 1_000_000);
        $this->emitTimingLogs($elapsedMs, [
            'order_id' => $orderId,
            'vendor_id_filter' => $vendorIdFilter,
            'total_events' => $total,
            'returned' => count($paged),
        ]);

        return ['events' => $paged, 'total' => $total];
    }

    // =================================================================
    // Source 1 — entity-derived events
    // =================================================================

    /**
     * @return list<array<string, mixed>>
     */
    private function fetchOrderEntityEvents(int $orderId): array
    {
        $sql = "SELECT id, order_reference, created_at, paid_at FROM orders WHERE id = :orderId";
        $row = $this->em->getConnection()->executeQuery(
            $sql,
            ['orderId' => $orderId],
        )->fetchAssociative();

        if ($row === false) {
            return [];
        }

        $events = [];
        $events[] = [
            'id' => 'order:created',
            'type' => 'order.created',
            'occurred_at' => $this->formatTimestamp($row['created_at']),
            'actor' => ['type' => 'system'],
            'summary' => "Order {$row['order_reference']} created",
            'details' => ['order_reference' => $row['order_reference']],
        ];

        if (!empty($row['paid_at'])) {
            $events[] = [
                'id' => 'order:paid',
                'type' => 'order.paid',
                'occurred_at' => $this->formatTimestamp($row['paid_at']),
                'actor' => ['type' => 'system'],
                'summary' => "Payment confirmed for {$row['order_reference']}",
                'details' => ['order_reference' => $row['order_reference']],
            ];
        }

        return $events;
    }

    // =================================================================
    // Source 2 — audit_log
    // =================================================================

    /**
     * @return list<array<string, mixed>>
     */
    private function fetchAuditEvents(int $orderId, ?int $vendorIdFilter): array
    {
        // Order audits: subject_type='Order' and subject_id = orderId
        // OrderItem audits: subject_type='OrderItem', need to join
        //   order_items to filter by order_id
        // Return audits: subject_type='OrderReturnRequest', join via
        //   order_return_requests.order_id
        //
        // We do this as three UNIONed queries to keep each filter
        // localised.
        $sql = "
            (SELECT
                al.id, al.user_id, al.subject_type, al.subject_id, al.action,
                al.changes, al.created_at, al.ip_address,
                NULL::bigint AS item_vendor_id,
                o.order_reference
             FROM audit_log al
             INNER JOIN orders o ON o.id = al.subject_id
             WHERE al.subject_type = 'Order' AND al.subject_id = :orderId)
            UNION ALL
            (SELECT
                al.id, al.user_id, al.subject_type, al.subject_id, al.action,
                al.changes, al.created_at, al.ip_address,
                oi.vendor_id AS item_vendor_id,
                o.order_reference
             FROM audit_log al
             INNER JOIN order_items oi ON oi.id = al.subject_id
             INNER JOIN orders o ON o.id = oi.order_id
             WHERE al.subject_type = 'OrderItem' AND oi.order_id = :orderId)
            UNION ALL
            (SELECT
                al.id, al.user_id, al.subject_type, al.subject_id, al.action,
                al.changes, al.created_at, al.ip_address,
                NULL::bigint AS item_vendor_id,
                o.order_reference
             FROM audit_log al
             INNER JOIN order_return_requests rr ON rr.id = al.subject_id
             INNER JOIN orders o ON o.id = rr.order_id
             WHERE al.subject_type = 'OrderReturnRequest' AND rr.order_id = :orderId)
        ";

        $rows = $this->em->getConnection()->executeQuery(
            $sql,
            ['orderId' => $orderId],
        )->fetchAllAssociative();

        $events = [];
        foreach ($rows as $row) {
            // Vendor filter: keep audit rows where
            //   - the subject is an OrderItem owned by this vendor, OR
            //   - the actor user_id maps to this vendor (audit_log
            //     doesn't directly carry vendor_id; we cannot reliably
            //     filter actor→vendor without joining vendors. For v1
            //     we filter ONLY by item-vendor; actor-filtered audits
            //     are admin-driven so excluding them is the safer
            //     default for vendor view)
            if ($vendorIdFilter !== null) {
                $itemVendorId = $row['item_vendor_id'] !== null
                    ? (int) $row['item_vendor_id']
                    : null;
                if ($itemVendorId !== $vendorIdFilter) {
                    // Drop unless this audit was on this vendor's item
                    continue;
                }
            }

            $changes = $this->decodeChanges($row['changes']);
            $events[] = [
                'id' => 'audit:' . (string) $row['id'],
                'type' => $this->classifyAuditType($row['subject_type'], $row['action'], $changes),
                'occurred_at' => $this->formatTimestamp($row['created_at']),
                'actor' => $this->buildActorBlock($row['user_id'], $row['ip_address']),
                'summary' => $this->summarizeAuditEvent($row['subject_type'], $row['action'], $changes),
                'details' => array_merge($changes, [
                    'subject_type' => $row['subject_type'],
                    'subject_id' => (int) $row['subject_id'],
                ]),
            ];
        }

        return $events;
    }

    /**
     * @param array<string, mixed> $changes
     */
    private function classifyAuditType(string $subjectType, string $action, array $changes): string
    {
        // OrderItem status changes are item-level transitions
        if ($subjectType === 'OrderItem') {
            // Detect a status change via 'before.status' / 'after.status'
            // presence in changes
            $before = $changes['before'] ?? null;
            $after = $changes['after'] ?? null;
            if (is_array($before) && is_array($after) && isset($before['item_status'], $after['item_status'])) {
                return 'order.item_status_changed';
            }
            return 'order.item_' . $action;  // e.g. order.item_updated
        }
        if ($subjectType === 'Order') {
            $before = $changes['before'] ?? null;
            $after = $changes['after'] ?? null;
            if (is_array($before) && is_array($after) && isset($before['status'], $after['status'])) {
                return 'order.status_changed';
            }
            return 'order.' . $action;
        }
        if ($subjectType === 'OrderReturnRequest') {
            // Most return events come from the order_return_requests
            // table directly (Source 4). Audit rows here capture
            // admin-driven mutations specifically.
            return 'return.' . $action;
        }
        return $subjectType . '.' . $action;
    }

    /**
     * @param array<string, mixed> $changes
     */
    private function summarizeAuditEvent(string $subjectType, string $action, array $changes): string
    {
        if ($subjectType === 'Order' && isset($changes['before']['status'], $changes['after']['status'])) {
            return "Order status changed from '{$changes['before']['status']}' to '{$changes['after']['status']}'";
        }
        if ($subjectType === 'OrderItem' && isset($changes['before']['item_status'], $changes['after']['item_status'])) {
            return "Item status changed from '{$changes['before']['item_status']}' to '{$changes['after']['item_status']}'";
        }
        if ($subjectType === 'OrderReturnRequest') {
            $context = $changes['context'] ?? '';
            if ($context !== '') {
                return "Return request: {$context}";
            }
        }
        return ucfirst($subjectType) . ' ' . $action;
    }

    // =================================================================
    // Source 3 — notification_logs
    // =================================================================

    /**
     * @return list<array<string, mixed>>
     */
    private function fetchNotificationEvents(int $orderId, ?int $vendorIdFilter): array
    {
        // Vendor scope: a vendor sees notifications addressed to them
        // (recipient matches their contact_email) and customer-side
        // notifications about their items. For v1 we apply ONLY the
        // 'addressed to this vendor' filter — finer-grained per-item
        // attribution would require notification rows to carry
        // vendor_id (they don't today). Documented as operator
        // follow-up #22 in the closure.
        $sql = "
            SELECT
                nl.id, nl.order_id, nl.template, nl.recipient, nl.status,
                nl.sent_at, nl.last_failure_reason, nl.context, nl.created_at
            FROM notification_logs nl
            WHERE nl.order_id = :orderId
        ";

        $rows = $this->em->getConnection()->executeQuery(
            $sql,
            ['orderId' => $orderId],
        )->fetchAllAssociative();

        $vendorEmail = null;
        if ($vendorIdFilter !== null) {
            $vendorEmail = $this->fetchVendorContactEmail($vendorIdFilter);
        }

        $events = [];
        foreach ($rows as $row) {
            if ($vendorEmail !== null && $row['recipient'] !== $vendorEmail) {
                continue;
            }

            $sent = ($row['status'] ?? '') === 'sent';
            $events[] = [
                'id' => 'notification:' . (string) $row['id'],
                'type' => $sent ? 'notification.sent' : 'notification.failed',
                'occurred_at' => $this->formatTimestamp(
                    $row['sent_at'] !== null ? $row['sent_at'] : $row['created_at'],
                ),
                'actor' => ['type' => 'system'],
                'summary' => $sent
                    ? "Sent '{$row['template']}' to {$row['recipient']}"
                    : "Failed to send '{$row['template']}' to {$row['recipient']}",
                'details' => [
                    'template' => $row['template'],
                    'recipient' => $row['recipient'],
                    'status' => $row['status'],
                    'failure_reason' => $row['last_failure_reason'],
                ],
            ];
        }

        return $events;
    }

    private function fetchVendorContactEmail(int $vendorId): ?string
    {
        $sql = "SELECT contact_email FROM vendors WHERE id = :vendorId";
        $row = $this->em->getConnection()->executeQuery(
            $sql,
            ['vendorId' => $vendorId],
        )->fetchAssociative();

        return $row !== false ? (string) ($row['contact_email'] ?? '') : null;
    }

    // =================================================================
    // Source 4 — order_return_requests
    // =================================================================

    /**
     * @return list<array<string, mixed>>
     */
    private function fetchReturnEvents(int $orderId, ?int $vendorIdFilter): array
    {
        // For vendor scope: a return is shown if any of its items
        // belongs to this vendor.
        $sql = "
            SELECT DISTINCT
                rr.id, rr.status, rr.reason, rr.customer_id,
                rr.requested_at, rr.decided_at, rr.decided_by_user_id,
                rr.picked_up_at, rr.delivered_to_vendor_at,
                rr.refunded_at, rr.cancelled_at
            FROM order_return_requests rr
        ";

        if ($vendorIdFilter !== null) {
            $sql .= "
            INNER JOIN order_return_request_items rri ON rri.return_request_id = rr.id
            INNER JOIN order_items oi ON oi.id = rri.order_item_id
            WHERE rr.order_id = :orderId AND oi.vendor_id = :vendorId
        ";
            $params = ['orderId' => $orderId, 'vendorId' => $vendorIdFilter];
        } else {
            $sql .= "WHERE rr.order_id = :orderId";
            $params = ['orderId' => $orderId];
        }

        $rows = $this->em->getConnection()->executeQuery($sql, $params)->fetchAllAssociative();

        $events = [];
        foreach ($rows as $row) {
            $returnId = (int) $row['id'];
            $reason = (string) ($row['reason'] ?? '');

            // requested — always present
            $events[] = [
                'id' => 'return:' . $returnId . ':submitted',
                'type' => 'return.submitted',
                'occurred_at' => $this->formatTimestamp($row['requested_at']),
                'actor' => ['type' => 'customer', 'id' => (int) $row['customer_id']],
                'summary' => "Return RET-{$returnId} submitted ({$reason})",
                'details' => [
                    'return_id' => $returnId,
                    'reason' => $reason,
                    'status' => $row['status'],
                ],
            ];

            // decided_at maps to approved or denied based on status
            if (!empty($row['decided_at'])) {
                $isDenied = ($row['status'] ?? '') === 'denied';
                $type = $isDenied ? 'return.denied' : 'return.approved';
                $verb = $isDenied ? 'denied' : 'approved';
                $events[] = [
                    'id' => 'return:' . $returnId . ':decided',
                    'type' => $type,
                    'occurred_at' => $this->formatTimestamp($row['decided_at']),
                    'actor' => $this->buildActorBlock($row['decided_by_user_id'], null, 'admin'),
                    'summary' => "Return RET-{$returnId} {$verb}",
                    'details' => ['return_id' => $returnId],
                ];
            }

            if (!empty($row['picked_up_at'])) {
                $events[] = [
                    'id' => 'return:' . $returnId . ':picked_up',
                    'type' => 'return.picked_up',
                    'occurred_at' => $this->formatTimestamp($row['picked_up_at']),
                    'actor' => ['type' => 'admin'],
                    'summary' => "Return RET-{$returnId} picked up",
                    'details' => ['return_id' => $returnId],
                ];
            }

            if (!empty($row['delivered_to_vendor_at'])) {
                $events[] = [
                    'id' => 'return:' . $returnId . ':received_by_vendor',
                    'type' => 'return.received_by_vendor',
                    'occurred_at' => $this->formatTimestamp($row['delivered_to_vendor_at']),
                    'actor' => ['type' => 'vendor'],
                    'summary' => "Return RET-{$returnId} received by vendor",
                    'details' => ['return_id' => $returnId],
                ];
            }

            if (!empty($row['refunded_at'])) {
                $events[] = [
                    'id' => 'return:' . $returnId . ':refunded',
                    'type' => 'return.refunded',
                    'occurred_at' => $this->formatTimestamp($row['refunded_at']),
                    'actor' => ['type' => 'admin'],
                    'summary' => "Return RET-{$returnId} refunded",
                    'details' => ['return_id' => $returnId],
                ];
            }

            if (!empty($row['cancelled_at'])) {
                $events[] = [
                    'id' => 'return:' . $returnId . ':cancelled',
                    'type' => 'return.cancelled',
                    'occurred_at' => $this->formatTimestamp($row['cancelled_at']),
                    'actor' => ['type' => 'customer', 'id' => (int) $row['customer_id']],
                    'summary' => "Return RET-{$returnId} cancelled by customer",
                    'details' => ['return_id' => $returnId],
                ];
            }
        }

        return $events;
    }

    // =================================================================
    // Source 5 — order_disputes (admin-only per Q-VendorScope)
    // =================================================================

    /**
     * @return list<array<string, mixed>>
     */
    private function fetchDisputeEvents(int $orderId): array
    {
        $sql = "
            SELECT id, event_type, status, amount, currency, reason,
                   resolution_note, resolved_by_user_id, resolved_at,
                   created_at
            FROM order_disputes
            WHERE order_id = :orderId
        ";

        $rows = $this->em->getConnection()->executeQuery(
            $sql,
            ['orderId' => $orderId],
        )->fetchAllAssociative();

        $events = [];
        foreach ($rows as $row) {
            $disputeId = (int) $row['id'];

            $events[] = [
                'id' => 'dispute:' . $disputeId . ':created',
                'type' => 'dispute.created',
                'occurred_at' => $this->formatTimestamp($row['created_at']),
                'actor' => ['type' => 'system'],
                'summary' => "Dispute opened ({$row['event_type']})"
                    . ($row['amount'] !== null ? " for {$row['amount']} {$row['currency']}" : ''),
                'details' => [
                    'dispute_id' => $disputeId,
                    'event_type' => $row['event_type'],
                    'status' => $row['status'],
                    'amount' => $row['amount'],
                    'currency' => $row['currency'],
                    'reason' => $row['reason'],
                ],
            ];

            if (!empty($row['resolved_at'])) {
                $events[] = [
                    'id' => 'dispute:' . $disputeId . ':resolved',
                    'type' => 'dispute.resolved',
                    'occurred_at' => $this->formatTimestamp($row['resolved_at']),
                    'actor' => $this->buildActorBlock($row['resolved_by_user_id'], null, 'admin'),
                    'summary' => "Dispute resolved ({$row['status']})",
                    'details' => [
                        'dispute_id' => $disputeId,
                        'status' => $row['status'],
                        'resolution_note' => $row['resolution_note'],
                    ],
                ];
            }
        }

        return $events;
    }

    // =================================================================
    // Helpers
    // =================================================================

    /**
     * @param list<array<string, mixed>> $events
     * @return list<array<string, mixed>>
     */
    private function sortByOccurredAt(array $events, string $order): array
    {
        usort($events, function (array $a, array $b) use ($order): int {
            $cmp = strcmp((string) $a['occurred_at'], (string) $b['occurred_at']);
            return $order === 'asc' ? $cmp : -$cmp;
        });
        return $events;
    }

    private function formatTimestamp(mixed $raw): string
    {
        if ($raw === null) {
            return '';
        }
        if ($raw instanceof \DateTimeInterface) {
            return $raw->format(\DateTimeInterface::ATOM);
        }
        // DBAL returns timestamps as strings ('2026-05-18 10:23:45+00');
        // normalize to ISO-8601.
        try {
            $dt = new \DateTimeImmutable((string) $raw);
            return $dt->format(\DateTimeInterface::ATOM);
        } catch (\Throwable) {
            return (string) $raw;
        }
    }

    /**
     * Decode an audit log 'changes' column. DBAL hands us a string
     * for json columns; Doctrine ORM hands us a decoded array. Be
     * permissive.
     *
     * @return array<string, mixed>
     */
    private function decodeChanges(mixed $raw): array
    {
        if (is_array($raw)) {
            return $raw;
        }
        if (is_string($raw) && $raw !== '') {
            $decoded = json_decode($raw, true);
            return is_array($decoded) ? $decoded : [];
        }
        return [];
    }

    /**
     * @return array{type: string, id?: int, label?: string}
     */
    private function buildActorBlock(mixed $userId, ?string $ipAddress = null, string $defaultType = 'system'): array
    {
        if ($userId === null) {
            return ['type' => $defaultType];
        }
        $uid = (int) $userId;
        $out = ['type' => $defaultType, 'id' => $uid];
        // We don't join users here — keep the timeline query light.
        // Caller can hydrate the actor label if needed by joining
        // user_id → users.email in the serializer layer.
        return $out;
    }

    private function applyStatementTimeout(): void
    {
        try {
            $this->em->getConnection()->executeStatement(
                sprintf('SET LOCAL statement_timeout = %d', self::STATEMENT_TIMEOUT_MS),
            );
        } catch (\Throwable $e) {
            $this->logger->debug('order_timeline.timeout.skipped', [
                'reason' => $e->getMessage(),
            ]);
        }
    }

    /**
     * @param array<string, mixed> $context
     */
    private function emitTimingLogs(int $elapsedMs, array $context): void
    {
        $this->logger->debug('order_timeline.computed', array_merge(
            ['duration_ms' => $elapsedMs],
            $context,
        ));

        if ($elapsedMs > self::SLOW_THRESHOLD_MS) {
            $this->logger->warning('order_timeline.slow_response', array_merge(
                ['duration_ms' => $elapsedMs, 'threshold_ms' => self::SLOW_THRESHOLD_MS],
                $context,
            ));
        }
    }
}
