<?php

declare(strict_types=1);

namespace Bayti\Api\Domain\Chat;

/**
 * Detects personal contact information (phone, email, social handles,
 * postal address) in chat messages, in English and Arabic. Ported and
 * consolidated from the legacy ModerationService so customers and vendors
 * cannot exchange details to take the deal off-platform.
 *
 * Pure and stateless — given a string it returns what was found; the
 * caller decides whether to block, redact, or flag.
 */
final class ModerationService
{
    public const FLAG_PHONE   = 'phone';
    public const FLAG_EMAIL   = 'email';
    public const FLAG_SOCIAL  = 'social';
    public const FLAG_ADDRESS = 'address';

    /** @var array<string, list<string>> category => regex patterns */
    private const PATTERNS = [
        self::FLAG_PHONE => [
            '/(?:\+|00)?(?:971|966|965|968|974|973|20|962)\s*\d{1,2}\s*\d{3}\s*\d{4}/',
            '/(?:\+|00)?1?\s*\(?\d{3}\)?[\s.-]?\d{3}[\s.-]?\d{4}/',
            '/\b0\d{1,2}[\s.-]?\d{3}[\s.-]?\d{4}\b/',
            '/\b\d{10,14}\b/',
            '/[٠-٩]{10,14}/u',
            '/(?:رقم|هاتف|جوال|موبايل|واتساب|واتس)\s*[:.]?\s*[\d٠-٩\s\-\+]+/u',
            '/(?:call\s*me|my\s*number)\s*(?:at|on|is)?\s*[\d\s\-\+\(\)]+/i',
        ],
        self::FLAG_EMAIL => [
            '/[a-zA-Z0-9._%+-]+@[a-zA-Z0-9.-]+\.[a-zA-Z]{2,}/',
            '/[a-zA-Z0-9._%+-]+\s*@\s*[a-zA-Z0-9.-]+\s*\.\s*[a-zA-Z]{2,}/',
            '/[a-zA-Z0-9._%+-]+\s*\[\s*at\s*\]\s*[a-zA-Z0-9.-]+/i',
            '/(?:ايميل|بريد|إيميل)\s*[:.]?\s*[a-zA-Z0-9._%+\-@\s]+/u',
        ],
        self::FLAG_SOCIAL => [
            '/(?:instagram|insta|ig)\s*[:@\/]?\s*[a-zA-Z0-9._]{3,30}/i',
            '/(?:انستقرام|انستا)\s*[:@\/]?\s*[a-zA-Z0-9._]+/u',
            '/(?:facebook|fb)\s*[:@\/]?\s*[a-zA-Z0-9._]+/i',
            '/(?:فيسبوك)\s*[:@\/]?\s*[a-zA-Z0-9._]+/u',
            '/(?:whatsapp|واتساب|واتس)\s*[:@]?\s*[\d٠-٩\s\-\+]+/iu',
            '/(?:snapchat|snap|سناب)\s*[:@\/]?\s*[a-zA-Z0-9._]+/iu',
            '/(?:telegram|تلغرام|تليجرام)\s*[:@\/]?\s*[a-zA-Z0-9._]+/iu',
            '/@[a-zA-Z0-9._]{3,30}/',
        ],
        self::FLAG_ADDRESS => [
            '/(?:street|st\.|road|rd\.|building|bldg|flat|apartment|apt|floor|villa)\s*[#:]?\s*\d+/i',
            '/(?:p\.?\s*o\.?\s*box|po\s*box)\s*\d+/i',
            '/\b\d+\s+[a-zA-Z]+\s+(?:street|st|road|rd|avenue|ave|drive|dr)\b/i',
            '/(?:شارع|طريق|بناية|عمارة|شقة|فيلا|منزل|حي)\s*[^\s,،]+/u',
            '/(?:صندوق\s*بريد|ص\.?\s*ب\.?)\s*\d+/u',
        ],
    ];

    public function check(string $content): ModerationResult
    {
        $content = trim($content);
        if ($content === '') {
            return ModerationResult::clean();
        }

        $matchesByType = [];
        foreach (self::PATTERNS as $type => $patterns) {
            foreach ($patterns as $pattern) {
                if (preg_match_all($pattern, $content, $m) && !empty($m[0])) {
                    $found = array_values(array_unique(array_map('trim', $m[0])));
                    $matchesByType[$type] = array_values(array_unique(
                        array_merge($matchesByType[$type] ?? [], $found)
                    ));
                }
            }
        }

        if ($matchesByType === []) {
            return ModerationResult::clean();
        }
        return ModerationResult::flagged($matchesByType);
    }

    /**
     * Produce a copy of $content with every detected match replaced by a
     * mask. Used only when the redact policy is selected.
     */
    public function redact(string $content, ModerationResult $result, string $mask = '•••'): string
    {
        foreach ($result->allMatches() as $match) {
            if ($match !== '') {
                $content = str_replace($match, $mask, $content);
            }
        }
        return $content;
    }
}
