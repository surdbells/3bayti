<?php

declare(strict_types=1);

namespace Bayti\Api\Tests\Domain\Notification;

use Bayti\Api\Domain\Notification\NotificationLog;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Coverage for NotificationLog entity (sub-phase A of M3.2.X.4).
 *
 * Locks the status taxonomy, factory contracts, and immutability
 * guarantees that NotificationLogRepository::save() + the admin
 * endpoint depend on.
 */
#[CoversClass(NotificationLog::class)]
final class NotificationLogTest extends TestCase
{
    #[Test]
    public function sentFactoryProducesSentRowWithNoError(): void
    {
        $log = NotificationLog::sent(
            orderId: 42,
            template: 'order.placed.customer',
            recipient: 'alice@example.com',
        );

        self::assertSame(NotificationLog::STATUS_SENT, $log->getStatus());
        self::assertSame(42, $log->getOrderId());
        self::assertSame('order.placed.customer', $log->getTemplate());
        self::assertSame('alice@example.com', $log->getRecipient());
        self::assertNull($log->getErrorKind(), 'sent rows have no error_kind');
        self::assertNull($log->getErrorMessage(), 'sent rows have no error_message');
        self::assertNull($log->getRawEvent(), 'raw_event null until future webhook');
    }

    #[Test]
    public function failedFactoryPopulatesErrorFields(): void
    {
        $log = NotificationLog::failed(
            orderId: 42,
            template: 'order.placed.customer',
            recipient: 'alice@example.com',
            errorKind: 'transport',
            errorMessage: 'HTTP 503 from ZeptoMail',
        );

        self::assertSame(NotificationLog::STATUS_FAILED, $log->getStatus());
        self::assertSame('transport', $log->getErrorKind());
        self::assertSame('HTTP 503 from ZeptoMail', $log->getErrorMessage());
    }

    #[Test]
    public function skippedFactoryPopulatesReasonAsErrorMessage(): void
    {
        $log = NotificationLog::skipped(
            orderId: 42,
            template: 'order.placed.vendor',
            recipient: '',
            reason: 'no_email',
        );

        self::assertSame(NotificationLog::STATUS_SKIPPED, $log->getStatus());
        self::assertNull(
            $log->getErrorKind(),
            'skipped rows distinguish from failed via NULL error_kind',
        );
        self::assertSame(
            'no_email',
            $log->getErrorMessage(),
            'skipped reason short-code stored in error_message',
        );
    }

    #[Test]
    public function orderIdAcceptsNullForFutureNonOrderNotifications(): void
    {
        // M3.2.X.4 always populates orderId. The column is nullable
        // so future non-order notifications (account events, password
        // resets, etc.) can land here too without a schema migration.
        $log = NotificationLog::sent(
            orderId: null,
            template: 'system.healthcheck',
            recipient: 'ops@example.com',
        );

        self::assertNull($log->getOrderId());
    }

    #[Test]
    public function timestampsSetOnConstruction(): void
    {
        $before = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
        $log = NotificationLog::sent(
            orderId: 1,
            template: 'order.placed.customer',
            recipient: 'a@b.test',
        );
        $after = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));

        self::assertGreaterThanOrEqual($before, $log->getSentAt());
        self::assertLessThanOrEqual($after, $log->getSentAt());
        self::assertGreaterThanOrEqual($before, $log->getCreatedAt());
        self::assertGreaterThanOrEqual($before, $log->getUpdatedAt());

        // sentAt and createdAt should be set to the same moment
        self::assertEquals($log->getSentAt(), $log->getCreatedAt());
    }

    #[Test]
    public function statusConstantsExposed(): void
    {
        self::assertSame('sent', NotificationLog::STATUS_SENT);
        self::assertSame('failed', NotificationLog::STATUS_FAILED);
        self::assertSame('skipped', NotificationLog::STATUS_SKIPPED);
        self::assertSame(
            ['sent', 'failed', 'skipped'],
            NotificationLog::ALL_STATUSES,
            'ALL_STATUSES exposes the full set for validators (admin endpoint)',
        );
    }

    #[Test]
    public function setRawEventUpdatesPayloadAndTimestamp(): void
    {
        $log = NotificationLog::sent(
            orderId: 1,
            template: 'order.placed.customer',
            recipient: 'a@b.test',
        );
        $originalUpdatedAt = $log->getUpdatedAt();

        // Sleep briefly to ensure timestamp tick
        usleep(2000);

        $log->setRawEvent(['event_type' => 'bounce', 'bounce_type' => 'hard']);

        self::assertSame(
            ['event_type' => 'bounce', 'bounce_type' => 'hard'],
            $log->getRawEvent(),
        );
        self::assertGreaterThan(
            $originalUpdatedAt,
            $log->getUpdatedAt(),
            'updatedAt advances when rawEvent is set',
        );
    }

    #[Test]
    public function idIsNullBeforePersist(): void
    {
        $log = NotificationLog::sent(
            orderId: 1,
            template: 'order.placed.customer',
            recipient: 'a@b.test',
        );

        self::assertNull(
            $log->getId(),
            'id remains null until EntityManager assigns the IDENTITY value',
        );
    }
}
