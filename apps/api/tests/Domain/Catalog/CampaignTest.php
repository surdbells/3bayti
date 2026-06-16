<?php

declare(strict_types=1);

namespace Bayti\Api\Tests\Domain\Catalog;

use Bayti\Api\Domain\Catalog\Campaign;
use DateTimeImmutable;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for the Campaign entity's pure logic (no DB):
 * active-window evaluation, type normalisation, discount clamping,
 * and item-collection management.
 */
#[CoversClass(Campaign::class)]
final class CampaignTest extends TestCase
{
    private function makeCampaign(
        string $type = 'flash',
        ?DateTimeImmutable $starts = null,
        ?DateTimeImmutable $ends = null,
    ): Campaign {
        $now    = new DateTimeImmutable('2026-06-15 12:00:00');
        $starts ??= $now->modify('-1 hour');
        $ends   ??= $now->modify('+1 hour');
        return new Campaign('test-campaign', $type, 'Test Campaign', $starts, $ends);
    }

    #[Test]
    public function isLiveWhenActiveAndInsideWindow(): void
    {
        $now = new DateTimeImmutable('2026-06-15 12:00:00');
        self::assertTrue($this->makeCampaign()->isLiveAt($now));
    }

    #[Test]
    public function isNotLiveWhenInactive(): void
    {
        $now = new DateTimeImmutable('2026-06-15 12:00:00');
        $c   = $this->makeCampaign();
        $c->setActive(false);
        self::assertFalse($c->isLiveAt($now));
    }

    #[Test]
    public function isNotLiveBeforeStart(): void
    {
        $c   = $this->makeCampaign();
        $before = new DateTimeImmutable('2026-06-15 10:00:00');
        self::assertFalse($c->isLiveAt($before));
    }

    #[Test]
    public function isNotLiveAfterEnd(): void
    {
        $c    = $this->makeCampaign();
        $after = new DateTimeImmutable('2026-06-15 14:00:00');
        self::assertFalse($c->isLiveAt($after));
    }

    #[Test]
    public function invalidTypeNormalisesToAnniversary(): void
    {
        $c = $this->makeCampaign('nonsense');
        self::assertSame(Campaign::TYPE_ANNIVERSARY, $c->getType());
    }

    #[Test]
    public function validTypesPreserved(): void
    {
        self::assertSame('flash', $this->makeCampaign('flash')->getType());
        self::assertSame('anniversary', $this->makeCampaign('anniversary')->getType());
    }

    #[Test]
    public function discountPercentClampedToRange(): void
    {
        $c = $this->makeCampaign();
        $c->setDiscountPercent(150);
        self::assertSame(100, $c->getDiscountPercent());
        $c->setDiscountPercent(-10);
        self::assertSame(0, $c->getDiscountPercent());
        $c->setDiscountPercent(35);
        self::assertSame(35, $c->getDiscountPercent());
    }

    #[Test]
    public function clearItemsEmptiesCollection(): void
    {
        $c = $this->makeCampaign();
        self::assertCount(0, $c->getItems());
        $c->clearItems();
        self::assertCount(0, $c->getItems());
    }
}
