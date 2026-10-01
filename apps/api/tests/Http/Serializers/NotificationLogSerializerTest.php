<?php

declare(strict_types=1);

namespace Bayti\Api\Tests\Http\Serializers;

use Bayti\Api\Domain\Notification\NotificationLog;
use Bayti\Api\Http\Serializers\NotificationLogSerializer;
use DateTimeImmutable;
use Doctrine\Instantiator\Instantiator;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Regression cover for PHP-22: GET /v3/admin/notification-logs 500'd because a
 * push-channel row (PushNotificationLogger inserts recipient = NULL) hydrated
 * the typed `recipient` property as UNINITIALIZED, so getRecipient() threw
 * "Typed property … must not be accessed before initialization" inside the
 * serializer.
 *
 * These tests instantiate the entity the way Doctrine's hydrator does — via
 * doctrine/instantiator, which bypasses both the constructor AND the property
 * default — so an unset `recipient` faithfully reproduces the production state
 * a normal `new NotificationLog(...)` can never produce.
 */
#[CoversClass(NotificationLogSerializer::class)]
#[CoversClass(NotificationLog::class)]
final class NotificationLogSerializerTest extends TestCase
{
    private NotificationLogSerializer $serializer;

    protected function setUp(): void
    {
        $this->serializer = new NotificationLogSerializer();
    }

    #[Test]
    public function serializesAPushRowWithNoRecipientWithoutFataling(): void
    {
        // A push row as PushNotificationLogger writes it: recipient left unset
        // (NULL in the DB), every other serialized field populated.
        $log = $this->hydrate(recipientSet: false);

        $shape = $this->serializer->adminShape($log);

        self::assertSame('', $shape['recipient'], 'a null/unset recipient surfaces as an empty string, not a fatal');
        self::assertSame('push.cart.abandoned', $shape['template']);
        self::assertSame(NotificationLog::STATUS_SENT, $shape['status']);
    }

    #[Test]
    public function adminShapeManyHandlesAMixOfEmailAndPushRows(): void
    {
        $emailRow = $this->hydrate(recipientSet: true, recipient: 'buyer@example.com');
        $pushRow = $this->hydrate(recipientSet: false);

        $rows = $this->serializer->adminShapeMany([$emailRow, $pushRow]);

        self::assertCount(2, $rows);
        self::assertSame('buyer@example.com', $rows[0]['recipient']);
        self::assertSame('', $rows[1]['recipient']);
    }

    #[Test]
    public function getRecipientNeverThrowsOnAnUnhydratedRecipient(): void
    {
        $log = $this->hydrate(recipientSet: false);

        // The exact call that fataled in PHP-22.
        self::assertSame('', $log->getRecipient());
    }

    /**
     * Build a NotificationLog in the same state Doctrine's hydrator leaves it:
     * constructor + property defaults bypassed, mapped fields set by reflection.
     * When $recipientSet is false the `recipient` property is left untouched —
     * exactly the uninitialized/NULL state a push row produces.
     */
    private function hydrate(bool $recipientSet, string $recipient = ''): NotificationLog
    {
        $log = (new Instantiator())->instantiate(NotificationLog::class);

        $now = new DateTimeImmutable('2026-10-01T16:31:00+00:00');
        $this->set($log, 'id', 123);
        $this->set($log, 'orderId', null);
        $this->set($log, 'cartId', 42);
        $this->set($log, 'template', 'push.cart.abandoned');
        $this->set($log, 'status', NotificationLog::STATUS_SENT);
        $this->set($log, 'sentAt', $now);
        $this->set($log, 'errorKind', null);
        $this->set($log, 'errorMessage', null);
        $this->set($log, 'rawEvent', null);
        $this->set($log, 'createdAt', $now);
        $this->set($log, 'updatedAt', $now);
        $this->set($log, 'isRead', false);
        $this->set($log, 'readAt', null);
        if ($recipientSet) {
            $this->set($log, 'recipient', $recipient);
        }

        return $log;
    }

    private function set(object $entity, string $property, mixed $value): void
    {
        $ref = new \ReflectionProperty(NotificationLog::class, $property);
        $ref->setAccessible(true);
        $ref->setValue($entity, $value);
    }
}
