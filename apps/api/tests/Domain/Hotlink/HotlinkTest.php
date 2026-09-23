<?php

declare(strict_types=1);

namespace Bayti\Api\Tests\Domain\Hotlink;

use Bayti\Api\Domain\Hotlink\Hotlink;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(Hotlink::class)]
final class HotlinkTest extends TestCase
{
    #[Test]
    public function constructsAStoreHotlink(): void
    {
        $h = new Hotlink('abcd1234', Hotlink::TARGET_STORE, 'atelier-noor');
        self::assertSame('abcd1234', $h->getCode());
        self::assertSame(Hotlink::TARGET_STORE, $h->getTargetType());
        self::assertSame('atelier-noor', $h->getTargetSlug());
        self::assertSame(0, $h->getClickCount());
        self::assertNull($h->getCreatedByUser());
    }

    #[Test]
    public function constructsAStyleHotlink(): void
    {
        $h = new Hotlink('xyz9', Hotlink::TARGET_STYLE, 'eid-look-a1b2c3d4');
        self::assertSame(Hotlink::TARGET_STYLE, $h->getTargetType());
    }

    #[Test]
    public function rejectsUnknownTargetType(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new Hotlink('abcd1234', 'product', 'silk-abaya');
    }

    #[Test]
    public function recordClickIncrementsCount(): void
    {
        $h = new Hotlink('abcd1234', Hotlink::TARGET_STORE, 'atelier-noor');
        $h->recordClick();
        $h->recordClick();
        self::assertSame(2, $h->getClickCount());
    }
}
