<?php

declare(strict_types=1);

namespace Bayti\Api\Ai\Concierge\Outfit;

use Bayti\Api\Ai\Concierge\ConciergeIntent;
use Bayti\Api\Ai\Concierge\ConciergeRanker;
use Bayti\Api\Ai\Concierge\Gift\GiftCardSuggestion;
use Bayti\Api\Ai\Concierge\ProductRetrievalService;
use Bayti\Api\Domain\Catalog\Product;

/**
 * Composes a coordinated OUTFIT — a hero garment plus complementary pieces across
 * distinct categories — from real, in-stock products, reusing the Phase-1
 * retrieval + ranking spine (no new hallucination boundary):
 *
 *   1. Retrieve + rank a HERO garment from the brief (colour/occasion/style/budget
 *      aware); the hero is capped to a share of the budget so complements can fit.
 *   2. Retrieve a cross-category COMPLEMENT pool (same colours/occasion, no
 *      category filter) within the remaining budget, then take one piece per
 *      category (excluding the hero's category) until the outfit is full.
 *
 * Every piece is re-validated isOrderable() && isInStock(); the whole look is
 * kept within budget and to <= 4 pieces (so it saves cleanly as a Style). When no
 * coherent look can be built, a gift-card suggestion is returned instead.
 */
final class OutfitComposerService
{
    public const DEFAULT_PIECES = 4;
    private const MIN_PIECES = 2;
    private const MAX_PIECES = 4;
    private const HERO_BUDGET_SHARE = 0.6;
    private const HERO_SHORTLIST = 24;
    private const COMPLEMENT_SHORTLIST = 40;
    private const BUDGET_TOLERANCE = 1.05;

    public function __construct(
        private readonly ProductRetrievalService $retrieval,
        private readonly ConciergeRanker $ranker,
    ) {
    }

    public function compose(OutfitBrief $brief, string $locale = 'en', int $limit = self::DEFAULT_PIECES): OutfitResult
    {
        $limit = max(self::MIN_PIECES, min(self::MAX_PIECES, $limit));
        $intent = $brief->toIntent();
        $budgetMax = $brief->budgetMax !== null && $brief->budgetMax > 0 ? $brief->budgetMax : null;

        // 1) HERO garment — capped to a budget share so complements can fit.
        $heroCap = $budgetMax !== null ? round($budgetMax * self::HERO_BUDGET_SHARE, 2) : null;
        $heroShortlist = $this->retrieval->retrieve($this->withBudget($intent, $intent->budgetMin, $heroCap), self::HERO_SHORTLIST);
        if ($heroShortlist === [] && $heroCap !== $budgetMax) {
            // The 60% cap starved the hero — relax to the full budget.
            $heroShortlist = $this->retrieval->retrieve($this->withBudget($intent, $intent->budgetMin, $budgetMax), self::HERO_SHORTLIST);
        }
        if ($heroShortlist === []) {
            return new OutfitResult(
                $intent,
                [],
                $this->rationale($brief, null, 0, $locale),
                GiftCardSuggestion::make(GiftCardSuggestion::REASON_FEW_MATCHES, $brief->budgetMax),
            );
        }

        $rankedHero = $this->ranker->rank($intent, $heroShortlist, $brief->toRankerQuery(), self::HERO_SHORTLIST);
        $hero = $rankedHero !== [] ? $rankedHero[0]->product : $heroShortlist[0];
        $heroReason = $rankedHero !== [] ? $rankedHero[0]->reason : $this->heroReason($locale);

        $pieces = [new OutfitPiece($hero, OutfitPiece::ROLE_HERO, $this->slugOf($hero), $heroReason)];
        $usedIds = $this->idList([$hero]);
        $usedCategories = [$this->categoryKey($hero)];
        $spent = (float) $hero->effectivePrice();

        // 2) COMPLEMENTS — cross-category, colour/occasion-aware, within remaining budget.
        // Drop the hero's product-type from the keywords so the pool isn't biased
        // back toward the same garment (e.g. "abaya") — we want coordinating pieces.
        $remaining = $budgetMax !== null ? max(0.0, $budgetMax - $spent) : null;
        $complementIntent = new ConciergeIntent(
            productType: null,
            categorySlug: null,
            occasions: $intent->occasions,
            colours: $intent->colours,
            styles: $intent->styles,
            budgetMin: null,
            budgetMax: $remaining !== null && $remaining > 0 ? $remaining : null,
            keywords: $this->complementKeywords($intent),
            vendorHints: [],
            isGift: false,
            confidence: $intent->confidence,
        );
        $pool = $this->retrieval->retrieve($complementIntent, self::COMPLEMENT_SHORTLIST);

        foreach ($pool as $product) {
            if (count($pieces) >= $limit) {
                break;
            }
            $id = $product->getId();
            if ($id === null || in_array($id, $usedIds, true)) {
                continue;
            }
            // Anti-hallucination boundary (belt and suspenders — retrieval already gates).
            if (!$product->isOrderable() || !$product->isInStock()) {
                continue;
            }
            $catKey = $this->categoryKey($product);
            if (in_array($catKey, $usedCategories, true)) {
                continue; // one piece per category
            }
            $price = (float) $product->effectivePrice();
            if ($budgetMax !== null && ($spent + $price) > $budgetMax * self::BUDGET_TOLERANCE) {
                continue; // keep the whole look within budget
            }
            $pieces[] = new OutfitPiece($product, OutfitPiece::ROLE_COMPLEMENT, $this->slugOf($product), $this->complementReason($locale));
            $usedIds[] = $id;
            $usedCategories[] = $catKey;
            $spent += $price;
        }

        $suggestion = count($pieces) < self::MIN_PIECES
            ? GiftCardSuggestion::make(GiftCardSuggestion::REASON_FEW_MATCHES, $brief->budgetMax)
            : null;

        return new OutfitResult($intent, $pieces, $this->rationale($brief, $pieces[0], count($pieces) - 1, $locale), $suggestion);
    }

    private function withBudget(ConciergeIntent $intent, ?float $min, ?float $max): ConciergeIntent
    {
        return new ConciergeIntent(
            productType: $intent->productType,
            categorySlug: $intent->categorySlug,
            occasions: $intent->occasions,
            colours: $intent->colours,
            styles: $intent->styles,
            budgetMin: $min,
            budgetMax: $max,
            keywords: $intent->keywords,
            vendorHints: $intent->vendorHints,
            isGift: $intent->isGift,
            confidence: $intent->confidence,
        );
    }

    /**
     * Stable one-per-category key: the category slug when present, else a per-id
     * key so uncategorised products don't all collapse into one slot.
     */
    private function categoryKey(Product $product): string
    {
        $slug = $product->getCategory()?->getSlug();
        if (is_string($slug) && $slug !== '') {
            return 'cat:' . $slug;
        }
        return 'id:' . ($product->getId() ?? 0);
    }

    private function slugOf(Product $product): string
    {
        return $product->getCategory()?->getSlug() ?? '';
    }

    /**
     * The hero intent's keywords minus its own product-type, so the complement
     * pool coordinates (bags, accessories, scarves) rather than surfacing more of
     * the same garment.
     *
     * @return list<string>
     */
    private function complementKeywords(ConciergeIntent $intent): array
    {
        if ($intent->productType === null) {
            return $intent->keywords;
        }
        $hero = mb_strtolower($intent->productType);
        return array_values(array_filter(
            $intent->keywords,
            static fn (string $k): bool => mb_strtolower($k) !== $hero,
        ));
    }

    /**
     * @param list<Product> $products
     * @return list<int>
     */
    private function idList(array $products): array
    {
        $ids = [];
        foreach ($products as $p) {
            $id = $p->getId();
            if ($id !== null) {
                $ids[] = $id;
            }
        }
        return $ids;
    }

    /**
     * Deterministic look rationale (EN/AR) — no LLM dependency, so it reads well
     * even with AI disabled. Built from the brief signals + the hero.
     */
    private function rationale(OutfitBrief $brief, ?OutfitPiece $hero, int $complementCount, string $locale): string
    {
        $ar = str_starts_with($locale, 'ar');
        if ($hero === null) {
            return $ar
                ? 'لم نتمكن من تكوين إطلالة كاملة بهذه المواصفات — بطاقة الهدية خيار مرن.'
                : "We couldn't build a full look from that just yet — a gift card is a flexible option.";
        }

        $style = $brief->styles !== [] ? implode(' ', $brief->styles) : '';
        $occasion = $brief->occasion;
        $colour = $brief->colours !== [] ? $brief->colours[0] : '';
        $heroName = $hero->product->getName();

        if ($ar) {
            $lead = 'إطلالة';
            if ($style !== '') {
                $lead .= ' ' . $style;
            }
            if ($occasion !== null) {
                $lead .= ' لمناسبة ' . $occasion;
            }
            if ($colour !== '') {
                $lead .= ' بلون ' . $colour;
            }
            $tail = $complementCount > 0
                ? '، تتمحور حول ' . $heroName . ' مع قطع منسّقة.'
                : '، تتمحور حول ' . $heroName . '.';
            return $lead . $tail;
        }

        $lead = 'A' . ($style !== '' ? ' ' . $style : '') . ' look';
        if ($occasion !== null) {
            $lead .= ' for ' . $occasion;
        }
        if ($colour !== '') {
            $lead .= ' in ' . $colour;
        }
        $tail = $complementCount > 0
            ? ', built around ' . $heroName . ' and styled with coordinating pieces.'
            : ', built around ' . $heroName . '.';

        return $lead . $tail;
    }

    private function heroReason(string $locale): string
    {
        return str_starts_with($locale, 'ar') ? 'القطعة الأساسية للإطلالة' : 'The statement piece of the look';
    }

    private function complementReason(string $locale): string
    {
        return str_starts_with($locale, 'ar') ? 'يكمل الإطلالة' : 'Completes the look';
    }
}
