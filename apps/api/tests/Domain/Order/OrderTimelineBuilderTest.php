<?php

declare(strict_types=1);

namespace Bayti\Api\Tests\Domain\Order;

use Bayti\Api\Domain\Order\OrderTimelineBuilder;
use Bayti\Api\Tests\Support\InMemoryLogger;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Result;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for OrderTimelineBuilder (M3.2.X.17-A).
 *
 * Approach: mock Doctrine\\DBAL\\Connection::executeQuery to return
 * canned rows for each source query in order. The builder issues
 * queries in a deterministic sequence:
 *
 *   1. orders (entity-derived)
 *   2. audit_log
 *   3. notification_logs (+ optional vendors lookup if vendor filter set)
 *   4. order_return_requests
 *   5. order_disputes (admin scope only)
 */
#[CoversClass(OrderTimelineBuilder::class)]
final class OrderTimelineBuilderTest extends TestCase
{
    // =================================================================
    // Source aggregation
    // =================================================================

    #[Test]
    public function aggregatesAllFiveSourcesInOneTimeline(): void
    {
        $captured = $this->captureQueries([
            // 1. orders entity row
            ['id' => '1234', 'order_reference' => 'V3-XYZ-001',
             'created_at' => '2026-05-01 08:00:00+00',
             'paid_at' => '2026-05-01 08:05:00+00'],
            // 2. audit_log rows
            [
                ['id' => '500', 'user_id' => '42', 'subject_type' => 'Order',
                 'subject_id' => '1234', 'action' => 'overridden',
                 'changes' => '{"before":{"status":"paid"},"after":{"status":"fulfilling"}}',
                 'created_at' => '2026-05-02 10:00:00+00',
                 'ip_address' => '1.2.3.4', 'item_vendor_id' => null,
                 'order_reference' => 'V3-XYZ-001'],
            ],
            // 3. notification_logs rows
            [
                ['id' => '900', 'order_id' => '1234',
                 'template' => 'order.paid.customer',
                 'recipient' => 'customer@example.com', 'status' => 'sent',
                 'sent_at' => '2026-05-01 08:05:10+00',
                 'last_failure_reason' => null, 'context' => '{}',
                 'created_at' => '2026-05-01 08:05:10+00'],
            ],
            // 4. order_return_requests rows
            [
                ['id' => '42', 'status' => 'approved', 'reason' => 'defective',
                 'customer_id' => '100',
                 'requested_at' => '2026-05-05 14:00:00',
                 'decided_at' => '2026-05-06 09:30:00',
                 'decided_by_user_id' => '42',
                 'picked_up_at' => null,
                 'delivered_to_vendor_at' => null,
                 'refunded_at' => null,
                 'cancelled_at' => null],
            ],
            // 5. order_disputes rows
            [
                ['id' => '8', 'event_type' => 'chargeback', 'status' => 'pending',
                 'amount' => '99.00', 'currency' => 'AED',
                 'reason' => 'Customer claim', 'resolution_note' => null,
                 'resolved_by_user_id' => null, 'resolved_at' => null,
                 'created_at' => '2026-05-07 12:00:00+00'],
            ],
        ]);

        $builder = new OrderTimelineBuilder($captured['em'], new InMemoryLogger());
        $result = $builder->build(1234);

        // 2 order events + 1 audit + 1 notification + 2 return events
        // (submitted + decided) + 1 dispute event = 7
        self::assertSame(7, $result['total']);

        // Default newest-first ordering: dispute (May 7) before
        // return.approved (May 6) before return.submitted (May 5)
        // before audit (May 2) before notification (May 1 08:05:10)
        // before order.paid (May 1 08:05:00) before order.created (May 1 08:00:00)
        $types = array_column($result['events'], 'type');
        self::assertSame([
            'dispute.created',
            'return.approved',
            'return.submitted',
            'order.status_changed',
            'notification.sent',
            'order.paid',
            'order.created',
        ], $types);
    }

    #[Test]
    public function missingOrderReturnsEmptyResult(): void
    {
        $captured = $this->captureQueries([
            false,    // orders query returns no row
            [],       // audit
            [],       // notifications
            [],       // returns
            [],       // disputes
        ]);

        $result = (new OrderTimelineBuilder($captured['em'], new InMemoryLogger()))
            ->build(99999);

        self::assertSame(0, $result['total']);
        self::assertSame([], $result['events']);
    }

    // =================================================================
    // Event shape
    // =================================================================

    #[Test]
    public function entityDerivedEventsCarryCorrectShape(): void
    {
        $captured = $this->captureQueries([
            ['id' => '1234', 'order_reference' => 'V3-XYZ-001',
             'created_at' => '2026-05-01 08:00:00+00',
             'paid_at' => '2026-05-01 08:05:00+00'],
            [], [], [], [],
        ]);

        $result = (new OrderTimelineBuilder($captured['em'], new InMemoryLogger()))
            ->build(1234, order: 'asc');

        // order.created should be first in asc order
        $created = $result['events'][0];
        self::assertSame('order:created', $created['id']);
        self::assertSame('order.created', $created['type']);
        self::assertSame('system', $created['actor']['type']);
        self::assertStringContainsString('V3-XYZ-001', $created['summary']);

        $paid = $result['events'][1];
        self::assertSame('order:paid', $paid['id']);
        self::assertSame('order.paid', $paid['type']);
    }

    #[Test]
    public function orderStatusChangeEventClassifiedCorrectly(): void
    {
        $captured = $this->captureQueries([
            ['id' => '1234', 'order_reference' => 'V3-XYZ-001',
             'created_at' => '2026-05-01 08:00:00+00', 'paid_at' => null],
            [
                ['id' => '500', 'user_id' => '42', 'subject_type' => 'Order',
                 'subject_id' => '1234', 'action' => 'overridden',
                 'changes' => '{"before":{"status":"paid"},"after":{"status":"cancelled"}}',
                 'created_at' => '2026-05-02 10:00:00+00',
                 'ip_address' => null, 'item_vendor_id' => null,
                 'order_reference' => 'V3-XYZ-001'],
            ],
            [], [], [],
        ]);

        $result = (new OrderTimelineBuilder($captured['em'], new InMemoryLogger()))
            ->build(1234);

        $statusEvent = null;
        foreach ($result['events'] as $e) {
            if ($e['type'] === 'order.status_changed') {
                $statusEvent = $e;
                break;
            }
        }
        self::assertNotNull($statusEvent);
        self::assertSame('audit:500', $statusEvent['id']);
        self::assertStringContainsString('paid', $statusEvent['summary']);
        self::assertStringContainsString('cancelled', $statusEvent['summary']);
        // Actor is the user_id 42 admin
        self::assertSame('system', $statusEvent['actor']['type']);  // defaultType from buildActorBlock
        self::assertSame(42, $statusEvent['actor']['id']);
    }

    #[Test]
    public function orderItemStatusChangeClassifiedCorrectly(): void
    {
        $captured = $this->captureQueries([
            ['id' => '1234', 'order_reference' => 'V3-X', 'created_at' => '2026-05-01 08:00:00+00', 'paid_at' => null],
            [
                ['id' => '501', 'user_id' => '42', 'subject_type' => 'OrderItem',
                 'subject_id' => '5000', 'action' => 'updated',
                 'changes' => '{"before":{"item_status":"pending"},"after":{"item_status":"accepted"}}',
                 'created_at' => '2026-05-02 10:00:00+00',
                 'ip_address' => null, 'item_vendor_id' => '101',
                 'order_reference' => 'V3-X'],
            ],
            [], [], [],
        ]);

        $result = (new OrderTimelineBuilder($captured['em'], new InMemoryLogger()))
            ->build(1234);

        $itemEvent = null;
        foreach ($result['events'] as $e) {
            if ($e['type'] === 'order.item_status_changed') {
                $itemEvent = $e;
                break;
            }
        }
        self::assertNotNull($itemEvent);
        self::assertStringContainsString('pending', $itemEvent['summary']);
        self::assertStringContainsString('accepted', $itemEvent['summary']);
    }

    #[Test]
    public function notificationSentVsFailedClassified(): void
    {
        $captured = $this->captureQueries([
            ['id' => '1234', 'order_reference' => 'V3-X',
             'created_at' => '2026-05-01 08:00:00+00', 'paid_at' => null],
            [],
            [
                ['id' => '900', 'order_id' => '1234',
                 'template' => 'order.paid.customer',
                 'recipient' => 'a@b.com', 'status' => 'sent',
                 'sent_at' => '2026-05-01 09:00:00+00',
                 'last_failure_reason' => null, 'context' => '{}',
                 'created_at' => '2026-05-01 09:00:00+00'],
                ['id' => '901', 'order_id' => '1234',
                 'template' => 'order.delivered.customer',
                 'recipient' => 'a@b.com', 'status' => 'failed',
                 'sent_at' => null,
                 'last_failure_reason' => 'SMTP timeout', 'context' => '{}',
                 'created_at' => '2026-05-02 10:00:00+00'],
            ],
            [], [],
        ]);

        $result = (new OrderTimelineBuilder($captured['em'], new InMemoryLogger()))
            ->build(1234);

        $types = array_column($result['events'], 'type');
        self::assertContains('notification.sent', $types);
        self::assertContains('notification.failed', $types);

        // Failed event includes failure_reason
        foreach ($result['events'] as $e) {
            if ($e['type'] === 'notification.failed') {
                self::assertSame('SMTP timeout', $e['details']['failure_reason']);
            }
        }
    }

    #[Test]
    public function returnLifecycleEmitsMultipleEvents(): void
    {
        // A fully-lifecycle'd return: submitted → approved →
        // picked_up → delivered → refunded = 5 events
        $captured = $this->captureQueries([
            ['id' => '1234', 'order_reference' => 'V3-X',
             'created_at' => '2026-05-01 08:00:00+00', 'paid_at' => null],
            [], [],
            [
                ['id' => '42', 'status' => 'refunded', 'reason' => 'defective',
                 'customer_id' => '100',
                 'requested_at' => '2026-05-05 14:00:00',
                 'decided_at' => '2026-05-06 09:30:00',
                 'decided_by_user_id' => '42',
                 'picked_up_at' => '2026-05-07 11:00:00',
                 'delivered_to_vendor_at' => '2026-05-08 15:00:00',
                 'refunded_at' => '2026-05-10 10:00:00',
                 'cancelled_at' => null],
            ],
            [],
        ]);

        $result = (new OrderTimelineBuilder($captured['em'], new InMemoryLogger()))
            ->build(1234);

        $returnTypes = array_values(array_filter(
            array_column($result['events'], 'type'),
            fn (string $t): bool => str_starts_with($t, 'return.'),
        ));

        self::assertContains('return.submitted', $returnTypes);
        self::assertContains('return.approved', $returnTypes);
        self::assertContains('return.picked_up', $returnTypes);
        self::assertContains('return.received_by_vendor', $returnTypes);
        self::assertContains('return.refunded', $returnTypes);
        self::assertCount(5, $returnTypes);
    }

    #[Test]
    public function deniedReturnEmitsReturnDeniedNotApproved(): void
    {
        $captured = $this->captureQueries([
            ['id' => '1234', 'order_reference' => 'V3-X',
             'created_at' => '2026-05-01 08:00:00+00', 'paid_at' => null],
            [], [],
            [
                ['id' => '42', 'status' => 'denied', 'reason' => 'defective',
                 'customer_id' => '100',
                 'requested_at' => '2026-05-05 14:00:00',
                 'decided_at' => '2026-05-06 09:30:00',
                 'decided_by_user_id' => '42',
                 'picked_up_at' => null, 'delivered_to_vendor_at' => null,
                 'refunded_at' => null, 'cancelled_at' => null],
            ],
            [],
        ]);

        $result = (new OrderTimelineBuilder($captured['em'], new InMemoryLogger()))
            ->build(1234);

        $types = array_column($result['events'], 'type');
        self::assertContains('return.denied', $types);
        self::assertNotContains('return.approved', $types);
    }

    #[Test]
    public function disputeWithResolutionEmitsTwoEvents(): void
    {
        $captured = $this->captureQueries([
            ['id' => '1234', 'order_reference' => 'V3-X',
             'created_at' => '2026-05-01 08:00:00+00', 'paid_at' => null],
            [], [], [],
            [
                ['id' => '8', 'event_type' => 'chargeback', 'status' => 'won',
                 'amount' => '99.00', 'currency' => 'AED',
                 'reason' => 'Customer claim',
                 'resolution_note' => 'Provided evidence',
                 'resolved_by_user_id' => '42',
                 'resolved_at' => '2026-05-10 14:00:00+00',
                 'created_at' => '2026-05-07 12:00:00+00'],
            ],
        ]);

        $result = (new OrderTimelineBuilder($captured['em'], new InMemoryLogger()))
            ->build(1234);

        $disputeEvents = array_values(array_filter(
            $result['events'],
            fn (array $e): bool => str_starts_with($e['type'], 'dispute.'),
        ));

        self::assertCount(2, $disputeEvents);
        $types = array_column($disputeEvents, 'type');
        self::assertContains('dispute.created', $types);
        self::assertContains('dispute.resolved', $types);
    }

    // =================================================================
    // Vendor filter (Q-VendorScope)
    // =================================================================

    #[Test]
    public function vendorFilterExcludesDisputeEvents(): void
    {
        $captured = $this->captureQueriesWithVendorEmail([
            ['id' => '1234', 'order_reference' => 'V3-X',
             'created_at' => '2026-05-01 08:00:00+00', 'paid_at' => null],
            [], [], [],
            // No disputes query is made when vendor filter set
        ], vendorEmail: 'vendor@example.com');

        $result = (new OrderTimelineBuilder($captured['em'], new InMemoryLogger()))
            ->build(1234, vendorIdFilter: 101);

        $types = array_column($result['events'], 'type');
        foreach ($types as $type) {
            self::assertStringStartsNotWith('dispute.', $type);
        }
    }

    #[Test]
    public function vendorFilterDropsAuditRowsOnOtherVendorItems(): void
    {
        $captured = $this->captureQueriesWithVendorEmail([
            ['id' => '1234', 'order_reference' => 'V3-X',
             'created_at' => '2026-05-01 08:00:00+00', 'paid_at' => null],
            [
                // Audit on this vendor's item — kept
                ['id' => '501', 'user_id' => '42', 'subject_type' => 'OrderItem',
                 'subject_id' => '5000', 'action' => 'updated',
                 'changes' => '{"before":{"item_status":"pending"},"after":{"item_status":"accepted"}}',
                 'created_at' => '2026-05-02 10:00:00+00',
                 'ip_address' => null, 'item_vendor_id' => '101',
                 'order_reference' => 'V3-X'],
                // Audit on OTHER vendor's item — dropped
                ['id' => '502', 'user_id' => '42', 'subject_type' => 'OrderItem',
                 'subject_id' => '5001', 'action' => 'updated',
                 'changes' => '{"before":{"item_status":"pending"},"after":{"item_status":"accepted"}}',
                 'created_at' => '2026-05-02 10:01:00+00',
                 'ip_address' => null, 'item_vendor_id' => '202',
                 'order_reference' => 'V3-X'],
                // Audit on Order subject — also dropped (item_vendor_id is null)
                ['id' => '503', 'user_id' => '42', 'subject_type' => 'Order',
                 'subject_id' => '1234', 'action' => 'overridden',
                 'changes' => '{"before":{"status":"paid"},"after":{"status":"cancelled"}}',
                 'created_at' => '2026-05-02 10:02:00+00',
                 'ip_address' => null, 'item_vendor_id' => null,
                 'order_reference' => 'V3-X'],
            ],
            [], [],
        ], vendorEmail: 'vendor@example.com');

        $result = (new OrderTimelineBuilder($captured['em'], new InMemoryLogger()))
            ->build(1234, vendorIdFilter: 101);

        $auditIds = array_values(array_filter(
            array_column($result['events'], 'id'),
            fn (string $id): bool => str_starts_with($id, 'audit:'),
        ));

        // Only the audit on this vendor's item survives
        self::assertSame(['audit:501'], $auditIds);
    }

    #[Test]
    public function vendorFilterDropsNotificationsToOtherRecipients(): void
    {
        $captured = $this->captureQueriesWithVendorEmail([
            ['id' => '1234', 'order_reference' => 'V3-X',
             'created_at' => '2026-05-01 08:00:00+00', 'paid_at' => null],
            [],
            [
                // Sent to the vendor — kept
                ['id' => '900', 'order_id' => '1234',
                 'template' => 'order.paid.vendor',
                 'recipient' => 'vendor@example.com', 'status' => 'sent',
                 'sent_at' => '2026-05-01 09:00:00+00',
                 'last_failure_reason' => null, 'context' => '{}',
                 'created_at' => '2026-05-01 09:00:00+00'],
                // Sent to the customer — dropped
                ['id' => '901', 'order_id' => '1234',
                 'template' => 'order.paid.customer',
                 'recipient' => 'customer@example.com', 'status' => 'sent',
                 'sent_at' => '2026-05-01 09:00:01+00',
                 'last_failure_reason' => null, 'context' => '{}',
                 'created_at' => '2026-05-01 09:00:01+00'],
            ],
            [], [],
        ], vendorEmail: 'vendor@example.com');

        $result = (new OrderTimelineBuilder($captured['em'], new InMemoryLogger()))
            ->build(1234, vendorIdFilter: 101);

        $notificationIds = array_values(array_filter(
            array_column($result['events'], 'id'),
            fn (string $id): bool => str_starts_with($id, 'notification:'),
        ));

        self::assertSame(['notification:900'], $notificationIds);
    }

    #[Test]
    public function vendorFilterUsesJoinedReturnQuery(): void
    {
        // When vendor filter is set, the return query has a different
        // SQL shape (INNER JOIN on items) — verify it runs and returns
        // the expected event.
        $captured = $this->captureQueriesWithVendorEmail([
            ['id' => '1234', 'order_reference' => 'V3-X',
             'created_at' => '2026-05-01 08:00:00+00', 'paid_at' => null],
            [], [],
            [
                ['id' => '42', 'status' => 'approved', 'reason' => 'defective',
                 'customer_id' => '100',
                 'requested_at' => '2026-05-05 14:00:00',
                 'decided_at' => null,  // not yet decided
                 'decided_by_user_id' => null,
                 'picked_up_at' => null, 'delivered_to_vendor_at' => null,
                 'refunded_at' => null, 'cancelled_at' => null],
            ],
        ], vendorEmail: 'vendor@example.com');

        $result = (new OrderTimelineBuilder($captured['em'], new InMemoryLogger()))
            ->build(1234, vendorIdFilter: 101);

        // The SQL captured for the return query should reference oi.vendor_id
        $returnQuery = $captured['queries'][3];   // 0=orders, 1=audit, 2=vendor email lookup, 3=notifications... wait
        // Actually: when vendor filter is set, the call order is:
        //   0. orders
        //   1. audit
        //   2. vendors (contact_email lookup)
        //   3. notifications
        //   4. returns (with INNER JOIN)
        $returnQuery = $captured['queries'][4];
        self::assertStringContainsString('oi.vendor_id', $returnQuery['sql']);
        self::assertSame(101, $returnQuery['params']['vendorId']);

        // Event was produced
        $types = array_column($result['events'], 'type');
        self::assertContains('return.submitted', $types);
    }

    // =================================================================
    // Ordering + pagination
    // =================================================================

    #[Test]
    public function ascOrderInvertsSequence(): void
    {
        $captured = $this->captureQueries([
            ['id' => '1234', 'order_reference' => 'V3-X',
             'created_at' => '2026-05-01 08:00:00+00',
             'paid_at' => '2026-05-01 09:00:00+00'],
            [], [], [], [],
        ]);

        $resultAsc = (new OrderTimelineBuilder($captured['em'], new InMemoryLogger()))
            ->build(1234, order: 'asc');
        $typesAsc = array_column($resultAsc['events'], 'type');

        self::assertSame(['order.created', 'order.paid'], $typesAsc);
    }

    #[Test]
    public function paginationSlicesAfterMergeSort(): void
    {
        // First call: limit=1, offset=0 → first event only
        $captured1 = $this->captureQueries([
            ['id' => '1234', 'order_reference' => 'V3-X',
             'created_at' => '2026-05-01 08:00:00+00',
             'paid_at' => '2026-05-01 09:00:00+00'],
            [], [], [], [],
        ]);
        $result = (new OrderTimelineBuilder($captured1['em'], new InMemoryLogger()))
            ->build(1234, limit: 1, offset: 0);

        self::assertSame(2, $result['total']);
        self::assertCount(1, $result['events']);
        // Default desc order: order.paid is first
        self::assertSame('order.paid', $result['events'][0]['type']);

        // Second call: offset=1, limit=10 → second event
        // (fresh captured handle since the connection mock consumed
        // all its canned rows on the first build call)
        $captured2 = $this->captureQueries([
            ['id' => '1234', 'order_reference' => 'V3-X',
             'created_at' => '2026-05-01 08:00:00+00',
             'paid_at' => '2026-05-01 09:00:00+00'],
            [], [], [], [],
        ]);
        $result2 = (new OrderTimelineBuilder($captured2['em'], new InMemoryLogger()))
            ->build(1234, limit: 10, offset: 1);

        self::assertCount(1, $result2['events']);
        self::assertSame('order.created', $result2['events'][0]['type']);
    }

    // =================================================================
    // Observability
    // =================================================================

    #[Test]
    public function emitsTimingLogOnEveryBuild(): void
    {
        $logger = new InMemoryLogger();
        $captured = $this->captureQueries([
            ['id' => '1234', 'order_reference' => 'V3-X',
             'created_at' => '2026-05-01 08:00:00+00', 'paid_at' => null],
            [], [], [], [],
        ]);

        (new OrderTimelineBuilder($captured['em'], $logger))->build(1234);

        $records = $logger->findByMessage('order_timeline.computed');
        self::assertCount(1, $records);
        self::assertSame('debug', $records[0]['level']);
        self::assertSame(1234, $records[0]['context']['order_id']);
        self::assertNull($records[0]['context']['vendor_id_filter']);
        self::assertArrayHasKey('duration_ms', $records[0]['context']);
        self::assertSame(1, $records[0]['context']['total_events']);
    }

    #[Test]
    public function emitsSlowResponseWarningWhenOver300Ms(): void
    {
        $logger = new InMemoryLogger();
        $sleeps = [310_000, 0, 0, 0, 0];
        $callIdx = 0;
        $connection = $this->createMock(Connection::class);
        $connection->method('executeQuery')->willReturnCallback(
            function () use (&$callIdx, $sleeps) {
                $delay = $sleeps[$callIdx] ?? 0;
                $callIdx++;
                if ($delay > 0) {
                    usleep($delay);
                }
                $r = $this->createMock(Result::class);
                $r->method('fetchAssociative')->willReturn(false);
                $r->method('fetchAllAssociative')->willReturn([]);
                return $r;
            },
        );
        $em = $this->emWithConnection($connection);

        (new OrderTimelineBuilder($em, $logger))->build(1234);

        $slow = $logger->findByMessage('order_timeline.slow_response');
        self::assertCount(1, $slow);
        self::assertSame('warning', $slow[0]['level']);
        self::assertGreaterThan(300, $slow[0]['context']['duration_ms']);
        self::assertSame(300, $slow[0]['context']['threshold_ms']);
    }

    #[Test]
    public function timeoutFailureIsLoggedNotPropagated(): void
    {
        $logger = new InMemoryLogger();
        $connection = $this->createMock(Connection::class);
        $connection->method('executeStatement')->willThrowException(
            new \RuntimeException('driver does not support statement_timeout'),
        );
        $connection->method('executeQuery')->willReturnCallback(
            function () {
                $r = $this->createMock(Result::class);
                $r->method('fetchAssociative')->willReturn(false);
                $r->method('fetchAllAssociative')->willReturn([]);
                return $r;
            },
        );
        $em = $this->emWithConnection($connection);

        $result = (new OrderTimelineBuilder($em, $logger))->build(1234);

        self::assertSame(0, $result['total']);
        $skipped = $logger->findByMessage('order_timeline.timeout.skipped');
        self::assertCount(1, $skipped);
    }

    // =================================================================
    // Helpers
    // =================================================================

    /**
     * Build a Connection mock for the admin-scope (no vendor filter)
     * call sequence: orders → audit → notifications → returns → disputes.
     *
     * @param list<array<string, mixed>|list<array<string, mixed>>|false> $resultRows
     *        - First entry: associative row from orders query (or false for missing order)
     *        - Subsequent entries: list-of-rows for fetchAllAssociative
     * @return array{em: EntityManagerInterface, queries: list<array{sql: string, params: array<string, mixed>}>}
     */
    private function captureQueries(array $resultRows): array
    {
        $queries = [];
        $callIndex = 0;

        $connection = $this->createMock(Connection::class);
        $connection->method('executeQuery')->willReturnCallback(
            function (string $sql, array $params) use (&$queries, &$callIndex, $resultRows): Result {
                $queries[] = ['sql' => $sql, 'params' => $params];
                $row = $resultRows[$callIndex] ?? [];
                $callIndex++;

                $result = $this->createMock(Result::class);
                if ($row === false) {
                    $result->method('fetchAssociative')->willReturn(false);
                    $result->method('fetchAllAssociative')->willReturn([]);
                    return $result;
                }
                // Heuristic: if the value is associative (string keys),
                // treat as single row; if it's a list (int keys), treat as multi.
                if (is_array($row) && !empty($row) && !array_is_list($row)) {
                    // Single associative row — for fetchAssociative
                    $result->method('fetchAssociative')->willReturn($row);
                    $result->method('fetchAllAssociative')->willReturn([]);
                } else {
                    // List of rows — for fetchAllAssociative
                    $result->method('fetchAssociative')->willReturn(false);
                    $result->method('fetchAllAssociative')->willReturn($row);
                }
                return $result;
            },
        );

        $em = $this->emWithConnection($connection);
        return ['em' => $em, 'queries' => &$queries];
    }

    /**
     * Vendor-scoped capture: the builder inserts a contact_email
     * lookup query between audit and notifications. We splice the
     * vendor email row in at index 2.
     *
     * Call sequence: orders → audit → vendors(contact_email) →
     * notifications → returns. (No disputes — vendor scope skips them.)
     *
     * @param list<array<string, mixed>|list<array<string, mixed>>|false> $resultRows
     * @return array{em: EntityManagerInterface, queries: list<array{sql: string, params: array<string, mixed>}>}
     */
    private function captureQueriesWithVendorEmail(array $resultRows, string $vendorEmail): array
    {
        // Splice the contact_email row at the right position.
        // Actual call sequence in fetchNotificationEvents:
        //   1. notifications query runs FIRST (via executeQuery)
        //   2. THEN fetchVendorContactEmail fires the vendors query
        // So the full order is: [orders, audit, notifications, vendors, returns]
        // Caller passes: [orders, audit, notifications, returns]
        // We insert at index 3 (between notifications and returns).
        $vendorRow = ['contact_email' => $vendorEmail];
        array_splice($resultRows, 3, 0, [$vendorRow]);
        return $this->captureQueries($resultRows);
    }

    private function emWithConnection(Connection $connection): EntityManagerInterface
    {
        $em = $this->createMock(EntityManagerInterface::class);
        $em->method('getConnection')->willReturn($connection);
        return $em;
    }
}
