<?php

declare(strict_types=1);

namespace Bayti\Api\Ai\Concierge\Style;

use Bayti\Api\Ai\Concierge\ConciergeIntent;
use Bayti\Api\Domain\Catalog\Product;

/**
 * A "restyle" request: an existing look (seed products) + a natural-language
 * instruction ("make it more elegant", "style this for a wedding"), already
 * parsed into a {@see ConciergeIntent} by IntentParser. It MERGES the two into
 * one intent that the Phase-1 retrieval + ranking pipeline rebuilds from real,
 * in-stock products — the anti-hallucination gate is inherited, not re-done.
 *
 * The instruction's explicit signals win; the seed contributes wardrobe
 * keywords + colours (only when the instruction named none) so the rebuild stays
 * in the same territory. It is a WHOLE-LOOK rebuild: productType/categorySlug
 * stay null so retrieval returns a themed cross-category set.
 */
final class RestyleBrief
{
    /** @var list<string> */
    private array $seedNames;
    /** @var list<string> */
    private array $seedColours;
    /** @var list<string> seed category slugs/names, as soft wardrobe keywords */
    private array $seedTokens;

    /**
     * @param list<Product> $seedProducts
     */
    public function __construct(
        array $seedProducts,
        private readonly string $instruction,
        private readonly ConciergeIntent $instructionIntent,
    ) {
        $names = [];
        $colours = [];
        $tokens = [];
        foreach ($seedProducts as $p) {
            $names[] = $p->getName();
            foreach ($p->getAvailableColors() as $c) {
                $colours[] = strtolower(trim($c));
            }
            $category = $p->getCategory();
            if ($category !== null) {
                $tokens[] = strtolower(trim($category->getName()));
            }
        }
        $this->seedNames = array_values(array_unique(array_filter($names)));
        $this->seedColours = array_values(array_unique(array_filter($colours)));
        $this->seedTokens = array_values(array_unique(array_filter($tokens)));
    }

    public function toIntent(): ConciergeIntent
    {
        // Keywords: the instruction's, anchored by the seed's wardrobe tokens
        // (so a rebuild of an abaya look stays abaya-adjacent). Recipient/gift
        // concepts are irrelevant here.
        $keywords = $this->instructionIntent->keywords;
        if ($keywords === []) {
            $keywords = $this->seedTokens;
        } else {
            $keywords = array_values(array_unique(array_merge($keywords, $this->seedTokens)));
        }

        // Colours: explicit instruction colours win; else keep the seed's palette.
        $colours = $this->instructionIntent->colours !== [] ? $this->instructionIntent->colours : $this->seedColours;

        return new ConciergeIntent(
            productType: null,
            categorySlug: null,
            occasions: $this->instructionIntent->occasions,
            colours: $colours,
            styles: $this->instructionIntent->styles,
            budgetMin: $this->instructionIntent->budgetMin,
            budgetMax: $this->instructionIntent->budgetMax,
            keywords: $keywords,
            vendorHints: [],
            isGift: false,
            confidence: $this->instructionIntent->confidence,
        );
    }

    /** LLM ranking context + the recorded query_text. */
    public function toRankerQuery(): string
    {
        $q = 'Restyle this look: ' . $this->instruction;
        if ($this->seedNames !== []) {
            $q .= '. Original look: ' . implode(', ', array_slice($this->seedNames, 0, 4));
        }
        return $q . '.';
    }

    public function instruction(): string
    {
        return $this->instruction;
    }

    /** Deterministic, localized one-liner (works with AI off). */
    public function rationale(string $locale): string
    {
        $intent = $this->instructionIntent;
        $occasion = $intent->occasions[0] ?? null;
        $style = $intent->styles[0] ?? null;
        $colour = ($intent->colours[0] ?? null) ?? ($this->seedColours[0] ?? null);

        if ($locale === 'ar') {
            if ($occasion !== null) {
                return 'أعادت عين تنسيق إطلالتك لـ' . $occasion . '.';
            }
            if ($style !== null) {
                return 'إطلالة بروح ' . $style . ' اختارتها لك عين.';
            }
            return 'أعادت عين تنسيق إطلالتك حسب طلبك.';
        }

        if ($occasion !== null) {
            return 'Ain restyled your look for a ' . $occasion . ' occasion.';
        }
        if ($style !== null) {
            $base = 'A more ' . $style . ' take on your look';
            return $colour !== null ? $base . ', in ' . $colour . '.' : $base . '.';
        }
        return 'Ain rebuilt your look around your instruction.';
    }
}
