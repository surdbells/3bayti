<?php

declare(strict_types=1);

namespace Bayti\Api\Ai\Concierge;

/**
 * Ain's conversational copy: localized (en/ar) template replies + fixed
 * follow-up suggestions for when AI is off (or the model gave none), small-talk
 * detection for the AI-off path, and the server-side sanitiser for anything the
 * model writes (reply / follow-ups).
 *
 * The sanitiser is the trust boundary for model prose: it strips URLs and
 * markup, removes control characters, collapses whitespace and length-caps the
 * text, so even a prompt-injected history can only ever yield a short plain
 * sentence — never a link, HTML, or a wall of text.
 */
final class ConciergeCopy
{
    public const MAX_REPLY_CHARS = 300;
    public const MAX_FOLLOW_UP_CHARS = 40;
    /** Cap on MODEL-written follow-ups (sanitised). */
    public const MAX_FOLLOW_UPS = 3;
    /** Cap on the chips of a guided-question turn (answers + No preference + Show me). */
    public const MAX_QUESTION_CHIPS = 8;
    /** Cap on the refine chips under results. */
    public const MAX_REFINE_CHIPS = 6;
    /** How many follow-ups pre-guided-discovery clients display (they cut the list). */
    public const LEGACY_FOLLOW_UPS = 3;

    public const KIND_FOUND = 'found';
    public const KIND_NO_RESULTS = 'no_results';
    public const KIND_GREETING = 'greeting';
    /** Retrieval matched nothing and served popular in-budget pieces instead. */
    public const KIND_CLOSEST = 'closest';
    /** Results of a guided conversation with no short subject to quote. */
    public const KIND_PICKS = 'picks';

    /**
     * One short, warm question per discovery slot ({@see DiscoveryPlanner}).
     * These exact strings are also how a legacy client's history is recognised
     * as "already asked" — change them only together with that in mind.
     */
    private const QUESTIONS = [
        'en' => [
            DiscoveryPlanner::SLOT_RECIPIENT => 'Happy to help! Is this for you, or a gift for someone special?',
            DiscoveryPlanner::SLOT_OCCASION => "Lovely! What's the occasion?",
            DiscoveryPlanner::SLOT_TYPE => 'What kind of piece do you have in mind?',
            DiscoveryPlanner::SLOT_STYLE => 'Any colours or style you love?',
            DiscoveryPlanner::SLOT_BUDGET => 'And roughly what budget would you like me to stay within?',
        ],
        'ar' => [
            DiscoveryPlanner::SLOT_RECIPIENT => 'يسعدني مساعدتكِ! هل هذه القطعة لكِ، أم هدية لشخص عزيز؟',
            DiscoveryPlanner::SLOT_OCCASION => 'جميل! ما هي المناسبة؟',
            DiscoveryPlanner::SLOT_TYPE => 'ما نوع القطعة التي تفكّرين بها؟',
            DiscoveryPlanner::SLOT_STYLE => 'هل هناك ألوان أو أسلوب تحبّينه؟',
            DiscoveryPlanner::SLOT_BUDGET => 'وما الميزانية التقريبية التي تفضّلين أن أبقى ضمنها؟',
        ],
    ];

    /** Predefined ANSWER chips per slot (phrased as the shopper would answer). */
    private const ANSWER_CHIPS = [
        'en' => [
            DiscoveryPlanner::SLOT_RECIPIENT => ['For me', 'A gift'],
            DiscoveryPlanner::SLOT_OCCASION => ['Eid', 'Wedding', 'Everyday', 'Work', 'Ramadan', 'Party / evening'],
            DiscoveryPlanner::SLOT_TYPE => ['Abaya', 'Kaftan', 'Jalabiya', 'Dress', 'Modest set', 'Accessories'],
            DiscoveryPlanner::SLOT_STYLE => ['Classic black', 'Pastels', 'Earthy tones', 'Embellished', 'Minimal'],
            DiscoveryPlanner::SLOT_BUDGET => ['Under 300 AED', '300–700 AED', '700+ AED'],
        ],
        'ar' => [
            DiscoveryPlanner::SLOT_RECIPIENT => ['لي أنا', 'هدية'],
            DiscoveryPlanner::SLOT_OCCASION => ['العيد', 'حفل زفاف', 'للاستخدام اليومي', 'العمل', 'رمضان', 'حفلة / سهرة'],
            DiscoveryPlanner::SLOT_TYPE => ['عباية', 'قفطان', 'جلابية', 'فستان', 'طقم محتشم', 'إكسسوارات'],
            DiscoveryPlanner::SLOT_STYLE => ['أسود كلاسيكي', 'ألوان باستيل', 'ألوان ترابية', 'مطرّز', 'بسيط'],
            DiscoveryPlanner::SLOT_BUDGET => ['أقل من 300 درهم', '300–700 درهم', 'أكثر من 700 درهم'],
        ],
    ];

    /** Always offered on a question turn, in this order, as the LAST two chips. */
    private const NO_PREFERENCE = ['en' => 'No preference', 'ar' => 'لا يهم'];
    private const SHOW_NOW = ['en' => 'Show me what you have', 'ar' => 'أريني ما لديكِ'];

    /** Predefined REFINE chips under results (picked by what the intent leaves open). */
    private const REFINE = [
        'en' => [
            'in_black' => 'In black',
            'more_colourful' => 'Something more colourful',
            'under_300' => 'Under 300 AED',
            'cheaper' => 'Similar but cheaper',
            'embellished' => 'More embellished',
            'minimal' => 'Something more minimal',
            'wedding' => 'For a wedding instead',
            'eid' => 'For Eid instead',
            'more_like' => 'Show more like these',
            'gift' => 'A gift idea instead',
            'for_me' => 'Something for me instead',
        ],
        'ar' => [
            'in_black' => 'باللون الأسود',
            'more_colourful' => 'شيء بألوان أكثر',
            'under_300' => 'أقل من 300 درهم',
            'cheaper' => 'مشابه لكن أرخص',
            'embellished' => 'بتطريز أكثر',
            'minimal' => 'شيء أبسط',
            'wedding' => 'لحفل زفاف بدلاً من ذلك',
            'eid' => 'للعيد بدلاً من ذلك',
            'more_like' => 'أريني المزيد مثلها',
            'gift' => 'فكرة هدية بدلاً من ذلك',
            'for_me' => 'شيء لي بدلاً من ذلك',
        ],
    ];

    /**
     * Promise / commercial-claim words a model reply or follow-up must never
     * contain (Ain cannot see prices, stock, promotions or delivery). English is
     * matched on word boundaries; Arabic as substrings.
     */
    private const PROMISE_WORDS_EN = [
        'free', 'discount', 'discounts', 'discounted', 'sale', 'sales', 'promo', 'promotion', 'coupon', 'voucher',
        'cashback', 'refund', 'guarantee', 'guaranteed', 'in stock', 'out of stock', 'delivery', 'deliver',
        'shipping', 'whatsapp', 'call us', 'call me', 'limited time', 'today only', 'percent',
    ];
    private const PROMISE_WORDS_AR = [
        'مجان', 'خصم', 'تخفيض', 'تنزيلات', 'كوبون', 'قسيمة', 'توصيل', 'شحن', 'المخزون', 'ضمان', 'استرداد',
        'اتصل', 'واتساب', 'بالمئة', 'بالمائة', 'عرض خاص', 'لفترة محدودة',
    ];

    private const REPLIES = [
        'en' => [
            self::KIND_FOUND => 'Here are some pieces for “{query}”.',
            self::KIND_NO_RESULTS => "I couldn't find a match for “{query}” right now. Try another colour, occasion or budget?",
            self::KIND_GREETING => "Hi, I'm Ain! Tell me what you're looking for — an occasion, a colour or a budget — and I'll find pieces for you.",
            self::KIND_CLOSEST => "I couldn't find an exact match for “{query}”, so here are some popular pieces instead.",
            self::KIND_PICKS => 'Here are some pieces I picked for you.',
        ],
        'ar' => [
            self::KIND_FOUND => 'إليكِ بعض القطع لـ «{query}».',
            self::KIND_NO_RESULTS => 'لم أجد قطعة مطابقة لـ «{query}» حالياً. هل تجرّبين لوناً أو مناسبة أو ميزانية أخرى؟',
            self::KIND_GREETING => 'أهلاً، أنا عين! أخبريني عمّا تبحثين عنه — مناسبة أو لون أو ميزانية — وسأجد لكِ قطعاً مناسبة.',
            self::KIND_CLOSEST => 'لم أجد تطابقاً تاماً لـ «{query}»، لذا إليكِ بعض القطع الرائجة بدلاً من ذلك.',
            self::KIND_PICKS => 'إليكِ بعض القطع التي اخترتها لكِ.',
        ],
    ];

    private const FOLLOW_UPS = [
        'en' => [
            self::KIND_FOUND => ['Show me cheaper ones', 'In black', 'Something for a wedding'],
            self::KIND_NO_RESULTS => ['Show me abayas', 'Something under 500 AED', 'A gift idea'],
            self::KIND_GREETING => ['An abaya for Eid', 'A gift for my mother', 'Something under 500 AED'],
        ],
        'ar' => [
            self::KIND_FOUND => ['أريد خيارات أرخص', 'باللون الأسود', 'شيء لحفل زفاف'],
            self::KIND_NO_RESULTS => ['أرني عبايات', 'شيء بأقل من 500 درهم', 'فكرة هدية'],
            self::KIND_GREETING => ['عباية للعيد', 'هدية لأمي', 'شيء بأقل من 500 درهم'],
        ],
    ];

    /**
     * Whole-message greetings / thanks (normalised: lowercase, punctuation
     * stripped). A message must BE one of these — "hi, I want an abaya" is a
     * real request, not small talk.
     */
    private const SMALL_TALK = [
        'hi', 'hii', 'hello', 'hey', 'hey there', 'hi there', 'hello there', 'hiya', 'yo',
        'good morning', 'good evening', 'good afternoon',
        'thanks', 'thank you', 'thank you so much', 'thanks a lot', 'thx', 'ty', 'ok thanks', 'ok thank you',
        'salam', 'salaam', 'salam alaikum', 'assalamu alaikum', 'as salamu alaykum', 'marhaba',
        'how are you', 'bye', 'goodbye',
        'مرحبا', 'مرحباً', 'اهلا', 'أهلا', 'أهلاً', 'اهلين', 'هلا', 'السلام عليكم', 'سلام', 'صباح الخير', 'مساء الخير',
        'شكرا', 'شكراً', 'شكرا لك', 'شكراً لك', 'شكرا جزيلا', 'شكراً جزيلاً', 'مشكورة', 'مشكور', 'كيف حالك', 'مع السلامة',
    ];

    public static function locale(string $locale): string
    {
        return $locale === 'ar' ? 'ar' : 'en';
    }

    /** A localized template reply. {query} is replaced with a cleaned, capped query. */
    public static function reply(string $kind, string $locale, string $query = ''): string
    {
        $table = self::REPLIES[self::locale($locale)];
        $template = $table[$kind] ?? $table[self::KIND_FOUND];
        $q = self::clean($query, 60);
        if ($q === '') {
            // No usable subject: drop the quoted part gracefully.
            $q = self::locale($locale) === 'ar' ? 'طلبكِ' : 'your request';
        }
        return str_replace('{query}', $q, $template);
    }

    /** @return list<string> the fixed localized follow-up set for a reply kind. */
    public static function followUps(string $kind, string $locale): array
    {
        $table = self::FOLLOW_UPS[self::locale($locale)];
        return $table[$kind] ?? $table[self::KIND_FOUND];
    }

    /** Ain's localized question for a discovery slot. */
    public static function question(string $slot, string $locale): string
    {
        $table = self::QUESTIONS[self::locale($locale)];
        return $table[$slot] ?? $table[DiscoveryPlanner::SLOT_RECIPIENT];
    }

    /**
     * The chips for a guided-question turn: the slot's predefined answers, then
     * ALWAYS "No preference" and, LAST, "Show me what you have" (which the
     * client sends with show_now=true). Max {@see self::MAX_QUESTION_CHIPS}.
     *
     * $compact is for legacy clients (they send no asked_slots and cut
     * follow_ups to 3): the first two answers, then "Show me what you have",
     * so the way out is always visible.
     *
     * @return list<string>
     */
    public static function questionChips(string $slot, string $locale, bool $compact = false): array
    {
        $locale = self::locale($locale);
        $answers = self::ANSWER_CHIPS[$locale][$slot] ?? [];
        if ($compact) {
            return [...array_slice($answers, 0, self::LEGACY_FOLLOW_UPS - 1), self::SHOW_NOW[$locale]];
        }
        $answers = array_slice($answers, 0, self::MAX_QUESTION_CHIPS - 2);
        return [...$answers, self::NO_PREFERENCE[$locale], self::SHOW_NOW[$locale]];
    }

    /** True when the text is Ain's greeting template (either locale). */
    public static function isGreetingReply(string $text): bool
    {
        $text = trim($text);
        foreach (self::REPLIES as $table) {
            if ($table[self::KIND_GREETING] === $text) {
                return true;
            }
        }
        return false;
    }

    /** The localized "Show me what you have" (skip the questions) chip. */
    public static function showNowChip(string $locale): string
    {
        return self::SHOW_NOW[self::locale($locale)];
    }

    /** The localized "No preference" chip. */
    public static function noPreferenceChip(string $locale): string
    {
        return self::NO_PREFERENCE[self::locale($locale)];
    }

    /**
     * Which slot an Ain message asks about, when it is exactly one of the
     * question templates (either locale); null otherwise.
     */
    public static function questionSlotFor(string $text): ?string
    {
        $text = trim($text);
        foreach (self::QUESTIONS as $table) {
            foreach ($table as $slot => $q) {
                if ($q === $text) {
                    return $slot;
                }
            }
        }
        return null;
    }

    /**
     * Up to {@see self::MAX_REFINE_CHIPS} chips for under the results: the
     * $primary set first, then predefined refine chips for what the intent
     * leaves open (plus useful pivots), with the (sanitised) model follow-ups
     * filling the middle. Deduped case-insensitively.
     *
     * @param list<string> $modelFollowUps already sanitised
     * @param list<string> $primary        chips that must come first (e.g. no-results ideas)
     * @return list<string>
     */
    public static function refineChips(ConciergeIntent $intent, string $locale, array $modelFollowUps = [], array $primary = []): array
    {
        $r = self::REFINE[self::locale($locale)];
        $styles = array_map('mb_strtolower', $intent->styles);
        $occasions = array_map('mb_strtolower', $intent->occasions);

        $predefined = [
            $intent->colours === [] ? $r['in_black'] : $r['more_colourful'],
            // No budget yet → offer one; any budget ("700+ AED" too) → "cheaper",
            // never an "Under 300" that contradicts the shopper's own answer.
            $intent->budgetMax === null && $intent->budgetMin === null ? $r['under_300'] : $r['cheaper'],
            in_array('embellished', $styles, true) ? $r['minimal'] : $r['embellished'],
            in_array('wedding', $occasions, true) ? $r['eid'] : $r['wedding'],
            $r['more_like'],
            $intent->isGift ? $r['for_me'] : $r['gift'],
        ];

        // Primary first, then 3 predefined, the model's ideas, then the rest.
        $ordered = [...$primary, ...array_slice($predefined, 0, 3), ...$modelFollowUps, ...array_slice($predefined, 3)];
        $out = [];
        $seen = [];
        foreach ($ordered as $chip) {
            $key = mb_strtolower($chip);
            if ($chip === '' || isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;
            $out[] = $chip;
            if (count($out) >= self::MAX_REFINE_CHIPS) {
                break;
            }
        }
        return $out;
    }

    /** True when the whole message is a greeting / thanks / small talk. */
    public static function isSmallTalk(string $message): bool
    {
        $norm = mb_strtolower(trim($message));
        // Drop punctuation/emoji (keep letters, marks, digits, spaces).
        $norm = (string) preg_replace('/[^\p{L}\p{M}\p{N}\s]+/u', '', $norm);
        $norm = trim((string) preg_replace('/\s+/u', ' ', $norm));
        if ($norm === '') {
            return false;
        }
        return in_array($norm, self::SMALL_TALK, true);
    }

    public static function wordCount(string $message): int
    {
        $parts = preg_split('/\s+/u', trim($message), -1, PREG_SPLIT_NO_EMPTY);
        return is_array($parts) ? count($parts) : 0;
    }

    /** Sanitise the model's reply: plain text, no URLs/markup, capped. */
    public static function sanitizeReply(mixed $reply): string
    {
        return is_string($reply) ? self::clean($reply, self::MAX_REPLY_CHARS) : '';
    }

    /**
     * Sanitise the model's follow-ups: plain text, each <= 40 chars (longer
     * ones are dropped, not cut mid-phrase), case-insensitively deduped, max 3.
     *
     * @return list<string>
     */
    public static function sanitizeFollowUps(mixed $followUps): array
    {
        if (!is_array($followUps)) {
            return [];
        }
        $out = [];
        $seen = [];
        foreach ($followUps as $f) {
            if (!is_string($f)) {
                continue;
            }
            $f = self::clean($f, 0);
            // Follow-ups are phrased as the SHOPPER would type them, so a budget
            // ("Under 300 AED") is fine — but no %, phone numbers or promises.
            if ($f === '' || mb_strlen($f) > self::MAX_FOLLOW_UP_CHARS || !self::isSafeReply($f, [], true)) {
                continue;
            }
            $key = mb_strtolower($f);
            if (isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;
            $out[] = $f;
            if (count($out) >= self::MAX_FOLLOW_UPS) {
                break;
            }
        }
        return $out;
    }

    /**
     * Content gate for model-written prose (reply / follow-ups), applied AFTER
     * {@see clean()}. Ain never sees live prices, stock, promotions or delivery,
     * so reject text that states a percentage, a phone-like number, a promise
     * word (free delivery, discount, in stock, خصم, توصيل مجاني…) or a currency
     * amount that is not one of the shopper's own budget figures.
     *
     * @param list<float> $allowedAmounts the shopper's own budget figures (AED)
     * @param bool $allowAnyAmount follow-ups are shopper phrasing ("Under 300 AED")
     */
    public static function isSafeReply(string $text, array $allowedAmounts = [], bool $allowAnyAmount = false): bool
    {
        // Normalise Arabic-Indic / Persian digits so the numeric checks see them.
        $t = strtr($text, [
            '٠' => '0', '١' => '1', '٢' => '2', '٣' => '3', '٤' => '4', '٥' => '5', '٦' => '6', '٧' => '7', '٨' => '8', '٩' => '9',
            '۰' => '0', '۱' => '1', '۲' => '2', '۳' => '3', '۴' => '4', '۵' => '5', '۶' => '6', '۷' => '7', '۸' => '8', '۹' => '9',
        ]);
        $lower = mb_strtolower($t);

        if (preg_match('/[%٪]/u', $t) === 1) {
            return false;
        }
        // Phone-like: a run of digits/spaces/dashes/dots/parens with >= 9 digits.
        if (preg_match_all('/\+?\d[\d\s().-]{5,}\d/u', $t, $runs) > 0) {
            foreach ($runs[0] as $run) {
                if (preg_match_all('/\d/', $run) >= 9) {
                    return false;
                }
            }
        }
        foreach (self::PROMISE_WORDS_EN as $w) {
            if (preg_match('/(?<![\p{L}\p{N}])' . preg_quote($w, '/') . '(?![\p{L}\p{N}])/iu', $lower) === 1) {
                return false;
            }
        }
        if (preg_match('/\d\s*off\b/iu', $lower) === 1) {
            return false;
        }
        foreach (self::PROMISE_WORDS_AR as $w) {
            if (str_contains($t, $w)) {
                return false;
            }
        }

        if (!$allowAnyAmount) {
            $currency = '(?:aed|dhs?|dirhams?|درهم|دراهم|د\.إ)';
            $amounts = [];
            if (preg_match_all('/(\d[\d,]*(?:\.\d+)?)\s*' . $currency . '/iu', $t, $m) > 0) {
                $amounts = array_merge($amounts, $m[1]);
            }
            if (preg_match_all('/' . $currency . '\s*(\d[\d,]*(?:\.\d+)?)/iu', $t, $m) > 0) {
                $amounts = array_merge($amounts, $m[1]);
            }
            foreach ($amounts as $raw) {
                $value = (float) str_replace(',', '', $raw);
                $ok = false;
                foreach ($allowedAmounts as $allowed) {
                    if (abs($allowed - $value) < 0.01) {
                        $ok = true;
                        break;
                    }
                }
                if (!$ok) {
                    return false;
                }
            }
        }
        return true;
    }

    /**
     * Strip URLs, HTML/markdown markup and control characters; collapse
     * whitespace; cap at $max chars (0 = no cap) on a word boundary.
     */
    public static function clean(string $text, int $max): string
    {
        $text = strip_tags($text);
        // URLs and bare domains-with-paths.
        $text = (string) preg_replace('~\b(?:https?://|www\.)\S+~iu', '', $text);
        // Any dotted host-like token (label.label…tld, any 2-24 letter TLD) with an
        // optional path, bounded on both sides so "see.company" goes whole and
        // ordinary words / decimals ("1.5") are untouched.
        $text = (string) preg_replace('~(?<![\p{L}\p{N}-])[\p{L}\p{N}-]+(?:\.[\p{L}\p{N}-]+)*\.[a-z]{2,24}(?![\p{L}\p{N}-])(?:/\S*)?~iu', '', $text);
        // Markdown / markup characters.
        $text = (string) preg_replace('/[*_`#<>\[\]{}|~\\\\]+/u', '', $text);
        // Control characters, then whitespace collapse.
        $text = (string) preg_replace('/[\x00-\x1F\x7F]+/u', ' ', $text);
        $text = trim((string) preg_replace('/\s+/u', ' ', $text));

        if ($max > 0 && mb_strlen($text) > $max) {
            $cut = mb_substr($text, 0, $max - 1);
            $space = mb_strrpos($cut, ' ');
            if ($space !== false && $space > (int) ($max * 0.6)) {
                $cut = mb_substr($cut, 0, $space);
            }
            $text = rtrim($cut, " ,;:-—") . '…';
        }
        return $text;
    }
}
