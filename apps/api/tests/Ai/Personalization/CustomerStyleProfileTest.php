<?php

declare(strict_types=1);

namespace Bayti\Api\Tests\Ai\Personalization;

use Bayti\Api\Ai\Personalization\CustomerStyleProfile;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(CustomerStyleProfile::class)]
final class CustomerStyleProfileTest extends TestCase
{
    #[Test]
    public function roundTripsThroughJsonbLikeArrays(): void
    {
        $profile = new CustomerStyleProfile(
            colours: [['tag' => 'black', 'weight' => 7.0], ['tag' => 'beige', 'weight' => 3.0]],
            styles: [['tag' => 'elegant', 'weight' => 4.0]],
            categories: [['id' => 10, 'weight' => 7.0]],
            vendors: [['id' => 99, 'weight' => 5.0]],
            occasions: [['tag' => 'wedding', 'weight' => 4.0]],
            sizes: [['tag' => '54', 'weight' => 4.0]],
            budgetMin: '120.00',
            budgetMax: '480.00',
            seedProductIds: [5, 6, 7],
            signalCounts: ['wishlist' => 2, 'orders' => 1, 'views' => 3, 'follows' => 1],
        );

        // Simulate the raw-DBAL read path: jsonb columns arrive as JSON strings.
        $row = [
            'colours' => (string) json_encode($profile->colours),
            'styles' => (string) json_encode($profile->styles),
            'categories' => (string) json_encode($profile->categories),
            'vendors' => (string) json_encode($profile->vendors),
            'occasions' => (string) json_encode($profile->occasions),
            'sizes' => (string) json_encode($profile->sizes),
            'budget_min' => '120.00',
            'budget_max' => '480.00',
            'seed_product_ids' => (string) json_encode($profile->seedProductIds),
            'signal_counts' => (string) json_encode($profile->signalCounts),
        ];

        $restored = CustomerStyleProfile::fromArray($row);

        self::assertSame(['black', 'beige'], $restored->topColours(5));
        self::assertSame(['elegant'], $restored->topStyles(5));
        self::assertSame([10], $restored->topCategoryIds(5));
        self::assertSame([99], $restored->topVendorIds(5));
        self::assertSame(['wedding'], $restored->topOccasions(5));
        self::assertSame('120.00', $restored->budgetMin);
        self::assertSame('480.00', $restored->budgetMax);
        self::assertSame([5, 6, 7], $restored->seedProductIds);
        self::assertSame(['wishlist' => 2, 'orders' => 1, 'views' => 3, 'follows' => 1], $restored->signalCounts);
    }

    #[Test]
    public function topHelpersRespectLimitAndOrder(): void
    {
        $profile = new CustomerStyleProfile(
            colours: [['tag' => 'black', 'weight' => 9.0], ['tag' => 'beige', 'weight' => 5.0], ['tag' => 'rose', 'weight' => 2.0]],
            categories: [['id' => 10, 'weight' => 9.0], ['id' => 20, 'weight' => 4.0]],
        );

        self::assertSame(['black', 'beige'], $profile->topColours(2));
        self::assertSame([10], $profile->topCategoryIds(1));
        self::assertSame([], $profile->topColours(0));
    }

    #[Test]
    public function isEmptyWhenNoActionableAffinity(): void
    {
        self::assertTrue((new CustomerStyleProfile())->isEmpty());
        self::assertFalse((new CustomerStyleProfile(colours: [['tag' => 'black', 'weight' => 1.0]]))->isEmpty());
        // occasions/sizes alone are not enough to drive a rail.
        self::assertTrue((new CustomerStyleProfile(occasions: [['tag' => 'eid', 'weight' => 1.0]]))->isEmpty());
    }

    #[Test]
    public function fromArrayToleratesMissingAndMalformedColumns(): void
    {
        $restored = CustomerStyleProfile::fromArray([
            'colours' => null,
            'categories' => 'not-json',
            'budget_min' => null,
            'seed_product_ids' => (string) json_encode([1, 'x', 2]),
        ]);

        self::assertSame([], $restored->topColours(5));
        self::assertSame([], $restored->topCategoryIds(5));
        self::assertNull($restored->budgetMin);
        self::assertSame([1, 2], $restored->seedProductIds);
    }
}
