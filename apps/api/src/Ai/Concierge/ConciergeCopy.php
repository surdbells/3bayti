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
    public const MAX_FOLLOW_UPS = 3;

    public const KIND_FOUND = 'found';
    public const KIND_NO_RESULTS = 'no_results';
    public const KIND_GREETING = 'greeting';
    /** Retrieval matched nothing and served popular in-budget pieces instead. */
    public const KIND_CLOSEST = 'closest';

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
        ],
        'ar' => [
            self::KIND_FOUND => 'إليكِ بعض القطع لـ «{query}».',
            self::KIND_NO_RESULTS => 'لم أجد قطعة مطابقة لـ «{query}» حالياً. هل تجرّبين لوناً أو مناسبة أو ميزانية أخرى؟',
            self::KIND_GREETING => 'أهلاً، أنا عين! أخبريني عمّا تبحثين عنه — مناسبة أو لون أو ميزانية — وسأجد لكِ قطعاً مناسبة.',
            self::KIND_CLOSEST => 'لم أجد تطابقاً تاماً لـ «{query}»، لذا إليكِ بعض القطع الرائجة بدلاً من ذلك.',
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
