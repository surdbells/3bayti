<?php

declare(strict_types=1);

namespace Bayti\Api\Tests\Domain\Setting;

use Bayti\Api\Domain\Setting\NotificationSettings;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(NotificationSettings::class)]
final class NotificationSettingsTest extends TestCase
{
    #[Test]
    public function defaultDoesNotSuppress(): void
    {
        self::assertFalse(NotificationSettings::default()->suppressVendorItemStatus);
    }

    #[Test]
    public function nullConfigReadsAsDefault(): void
    {
        self::assertFalse(NotificationSettings::fromArray(null)->suppressVendorItemStatus);
    }

    #[Test]
    public function honoursTheStoredFlag(): void
    {
        self::assertTrue(
            NotificationSettings::fromArray(['suppress_vendor_item_status' => true])->suppressVendorItemStatus,
        );
        self::assertFalse(
            NotificationSettings::fromArray(['suppress_vendor_item_status' => false])->suppressVendorItemStatus,
        );
    }

    #[Test]
    public function missingKeyDefaultsToNotSuppressed(): void
    {
        self::assertFalse(NotificationSettings::fromArray(['something_else' => true])->suppressVendorItemStatus);
    }

    #[Test]
    public function toArrayRoundTrips(): void
    {
        $s = NotificationSettings::fromArray(['suppress_vendor_item_status' => true]);
        self::assertSame(['suppress_vendor_item_status' => true], $s->toArray());
        self::assertEquals($s, NotificationSettings::fromArray($s->toArray()));
    }
}
