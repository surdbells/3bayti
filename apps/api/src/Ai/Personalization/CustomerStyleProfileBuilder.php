<?php

declare(strict_types=1);

namespace Bayti\Api\Ai\Personalization;

use Bayti\Api\Ai\Enrichment\ProductAiAttributesStore;
use Bayti\Api\Domain\Catalog\Product;
use Bayti\Api\Domain\Catalog\ProductRepository;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;

/**
 * Aggregates a customer's behavioural signals (wishlist / purchases / views /
 * follows) into a {@see CustomerStyleProfile}: weighted colour / category /
 * vendor / style / occasion / size affinities, a budget band, and a few recent
 * "seed" products. Runs offline in `ai:build-style-profiles`.
 *
 * Colours/categories/vendors/budget come straight from the catalogue (so the
 * profile works even with AI enrichment off); styles/occasions are layered in
 * from product_ai_attributes when available. Every input is a real product the
 * customer engaged with — the profile never invents taste.
 */
final class CustomerStyleProfileBuilder
{
    private const WEIGHT_PURCHASE = 4.0;
    private const WEIGHT_WISHLIST = 3.0;
    private const WEIGHT_VIEW = 1.0;
    private const WEIGHT_FOLLOW = 5.0;

    private const MAX_COLOURS = 8;
    private const MAX_STYLES = 8;
    private const MAX_OCCASIONS = 8;
    private const MAX_CATEGORIES = 6;
    private const MAX_VENDORS = 8;
    private const MAX_SIZES = 6;
    private const MAX_SEEDS = 6;

    public function __construct(
        private readonly CustomerStyleSignals $signals,
        private readonly ProductAiAttributesStore $tags,
        private readonly EntityManagerInterface $em,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function build(int $userId): CustomerStyleProfile
    {
        $wishlist = $this->signals->wishlistProductIds($userId);
        $purchased = $this->signals->purchasedProductIds($userId);
        $viewed = $this->signals->viewedProductIds($userId);
        $followedVendors = $this->signals->followedVendorIds($userId);

        // Weighted product set: a product's weight is the sum of its signals
        // (a wishlisted AND purchased item counts strongest).
        $weightOf = [];
        foreach ($purchased as $id) {
            $weightOf[$id] = ($weightOf[$id] ?? 0.0) + self::WEIGHT_PURCHASE;
        }
        foreach ($wishlist as $id) {
            $weightOf[$id] = ($weightOf[$id] ?? 0.0) + self::WEIGHT_WISHLIST;
        }
        foreach ($viewed as $id) {
            $weightOf[$id] = ($weightOf[$id] ?? 0.0) + self::WEIGHT_VIEW;
        }

        $productIds = array_map('intval', array_keys($weightOf));
        $byId = $this->hydrate($productIds);
        $tagMap = $this->tags->fetchTags($productIds);

        // Prices that count toward the budget band: strong signals only
        // (things they wishlisted or actually bought), not passing views.
        $strongIds = array_values(array_unique([...$purchased, ...$wishlist]));

        /** @var array<string, float> $colourW */
        $colourW = [];
        /** @var array<string, float> $styleW */
        $styleW = [];
        /** @var array<string, float> $occasionW */
        $occasionW = [];
        /** @var array<int, float> $categoryW */
        $categoryW = [];
        /** @var array<int, float> $vendorW */
        $vendorW = [];
        /** @var array<string, float> $sizeW */
        $sizeW = [];
        /** @var list<float> $strongPrices */
        $strongPrices = [];

        foreach ($byId as $id => $product) {
            $w = $weightOf[$id] ?? 0.0;

            foreach ($product->getAvailableColors() as $colour) {
                $key = $this->normalise($colour);
                if ($key !== '') {
                    $colourW[$key] = ($colourW[$key] ?? 0.0) + $w;
                }
            }
            foreach ($product->getAvailableSizes() as $size) {
                $key = $this->normalise($size);
                if ($key !== '') {
                    $sizeW[$key] = ($sizeW[$key] ?? 0.0) + $w;
                }
            }
            $categoryId = $product->getCategory()?->getId();
            if ($categoryId !== null) {
                $categoryW[$categoryId] = ($categoryW[$categoryId] ?? 0.0) + $w;
            }
            $vendorId = $product->getVendor()->getId();
            if ($vendorId !== null) {
                $vendorW[$vendorId] = ($vendorW[$vendorId] ?? 0.0) + $w;
            }

            $tagsForProduct = $tagMap[$id] ?? null;
            if ($tagsForProduct !== null) {
                foreach ($tagsForProduct['styles'] as $style) {
                    $key = $this->normalise($style);
                    if ($key !== '') {
                        $styleW[$key] = ($styleW[$key] ?? 0.0) + $w;
                    }
                }
                foreach ($tagsForProduct['occasions'] as $occasion) {
                    $key = $this->normalise($occasion);
                    if ($key !== '') {
                        $occasionW[$key] = ($occasionW[$key] ?? 0.0) + $w;
                    }
                }
            }

            if (in_array($id, $strongIds, true)) {
                $strongPrices[] = (float) $product->effectivePrice();
            }
        }

        foreach ($followedVendors as $vendorId) {
            $vendorW[$vendorId] = ($vendorW[$vendorId] ?? 0.0) + self::WEIGHT_FOLLOW;
        }

        [$budgetMin, $budgetMax] = $this->budgetBand($strongPrices);

        // Seeds for the "Because you liked…" rail: the customer's most recent
        // wishlist adds first, then recent views.
        $seedProductIds = [];
        foreach ([...$wishlist, ...$viewed] as $id) {
            if (!in_array($id, $seedProductIds, true)) {
                $seedProductIds[] = $id;
            }
            if (count($seedProductIds) >= self::MAX_SEEDS) {
                break;
            }
        }

        $profile = new CustomerStyleProfile(
            colours: $this->rankTags($colourW, self::MAX_COLOURS),
            styles: $this->rankTags($styleW, self::MAX_STYLES),
            categories: $this->rankIds($categoryW, self::MAX_CATEGORIES),
            vendors: $this->rankIds($vendorW, self::MAX_VENDORS),
            occasions: $this->rankTags($occasionW, self::MAX_OCCASIONS),
            sizes: $this->rankTags($sizeW, self::MAX_SIZES),
            budgetMin: $budgetMin,
            budgetMax: $budgetMax,
            seedProductIds: $seedProductIds,
            signalCounts: [
                'wishlist' => count($wishlist),
                'orders' => count($purchased),
                'views' => count($viewed),
                'follows' => count($followedVendors),
            ],
        );

        $this->logger->debug('style_profile.built', [
            'user_id' => $userId,
            'products' => count($byId),
            'colours' => count($profile->colours),
            'vendors' => count($profile->vendors),
        ]);

        return $profile;
    }

    /**
     * @param list<int> $productIds
     * @return array<int, Product>
     */
    private function hydrate(array $productIds): array
    {
        if ($productIds === []) {
            return [];
        }
        /** @var ProductRepository $repo */
        $repo = $this->em->getRepository(Product::class);
        $byId = [];
        foreach ($repo->findBy(['id' => $productIds]) as $product) {
            $id = $product->getId();
            if ($id !== null) {
                $byId[$id] = $product;
            }
        }
        return $byId;
    }

    /**
     * @param list<float> $prices
     * @return array{0: ?string, 1: ?string}
     */
    private function budgetBand(array $prices): array
    {
        $prices = array_values(array_filter($prices, static fn (float $p): bool => $p > 0));
        if ($prices === []) {
            return [null, null];
        }
        sort($prices);
        // Trim outliers once there's enough data; otherwise use the raw range.
        if (count($prices) >= 5) {
            $min = $this->percentile($prices, 0.15);
            $max = $this->percentile($prices, 0.85);
        } else {
            $min = $prices[0];
            $max = $prices[count($prices) - 1];
        }
        if ($max < $min) {
            $max = $min;
        }
        return [number_format($min, 2, '.', ''), number_format($max, 2, '.', '')];
    }

    /**
     * @param list<float> $sorted ascending
     */
    private function percentile(array $sorted, float $p): float
    {
        $count = count($sorted);
        if ($count === 0) {
            return 0.0;
        }
        $index = (int) round($p * ($count - 1));
        $index = max(0, min($count - 1, $index));
        return $sorted[$index];
    }

    /**
     * @param array<string, float> $weights
     * @return list<array{tag: string, weight: float}>
     */
    private function rankTags(array $weights, int $limit): array
    {
        arsort($weights);
        $out = [];
        foreach (array_slice($weights, 0, $limit, true) as $tag => $weight) {
            $out[] = ['tag' => (string) $tag, 'weight' => round($weight, 2)];
        }
        return $out;
    }

    /**
     * @param array<int, float> $weights
     * @return list<array{id: int, weight: float}>
     */
    private function rankIds(array $weights, int $limit): array
    {
        arsort($weights);
        $out = [];
        foreach (array_slice($weights, 0, $limit, true) as $id => $weight) {
            $out[] = ['id' => (int) $id, 'weight' => round($weight, 2)];
        }
        return $out;
    }

    private function normalise(string $value): string
    {
        return mb_strtolower(trim($value));
    }
}
