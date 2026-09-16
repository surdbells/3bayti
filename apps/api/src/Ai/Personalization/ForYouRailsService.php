<?php

declare(strict_types=1);

namespace Bayti\Api\Ai\Personalization;

use Bayti\Api\Domain\Catalog\Product;
use Bayti\Api\Domain\Catalog\ProductRepository;
use Bayti\Api\Domain\Catalog\RecommendationsService;
use Bayti\Api\Domain\User\User;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Builds the personalised "For You" rails at request time from a customer's
 * pre-computed {@see CustomerStyleProfile} plus the existing catalogue retrieval
 * — NO per-request LLM call. Rails:
 *   - your_style        → products in the customer's colours/category
 *   - stores_you_love   → best-sellers from vendors they follow/buy
 *   - because_you_liked → co-purchase recs off a recent seed product
 *   - new_for_you       → new arrivals in their colours
 * Cold/new users (no profile) get a single `popular` rail.
 *
 * Anti-hallucination boundary: every product is re-validated with
 * isOrderable() && isInStock() before it can appear, and items the customer
 * already owns or wishlisted are excluded. Rails are de-duplicated across the
 * set, so each product appears at most once.
 *
 * Non-final so the controller test can mock the rail assembly.
 */
class ForYouRailsService
{
    public const KEY_YOUR_STYLE = 'your_style';
    public const KEY_STORES = 'stores_you_love';
    public const KEY_LIKED = 'because_you_liked';
    public const KEY_NEW = 'new_for_you';
    public const KEY_POPULAR = 'popular';

    public const DEFAULT_PER_RAIL = 12;
    private const MIN_PER_RAIL = 3;
    private const MAX_PER_RAIL = 24;
    private const RETRIEVE_MULTIPLIER = 3;
    /** High cap for the owned/wishlisted exclusion set so heavy users are still fully covered. */
    private const EXCLUDE_CAP = 2000;

    public function __construct(
        private readonly CustomerStyleProfileStore $profiles,
        private readonly CustomerStyleSignals $signals,
        private readonly RecommendationsService $recommendations,
        private readonly EntityManagerInterface $em,
    ) {
    }

    public function build(User $user, int $perRail = self::DEFAULT_PER_RAIL): ForYouRailSet
    {
        $userId = $user->getId();
        $perRail = max(self::MIN_PER_RAIL, min(self::MAX_PER_RAIL, $perRail));
        if ($userId === null) {
            return new ForYouRailSet(false, []);
        }

        // Never recommend what they already own or saved (high cap so even
        // power users with huge histories are fully excluded).
        /** @var array<int, bool> $shown */
        $shown = [];
        foreach ([
            ...$this->signals->purchasedProductIds($userId, self::EXCLUDE_CAP),
            ...$this->signals->wishlistProductIds($userId, self::EXCLUDE_CAP),
        ] as $ownedId) {
            $shown[$ownedId] = true;
        }

        $profile = $this->profiles->fetch($userId);
        if ($profile === null || $profile->isEmpty()) {
            return $this->coldSet($userId, $perRail, $shown);
        }

        $colours = $profile->topColours(3);
        $rails = [];

        // your_style — the customer's colours (+ their top category).
        $styleFilters = ['sort' => 'relevance', 'inStock' => true, 'limit' => $perRail * self::RETRIEVE_MULTIPLIER];
        if ($colours !== []) {
            $styleFilters['colors'] = $colours;
        }
        $topCategory = $profile->topCategoryIds(1)[0] ?? null;
        if ($topCategory !== null) {
            $styleFilters['categoryId'] = $topCategory;
        }
        if ($colours !== [] || $topCategory !== null) {
            $picked = $this->pick($this->retrieve($styleFilters), $shown, $perRail);
            if (count($picked) >= self::MIN_PER_RAIL) {
                $rails[] = new ForYouRail(self::KEY_YOUR_STYLE, $picked);
            }
        }

        // stores_you_love — best-sellers from their top vendors.
        $storeCandidates = [];
        foreach ($profile->topVendorIds(3) as $vendorId) {
            $storeCandidates = [
                ...$storeCandidates,
                ...$this->retrieve(['vendorId' => $vendorId, 'sort' => 'best_seller', 'inStock' => true, 'limit' => $perRail * 2]),
            ];
        }
        $picked = $this->pick($storeCandidates, $shown, $perRail);
        if (count($picked) >= self::MIN_PER_RAIL) {
            $rails[] = new ForYouRail(self::KEY_STORES, $picked);
        }

        // because_you_liked — recs off the most recent seed still orderable.
        $likedRail = $this->buildLikedRail($profile->seedProductIds, $shown, $perRail);
        if ($likedRail !== null) {
            $rails[] = $likedRail;
        }

        // new_for_you — fresh arrivals in their colours.
        $newFilters = ['isNew' => true, 'sort' => 'newest', 'inStock' => true, 'limit' => $perRail * self::RETRIEVE_MULTIPLIER];
        if ($colours !== []) {
            $newFilters['colors'] = $colours;
        }
        $picked = $this->pick($this->retrieve($newFilters), $shown, $perRail);
        if (count($picked) >= self::MIN_PER_RAIL) {
            $rails[] = new ForYouRail(self::KEY_NEW, $picked);
        }

        if ($rails === []) {
            // Profile existed but yielded nothing sellable — fall back to popular.
            return $this->coldSet($userId, $perRail, $shown);
        }

        return new ForYouRailSet(true, $rails);
    }

    /**
     * @param list<int> $seedIds
     * @param array<int, bool> $shown
     */
    private function buildLikedRail(array $seedIds, array &$shown, int $perRail): ?ForYouRail
    {
        /** @var ProductRepository $repo */
        $repo = $this->em->getRepository(Product::class);
        foreach ($seedIds as $seedId) {
            $seed = $repo->find($seedId);
            if (!$seed instanceof Product || !$seed->isOrderable()) {
                continue;
            }
            // Don't let the seed itself appear inside its own rail.
            $railShown = $shown;
            $railShown[$seedId] = true;
            $recs = $this->productsFrom($this->recommendations->getRecommendationsForProduct($seedId, $perRail * 2));
            $picked = $this->pick($recs, $railShown, $perRail);
            if (count($picked) >= self::MIN_PER_RAIL) {
                // Commit the picks (and the seed) into the shared shown set.
                foreach ($picked as $product) {
                    $id = $product->getId();
                    if ($id !== null) {
                        $shown[$id] = true;
                    }
                }
                return new ForYouRail(self::KEY_LIKED, $picked, $seed->getName());
            }
        }
        return null;
    }

    /**
     * @param array<int, bool> $shown
     */
    private function coldSet(int $userId, int $perRail, array $shown): ForYouRailSet
    {
        $cold = $this->pick(
            $this->productsFrom($this->recommendations->getRecommendationsForUser($userId, $perRail * self::RETRIEVE_MULTIPLIER)),
            $shown,
            $perRail,
        );
        if (count($cold) < self::MIN_PER_RAIL) {
            return new ForYouRailSet(false, []);
        }
        return new ForYouRailSet(false, [new ForYouRail(self::KEY_POPULAR, $cold)]);
    }

    /**
     * @param array<string, mixed> $filters
     * @return list<Product>
     */
    private function retrieve(array $filters): array
    {
        /** @var ProductRepository $repo */
        $repo = $this->em->getRepository(Product::class);
        return $repo->findActivePaginated($filters)['items'];
    }

    /**
     * Gate (orderable + in stock) + de-duplicate against $shown, take up to
     * $limit, and record the chosen ids in $shown so later rails don't repeat.
     *
     * @param list<Product> $candidates
     * @param array<int, bool> $shown
     * @return list<Product>
     */
    private function pick(array $candidates, array &$shown, int $limit): array
    {
        $out = [];
        foreach ($candidates as $product) {
            if (count($out) >= $limit) {
                break;
            }
            $id = $product->getId();
            if ($id === null || isset($shown[$id])) {
                continue;
            }
            if (!$product->isOrderable() || !$product->isInStock()) {
                continue;
            }
            $shown[$id] = true;
            $out[] = $product;
        }
        return $out;
    }

    /**
     * @param list<array{product: Product, score: string, source: string}> $recs
     * @return list<Product>
     */
    private function productsFrom(array $recs): array
    {
        return array_map(static fn (array $rec): Product => $rec['product'], $recs);
    }
}
