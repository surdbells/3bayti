<?php

declare(strict_types=1);

namespace Bayti\Api\Ai\Concierge;

use Bayti\Api\Ai\AiException;
use Bayti\Api\Ai\AiProviderInterface;

/**
 * Turns a natural-language concierge query into a {@see ConciergeIntent}.
 *
 * Understands English AND Arabic natively and always returns canonical ENGLISH
 * tags, so both languages funnel into the same catalogue retrieval — not a
 * translation layer. When AI is disabled or the call fails, degrades to a
 * keyword-only intent (the raw query), so discovery never breaks.
 */
final class IntentParser
{
    public function __construct(private readonly AiProviderInterface $ai)
    {
    }

    public function parse(string $query, string $locale = 'en'): ConciergeIntent
    {
        $query = trim($query);
        if ($query === '' || !$this->ai->isEnabled()) {
            return ConciergeIntent::keywordFallback($query);
        }

        try {
            $raw = $this->ai->completeJson(
                self::systemPrompt($locale),
                $query,
                self::schema(),
                'concierge_intent',
            );
        } catch (AiException) {
            return ConciergeIntent::keywordFallback($query);
        }

        // Thread the raw query through so an empty extraction still yields a
        // usable keyword search.
        $raw['keywords_fallback'] = $query;
        return ConciergeIntent::fromArray($raw);
    }

    private static function systemPrompt(string $locale): string
    {
        return <<<PROMPT
            You are Ain, the personal shopping stylist for 3bayti, a UAE marketplace for
            Emirati and modest fashion (abayas, mukhawars, kaftans, hijabs, bags, shoes,
            accessories). Extract the shopper's intent from their message into the given
            JSON schema.

            Rules:
            - Understand English AND Arabic. ALWAYS output canonical ENGLISH tags.
            - Only extract what the shopper actually said. Leave a field null or [] when
              it is not stated — never guess a colour, budget or occasion.
            - product_type: the garment/item type as a lowercase singular English noun
              (e.g. "abaya", "kaftan", "bag"). category_slug: only if you are confident it
              matches a store category slug; otherwise null.
            - budget_min/budget_max: numbers in AED. "under 800" => budget_max 800.
            - keywords: a few salient search words (English), excluding stopwords.
            - is_gift: true only if the shopper is clearly buying for someone else.
            - confidence: 0..1, how clearly the request was expressed.
            The shopper's device locale is "{$locale}"; reply structure only, no prose.
            PROMPT;
    }

    /**
     * @return array<string, mixed>
     */
    private static function schema(): array
    {
        $stringArray = ['type' => 'array', 'items' => ['type' => 'string']];

        return [
            'type' => 'object',
            'properties' => [
                'product_type' => ['type' => ['string', 'null']],
                'category_slug' => ['type' => ['string', 'null']],
                'occasions' => $stringArray,
                'colours' => $stringArray,
                'styles' => $stringArray,
                'budget_min' => ['type' => ['number', 'null']],
                'budget_max' => ['type' => ['number', 'null']],
                'keywords' => $stringArray,
                'vendor_hints' => $stringArray,
                'is_gift' => ['type' => 'boolean'],
                'confidence' => ['type' => 'number'],
            ],
            'required' => ['keywords', 'is_gift'],
            'additionalProperties' => false,
        ];
    }
}
