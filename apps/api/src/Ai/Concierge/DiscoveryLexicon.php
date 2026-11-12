<?php

declare(strict_types=1);

namespace Bayti\Api\Ai\Concierge;

/**
 * Deterministic (no-AI) English + Arabic lexicon for Ain's guided discovery.
 *
 * It recognises what a shopper message says about each discovery slot — who it
 * is for, the occasion, the product type, colours/styles and the budget — so
 * the {@see DiscoveryPlanner} can tell which slots are already filled even when
 * AI is off, and so a keyword-only (AI-off) intent can be enriched with real
 * structured fields (type, colours, budget…) instead of searching for the
 * literal text of an answer chip ("Under 300 AED", "For me").
 *
 * Every predefined answer / refine chip in {@see ConciergeCopy} is recognised
 * here, in both locales. Also detects the explicit "show me what you have"
 * (skip the questions) intent and whether a message carries nothing beyond
 * recognised terms + filler words ("consumed"), so its literal text is not used
 * as a search keyword.
 *
 * Output tags are canonical ENGLISH (like the model's), so both languages
 * funnel into the same retrieval.
 */
final class DiscoveryLexicon
{
    public const RECIPIENT_SELF = 'self';
    public const RECIPIENT_GIFT = 'gift';

    /** Canonical product type => terms. Arabic terms are stems (prefixes/suffixes allowed). '=' = exact word. */
    private const TYPES = [
        'abaya' => ['abaya', 'abaaya', 'عباي'],
        'kaftan' => ['kaftan', 'caftan', 'قفطان', 'كفتان'],
        'jalabiya' => ['jalabiya', 'jalabiyya', 'jalabia', 'jalabiyah', 'جلابي'],
        'dress' => ['dress', 'gown', 'فستان', 'فساتين'],
        'set' => ['modest set', 'co-ord', 'coord set', 'set', 'طقم', 'اطقم'],
        'accessories' => ['accessories', 'accessory', 'اكسسوار'],
        'bag' => ['handbag', 'bag', 'clutch', 'شنط', 'حقيب'],
        'shoes' => ['shoes', 'shoe', 'heels', 'sandals', 'حذاء', 'احذي'],
        'hijab' => ['hijab', 'shayla', 'sheila', 'scarf', 'scarves', 'حجاب', 'شيله', 'طرحه'],
        'mukhawar' => ['mukhawar', 'mukhawir', 'مخور'],
        'jewellery' => ['jewellery', 'jewelry', 'necklace', 'earrings', 'مجوهر'],
    ];

    private const OCCASIONS = [
        'eid' => ['eid', 'عيد'],
        'wedding' => ['wedding', 'bride', 'bridal', 'زفاف', 'عرس', 'زواج', 'عروس', 'ملكه'],
        'everyday' => ['everyday', 'every day', 'daily', 'casual', 'day to day', 'يومي', 'كاجوال'],
        'work' => ['work', 'office', 'workwear', 'عمل', 'دوام', 'مكتب'],
        'ramadan' => ['ramadan', 'iftar', 'suhoor', 'رمضان', 'افطار'],
        'party' => ['party', 'parties', 'evening', 'soiree', 'dinner', 'حفل', 'سهره', 'سهرات'],
        'graduation' => ['graduation', 'تخرج'],
        'engagement' => ['engagement', 'خطوبه'],
    ];

    private const COLOURS = [
        'black' => ['black', '=اسود', '=سوداء', '=بالاسود', '=الاسود', '=باللون الاسود'],
        'white' => ['white', '=ابيض', '=بيضاء', '=الابيض'],
        'beige' => ['beige', 'بيج'],
        'cream' => ['cream', 'ivory', 'كريمي', 'عاجي'],
        'navy' => ['navy', 'كحلي'],
        'blue' => ['blue', '=ازرق', '=زرقاء', '=الازرق'],
        'red' => ['red', '=احمر', '=حمراء', '=الاحمر'],
        'maroon' => ['maroon', 'burgundy', 'wine', 'عنابي', 'خمري'],
        'green' => ['green', 'olive', '=اخضر', '=خضراء', '=الاخضر', 'زيتي'],
        'pink' => ['pink', 'blush', 'وردي', 'زهري'],
        'purple' => ['purple', 'lilac', 'lavender', 'بنفسجي', 'ليلكي'],
        'grey' => ['grey', 'gray', 'رمادي'],
        'brown' => ['brown', 'camel', '=بني', '=البني', '=بنيه'],
        'gold' => ['gold', 'golden', 'ذهبي'],
        'silver' => ['silver', 'فضي'],
    ];

    private const STYLES = [
        'classic' => ['classic', 'timeless', 'كلاسيك'],
        'embellished' => ['embellished', 'embroidered', 'embroidery', 'beaded', 'sequin', 'sparkly', 'مطرز', 'تطريز', 'مزين', 'خرز', 'ترتر'],
        'minimal' => ['minimal', 'minimalist', 'simple', 'plain', 'بسيط', 'ابسط', '=ساده'],
        'modern' => ['modern', 'contemporary', 'trendy', 'عصري'],
        'elegant' => ['elegant', 'chic', 'انيق'],
        'luxurious' => ['luxurious', 'luxury', 'فخم', 'فاخر'],
        'traditional' => ['traditional', 'تقليدي', 'تراثي'],
        'pastel' => ['pastel', 'باستيل'],
        'earthy' => ['earthy', 'earth tones', 'earthy tones', 'neutral', 'ترابي'],
        'colourful' => ['colourful', 'colorful', 'bright', 'vibrant', 'ملون', 'زاهي', '=بالوان اكثر'],
    ];

    private const SELF_TERMS = ['for me', 'for myself', 'myself', 'just me', '=لي', 'لنفسي', '=لي انا', '=الي انا'];

    private const GIFT_RELATIONS = [
        'mother', 'mom', 'mum', 'mama', 'sister', 'wife', 'friend', 'daughter', 'aunt', 'grandmother', 'grandma',
        'niece', 'cousin', 'colleague', 'teacher', 'boss', 'fiancee', 'girlfriend', 'bride', 'mother in law',
        'sister in law', 'bestie',
    ];

    private const GIFT_TERMS_AR = [
        'هديه', 'هدايا', '=لامي', '=لماما', '=لوالدتي', '=لاختي', '=لصديقتي', '=لزوجتي', '=لبنتي', '=لابنتي',
        '=لخالتي', '=لعمتي', '=لجدتي', '=للعروس', '=لحماتي', '=لمعلمتي', '=لشخص عزيز', '=لشخص',
    ];

    /** First words that mean "stop asking, show me the products" (normalised). */
    private const SHOW_NOW_FIRST_WORDS = ['show', 'skip', 'اريني', 'ارني', 'اعرضي', 'اعرض', 'وريني', 'فرجيني', 'تخطي', 'شوفيني'];

    /** Whole (normalised) phrases that also mean "show me the products". */
    private const SHOW_NOW_PHRASES = [
        'what do you have', 'what have you got', 'see what you have', 'let me see', 'go ahead', 'enough questions',
        'ماذا لديك', 'ما لديك', 'وش عندك', 'ايش عندك', 'شو عندك', 'يكفي اسئله', 'بدون اسئله',
    ];

    /** Polite lead-ins stripped before the show-now check. */
    private const LEAD_INS = ['please', 'just', 'ok', 'okay', 'now', 'can you', 'فقط', 'بس', 'لو سمحت', 'لو سمحتي', 'ممكن', 'طيب', 'الان'];

    /** Words that carry no search meaning on their own (normalised). */
    private const FILLERS = [
        'a', 'an', 'the', 'for', 'in', 'of', 'on', 'with', 'and', 'or', 'me', 'my', 'i', 'im', 'want', 'need', 'looking',
        'look', 'find', 'something', 'some', 'thing', 'things', 'please', 'show', 'just', 'what', 'you', 'have', 'more',
        'like', 'these', 'this', 'those', 'that', 'instead', 'one', 'ones', 'but', 'similar', 'to', 'is', 'it', 'no', 'any',
        'anything', 'preference', 'sure', 'not', 'matter', 'doesnt', 'dont', 'mind', 'whatever', 'surprise', 'maybe',
        'kind', 'type', 'style', 'styles', 'colour', 'color', 'colours', 'colors', 'tones', 'tone', 'piece', 'pieces',
        'outfit', 'outfits', 'nice', 'beautiful', 'pretty', 'lovely', 'new', 'wear', 'clothes', 'options', 'option',
        'everything', 'all', 'results', 'products', 'go', 'ahead', 'skip', 'questions', 'question', 'enough', 'cheaper',
        'cheap', 'affordable', 'less', 'under', 'over', 'above', 'below', 'aed', 'dhs', 'dh', 'dirham', 'dirhams', 'budget',
        'around', 'about', 'max', 'up', 'ok', 'okay', 'yes', 'idea', 'ideas', 'got', 'see', 'let', 'by', 'from', 'than',
        'من', 'في', 'على', 'الى', 'عن', 'مع', 'او', 'و', 'انا', 'اريد', 'ابغى', 'ابي', 'ابحث', 'ابغي', 'عندك', 'لديك', 'ما',
        'ماذا', 'شي', 'شيء', 'شيئا', 'لو', 'سمحت', 'ممكن', 'فقط', 'بس', 'اكثر', 'اقل', 'مثل', 'مثلها', 'مشابه', 'لكن',
        'بدلا', 'ذلك', 'بلون', 'اللون', 'لون', 'الوان', 'باللون', 'بالوان', 'قطعه', 'قطع', 'لا', 'يهم', 'بدون', 'تفضيل',
        'فرق', 'اي', 'درهم', 'دراهم', 'ميزانيه', 'حوالي', 'تقريبا', 'هل', 'نعم', 'اريني', 'ارني', 'اعرضي', 'المزيد', 'هذه',
        'هذا', 'للاستخدام', 'استخدام', 'ارخص', 'modest', 'محتشم', 'محتشمه', 'تحت', 'فوق', 'حتى', 'بحدود', 'حدود', 'نفس', 'اخرى', 'شيئ', 'فكره',
        'help', 'hi', 'hello', 'hey', 'thanks', 'thank', 'stuff', 'hmm', 'salam', 'مرحبا', 'اهلا', 'ساعديني', 'ساعدني', 'شكرا',
    ];

    /** Style tags that describe colour too: a new colour replaces them (and vice versa) on a refinement. */
    private const COLOUR_STYLES = ['pastel', 'earthy', 'colourful'];

    /** At most this many leftover free-text keywords are carried into an AI-off search. */
    public const MAX_RESIDUAL_KEYWORDS = 3;

    private const CURRENCY = '(?:aed|dhs?|dirhams?|درهم|دراهم)';

    /**
     * Lowercase, Arabic-normalised (no diacritics/tatweel, unified alef/ya/ta
     * marbuta), Western digits, punctuation dropped (keeps + - for budgets),
     * whitespace collapsed.
     */
    public static function normalize(string $text): string
    {
        $t = mb_strtolower($text);
        $t = strtr($t, [
            '٠' => '0', '١' => '1', '٢' => '2', '٣' => '3', '٤' => '4', '٥' => '5', '٦' => '6', '٧' => '7', '٨' => '8', '٩' => '9',
            '۰' => '0', '۱' => '1', '۲' => '2', '۳' => '3', '۴' => '4', '۵' => '5', '۶' => '6', '۷' => '7', '۸' => '8', '۹' => '9',
            'أ' => 'ا', 'إ' => 'ا', 'آ' => 'ا', 'ٱ' => 'ا', 'ى' => 'ي', 'ة' => 'ه', 'ـ' => '',
            '–' => '-', '—' => '-', '‑' => '-', '−' => '-', '’' => '', "'" => '', '،' => ' ',
        ]);
        $t = (string) preg_replace('/[\x{064B}-\x{065F}\x{0670}]/u', '', $t);
        $t = (string) preg_replace('/[^\p{L}\p{N}\s+\-]+/u', ' ', $t);
        return trim((string) preg_replace('/\s+/u', ' ', $t));
    }

    /**
     * What the given texts (oldest first) say about each slot. Later texts win
     * for single-valued answers (type, occasion, budget, recipient); colours
     * and styles accumulate — unless $latestLookWins (refinements after
     * results), where a message naming a colour replaces the earlier colours
     * (and colour-ish styles like "pastel"), and one naming a style replaces
     * the earlier styles ("Something more colourful" also drops "black").
     *
     * @param list<string> $texts
     * @return array{recipient: ?string, type: ?string, occasions: list<string>, colours: list<string>, styles: list<string>, budget_min: ?float, budget_max: ?float}
     */
    public static function extract(array $texts, bool $latestLookWins = false): array
    {
        $out = ['recipient' => null, 'type' => null, 'occasions' => [], 'colours' => [], 'styles' => [], 'budget_min' => null, 'budget_max' => null];
        foreach ($texts as $text) {
            $one = self::extractOne($text);
            if ($one['recipient'] !== null) {
                $out['recipient'] = $one['recipient'];
            }
            if ($one['types'] !== []) {
                $out['type'] = $one['types'][0];
            }
            if ($one['occasions'] !== []) {
                $out['occasions'] = $one['occasions'];
            }
            if ($latestLookWins && ($one['colours'] !== [] || $one['styles'] !== [])) {
                if ($one['colours'] !== []) {
                    $out['colours'] = $one['colours'];
                    $out['styles'] = array_values(array_diff($out['styles'], self::COLOUR_STYLES));
                }
                if ($one['styles'] !== []) {
                    $out['styles'] = $one['styles'];
                    if ($one['colours'] === [] && array_intersect($one['styles'], self::COLOUR_STYLES) !== []) {
                        $out['colours'] = [];
                    }
                }
            } else {
                $out['colours'] = array_values(array_unique([...$out['colours'], ...$one['colours']]));
                $out['styles'] = array_values(array_unique([...$out['styles'], ...$one['styles']]));
            }
            if ($one['budget_min'] !== null || $one['budget_max'] !== null) {
                $out['budget_min'] = $one['budget_min'];
                $out['budget_max'] = $one['budget_max'];
            }
        }
        return $out;
    }

    /**
     * True when the message explicitly asks to see products now ("show me",
     * "show me what you have", "just show", "skip", "أريني ما لديكِ"…).
     */
    public static function isShowNow(string $text): bool
    {
        $norm = self::stripLeadIns(self::normalize($text));
        if ($norm === '') {
            return false;
        }
        if (in_array($norm, self::SHOW_NOW_PHRASES, true)) {
            return true;
        }
        $first = explode(' ', $norm)[0];
        return in_array($first, self::SHOW_NOW_FIRST_WORDS, true);
    }

    /**
     * True when the message carries nothing beyond recognised slot terms,
     * budgets and filler words — so its literal text is a poor search keyword
     * ("Under 300 AED", "For me", "No preference", "Classic black").
     */
    public static function isConsumed(string $text): bool
    {
        return self::residualKeyword($text) === '';
    }

    /**
     * What a message says BEYOND the recognised slot terms, budgets and filler
     * words — the shopper's own free-text detail ("something with pearls" →
     * "pearls"), '' when nothing is left.
     */
    public static function residualKeyword(string $text): string
    {
        $one = self::extractOne($text);
        $kept = [];
        foreach (explode(' ', $one['remainder']) as $token) {
            if ($token === '' || mb_strlen($token) < 2 || is_numeric($token) || in_array($token, self::FILLERS, true)) {
                continue;
            }
            if (preg_match('/^[\d+\-]+$/', $token) === 1) {
                continue;
            }
            $kept[] = $token;
        }
        return implode(' ', $kept);
    }

    /**
     * The free-text details of the whole conversation for an AI-off search:
     * each message's {@see residualKeyword()}, NEWEST first, skipping small
     * talk and "show me" requests, deduped, max {@see MAX_RESIDUAL_KEYWORDS}.
     * So "something with pearls" still reaches retrieval after four chip
     * answers, while chip text ("Under 300 AED", "In black") never does.
     *
     * @param list<string> $texts oldest first
     * @return list<string>
     */
    public static function residualKeywords(array $texts): array
    {
        $out = [];
        foreach (array_reverse($texts) as $text) {
            if (ConciergeCopy::isSmallTalk($text) || self::isShowNow($text)) {
                continue;
            }
            $kw = self::residualKeyword($text);
            if ($kw === '' || in_array($kw, $out, true)) {
                continue;
            }
            $out[] = $kw;
            if (count($out) >= self::MAX_RESIDUAL_KEYWORDS) {
                break;
            }
        }
        return $out;
    }

    /** True when the message names anything for a discovery slot (recipient, type, occasion, colour/style, budget). */
    public static function recognisesAnything(string $text): bool
    {
        $one = self::extractOne($text);
        return $one['recipient'] !== null || $one['types'] !== [] || $one['occasions'] !== []
            || $one['colours'] !== [] || $one['styles'] !== []
            || $one['budget_min'] !== null || $one['budget_max'] !== null;
    }

    /**
     * Fill the intent's MISSING structured fields from the texts (never
     * overrides what the model extracted). With $dropConsumedKeywords (the
     * AI-off keyword intent), keywords that are only recognised terms/filler are
     * removed so retrieval searches the product type instead of chip text.
     * $budgetOnly restricts the fill to the budget (refinements after results).
     * $latestLookWins: see {@see extract()}.
     *
     * @param list<string> $texts oldest first
     */
    public static function enrich(
        ConciergeIntent $intent,
        array $texts,
        bool $dropConsumedKeywords,
        bool $budgetOnly = false,
        bool $latestLookWins = false,
    ): ConciergeIntent {
        $lex = self::extract($texts, $latestLookWins);

        $budgetMin = $intent->budgetMin;
        $budgetMax = $intent->budgetMax;
        if ($budgetMin === null && $budgetMax === null) {
            $budgetMin = $lex['budget_min'];
            $budgetMax = $lex['budget_max'];
        }

        $productType = $intent->productType;
        $occasions = $intent->occasions;
        $colours = $intent->colours;
        $styles = $intent->styles;
        $isGift = $intent->isGift;
        if (!$budgetOnly) {
            if ($productType === null && $intent->categorySlug === null) {
                $productType = $lex['type'];
            }
            $occasions = $occasions !== [] ? $occasions : $lex['occasions'];
            $colours = $colours !== [] ? $colours : $lex['colours'];
            $styles = $styles !== [] ? $styles : $lex['styles'];
            $isGift = $isGift || $lex['recipient'] === self::RECIPIENT_GIFT;
        }

        $keywords = $intent->keywords;
        if ($dropConsumedKeywords) {
            $keywords = array_values(array_filter(
                $keywords,
                static fn (string $k): bool => !self::isConsumed($k),
            ));
        }

        return new ConciergeIntent(
            $productType,
            $intent->categorySlug,
            $occasions,
            $colours,
            $styles,
            $budgetMin,
            $budgetMax,
            $keywords,
            $intent->vendorHints,
            $isGift,
            $intent->confidence,
        );
    }

    /**
     * @return array{recipient: ?string, types: list<string>, occasions: list<string>, colours: list<string>, styles: list<string>, budget_min: ?float, budget_max: ?float, remainder: string}
     */
    private static function extractOne(string $text): array
    {
        $work = ' ' . self::normalize($text) . ' ';

        $recipient = null;
        if (self::consume($work, self::giftTerms())) {
            $recipient = self::RECIPIENT_GIFT;
        }
        if (self::consume($work, self::SELF_TERMS) && $recipient === null) {
            $recipient = self::RECIPIENT_SELF;
        }

        [$budgetMin, $budgetMax] = self::consumeBudget($work);

        $types = self::consumeGroup($work, self::TYPES);
        $occasions = self::consumeGroup($work, self::OCCASIONS);
        $colours = self::consumeGroup($work, self::COLOURS);
        $styles = self::consumeGroup($work, self::STYLES);

        return [
            'recipient' => $recipient,
            'types' => $types,
            'occasions' => $occasions,
            'colours' => $colours,
            'styles' => $styles,
            'budget_min' => $budgetMin,
            'budget_max' => $budgetMax,
            'remainder' => trim((string) preg_replace('/\s+/u', ' ', $work)),
        ];
    }

    /** @return list<string> */
    private static function giftTerms(): array
    {
        $terms = ['gift', 'present', 'for her', 'for someone', 'someone special'];
        foreach (self::GIFT_RELATIONS as $rel) {
            $terms[] = 'for my ' . $rel;
            $terms[] = 'for ' . $rel;
        }
        return [...$terms, ...self::GIFT_TERMS_AR];
    }

    /**
     * Remove every match of the given terms from $work; true when any matched.
     *
     * @param list<string> $terms
     */
    private static function consume(string &$work, array $terms): bool
    {
        $hit = false;
        foreach ($terms as $term) {
            $pattern = self::termPattern($term);
            $count = 0;
            $work = (string) preg_replace($pattern, ' ', $work, -1, $count);
            if ($count > 0) {
                $hit = true;
            }
        }
        return $hit;
    }

    /**
     * @param array<string, list<string>> $group canonical => terms
     * @return list<string> canonical tags found, in group order
     */
    private static function consumeGroup(string &$work, array $group): array
    {
        $found = [];
        foreach ($group as $canonical => $terms) {
            if (self::consume($work, $terms)) {
                $found[] = $canonical;
            }
        }
        return $found;
    }

    /**
     * Parse + remove a budget: "under 300", "300-700 AED", "700+ AED",
     * "أقل من 300 درهم", "أكثر من 700 درهم", "500 AED".
     *
     * @return array{0: ?float, 1: ?float}
     */
    private static function consumeBudget(string &$work): array
    {
        $c = self::CURRENCY;
        $min = null;
        $max = null;

        $rules = [
            // Range with a currency, or after "between/budget".
            ['/(\d{2,6})\s*-\s*(\d{2,6})\s*' . $c . '/u', 'range'],
            ['/' . $c . '\s*(\d{2,6})\s*-\s*(\d{2,6})/u', 'range'],
            ['/(?:between|budget|بين|ميزانيه|ميزانيتي)\s*(\d{2,6})\s*(?:-|and|to|و|الى|حتى)\s*(\d{2,6})\s*' . $c . '?/u', 'range'],
            ['/(\d{2,6})\s*\+\s*' . $c . '?/u', 'min'],
            ['/(?:over|above|more than|at least|starting at|اكثر من|فوق|لا يقل عن)\s*' . $c . '?\s*(\d{2,6})\s*' . $c . '?/u', 'min'],
            ['/(?:under|below|less than|up to|upto|max|maximum|within|no more than|cheaper than|اقل من|تحت|حتى|بحدود|في حدود|لا يزيد عن|ما يزيد عن)\s*' . $c . '?\s*(\d{2,6})\s*' . $c . '?/u', 'max'],
            // Weak "from N AED" only after the max phrases ("أقل من 300" contains "من 300").
            ['/(?:from|من)\s*(\d{2,6})\s*' . $c . '/u', 'min'],
            ['/(\d{2,6})\s*' . $c . '/u', 'max'],
            ['/' . $c . '\s*(\d{2,6})/u', 'max'],
        ];

        foreach ($rules as [$pattern, $kind]) {
            if (preg_match($pattern, $work, $m) !== 1) {
                continue;
            }
            if ($kind === 'range') {
                $a = (float) $m[1];
                $b = (float) $m[2];
                $min = min($a, $b);
                $max = max($a, $b);
            } elseif ($kind === 'min') {
                $min = (float) $m[1];
            } else {
                $max = (float) $m[1];
            }
            $work = (string) preg_replace($pattern, ' ', $work, 1);
            break;
        }
        return [$min, $max];
    }

    private static function termPattern(string $term): string
    {
        $exact = str_starts_with($term, '=');
        if ($exact) {
            $term = substr($term, 1);
        }
        $q = str_replace(' ', '\s+', preg_quote($term, '/'));

        if (preg_match('/\p{Arabic}/u', $term) === 1) {
            if ($exact) {
                return '/(?<![\p{L}])' . $q . '(?![\p{L}])/u';
            }
            // Optional proclitics (و/ف + ب/ل/ك + ال, or لل) and a short suffix.
            return '/(?<![\p{L}])(?:و|ف)?(?:لل|(?:ب|ل|ك)?(?:ال)?)' . $q . '\p{L}{0,3}(?![\p{L}])/u';
        }
        if ($exact) {
            return '/(?<![\p{L}\p{N}])' . $q . '(?![\p{L}\p{N}])/u';
        }
        return '/(?<![\p{L}\p{N}])' . $q . '(?:s|es)?(?![\p{L}\p{N}])/u';
    }

    private static function stripLeadIns(string $norm): string
    {
        $changed = true;
        while ($changed && $norm !== '') {
            $changed = false;
            foreach (self::LEAD_INS as $lead) {
                if ($norm === $lead) {
                    return '';
                }
                if (str_starts_with($norm, $lead . ' ')) {
                    $norm = trim(substr($norm, strlen($lead)));
                    $changed = true;
                }
            }
        }
        return $norm;
    }
}
