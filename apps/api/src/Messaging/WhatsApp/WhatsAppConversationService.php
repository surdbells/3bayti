<?php

declare(strict_types=1);

namespace Bayti\Api\Messaging\WhatsApp;

use Bayti\Api\Ai\Analytics\AiEventLogger;
use Bayti\Api\Ai\Concierge\ConciergeCopy;
use Bayti\Api\Ai\Concierge\ConciergeItem;
use Bayti\Api\Ai\Concierge\ConciergeService;
use Bayti\Api\Ai\Concierge\ConversationHistory;
use Bayti\Api\Ai\Concierge\DiscoveryPlanner;
use Bayti\Api\Domain\User\User;
use Bayti\Api\Domain\User\UserRepository;
use Bayti\Api\Infrastructure\Cache\KeyValueStore;
use Bayti\Api\Infrastructure\Cache\KeyValueStoreException;
use Bayti\Api\Messaging\MessagingChannelInterface;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;

/**
 * Turns an inbound WhatsApp text into an Ain concierge reply.
 *
 * The concierge pipeline is channel-agnostic, so this reuses
 * {@see ConciergeService::ask()} unchanged: the inbound text becomes real,
 * re-validated (isOrderable+isInStock), in-stock product cards. Discovery works
 * for anyone who messages — linking a WhatsApp number to an account (done in the
 * app) only adds personalisation + analytics attribution.
 *
 * Transactions are NOT performed here: cart/checkout are auth-gated with no
 * anonymous path, so each product carries a slug deep link
 * ({APP_URL}/product/{slug}) that drops the shopper into their authenticated
 * web/app session to add-to-cart and check out. The reply is free-form text sent
 * inside the customer-initiated 24-hour window, so no message template is needed.
 *
 * Conversation memory: the last {@see ConversationHistory::MAX_TURNS} entries
 * (shopper text + Ain's reply text, never product payloads) per number live in
 * the KeyValueStore (Redis) under a hashed key with a 30-minute idle TTL, and
 * are passed to the concierge as history so "cheaper" / "in black" refine the
 * previous request. "new" / "reset" / "start over" (en/ar) clears it. Guided
 * discovery's asked_slots are stored alongside the history, so Ain asks every
 * slot the shopper has not given yet (at most 6 short questions) before
 * showing pieces; "show me what you have" / "أريني ما لديكِ" skips them.
 *
 * The first message of a fresh conversation (no memory) is answered with Ain's
 * opening line followed by the first question, in ONE message (the stored
 * history keeps only the question, so a later turn still recognises it).
 *
 * Memory fails OPEN: no Redis = no memory, Ain still answers (straight to
 * results). Follow-ups are offered as plain-text suggestions — the channel
 * only sends text (no interactive quick-reply buttons, and the webhook only
 * reads `text` messages).
 */
final class WhatsAppConversationService
{
    private const REPLY_LIMIT = 5;
    private const HOUR_SECONDS = 3600;
    private const PER_NUMBER_HOURLY_CAP = 30;

    /** Rate-limit outcomes. */
    private const RL_OK = 'ok';
    private const RL_JUST_OVER = 'just_over';
    private const RL_OVER = 'over';

    /** WhatsApp rejects a text body over 4096 chars; stay safely under it. */
    private const MAX_BODY_CHARS = 3800;
    private const MAX_REASON_CHARS = 160;

    /** Conversation memory per number is forgotten after 30 idle minutes. */
    private const HISTORY_TTL_SECONDS = 1800;

    /** Whole-message commands that clear the memory (normalised, en + ar). */
    private const RESET_COMMANDS = [
        'new', 'reset', 'start over', 'restart', 'new chat', 'clear', 'start again',
        'جديد', 'محادثة جديدة', 'ابدأ من جديد', 'ابدئي من جديد', 'من جديد', 'إعادة', 'اعادة', 'مسح',
    ];

    public function __construct(
        private readonly MessagingChannelInterface $channel,
        private readonly ConciergeService $concierge,
        private readonly EntityManagerInterface $em,
        private readonly AiEventLogger $events,
        private readonly KeyValueStore $cache,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function handleInbound(string $from, string $text): void
    {
        $text = trim($text);
        if ($text === '' || $from === '') {
            return;
        }

        $user = $this->resolveUser($from);
        $locale = $this->resolveLocale($user, $text);

        /* Cost control on a public inbound channel: cap concierge calls per
           number per hour. Send the "slow down" notice only on the crossing,
           then stay silent, so the cap itself can't emit unbounded sends. */
        $rl = $this->rateLimitState($from);
        if ($rl === self::RL_OVER) {
            return;
        }
        if ($rl === self::RL_JUST_OVER) {
            $this->safeSend($from, $this->message($locale, 'rate_limited'));
            return;
        }

        if (mb_strlen($text) > ConversationHistory::MAX_TEXT_CHARS) {
            $text = mb_substr($text, 0, ConversationHistory::MAX_TEXT_CHARS);
        }

        // "new" / "reset" / "start over" wipes the conversation memory. The
        // reply invites a fresh request (no preset suggestions); an EMPTY
        // conversation is stored so the next message goes straight into the
        // questions instead of greeting the shopper a second time.
        if (self::isResetCommand($text)) {
            $this->forgetHistory($from);
            $this->saveConversation($from, ConversationHistory::empty(), []);
            $this->safeSend($from, $this->message($locale, 'reset'));
            return;
        }

        [$history, $askedSlots, $fresh] = $this->loadConversation($from);

        try {
            // "show me what you have" (en/ar) is recognised by the planner itself.
            // An empty conversation (fresh, or just reset — the reset reply
            // already invited a request) goes straight into the first question:
            // "hi" never gets a second greeting with preset suggestions.
            $opening = $history->isEmpty() && $askedSlots !== DiscoveryPlanner::SLOTS;
            $result = $this->concierge->ask($text, $locale, self::REPLY_LIMIT, $history, $askedSlots, false, $opening);
        } catch (\Throwable $e) {
            $this->logger->warning('whatsapp.concierge_failed', ['error' => $e->getMessage()]);
            $this->safeSend($from, $this->message($locale, 'fallback'));
            return;
        }

        // Only Ain's own reply is remembered (an exact question template, so a
        // later turn still recognises what was asked) — never the greeting.
        $this->saveConversation($from, $history->withTurn($text, $result->reply), $result->askedSlots);
        $reply = $result->reply;
        if ($fresh) {
            $reply = ConciergeCopy::opening($locale) . ($reply !== '' ? "\n\n" . $reply : '');
        }

        $items = $result->items;
        $this->events->recordInteraction(
            'style_concierge',
            $text,
            $result->intent->toArray(),
            $result->productIds(),
            $user?->getId(),
            $this->sessionId($from),
            'WHATSAPP',
            $locale,
        );

        if ($items === []) {
            // A clarifying question, small talk or no match: Ain's reply text
            // (always set by the service) plus the follow-up suggestions.
            $reply = $reply !== '' ? $reply : $this->message($locale, 'empty');
            $this->safeSend($from, $this->withSuggestions($reply, $result->followUps, $locale));
            return;
        }

        $this->safeSend($from, $this->buildReply($items, $locale, $reply, $result->followUps));
    }

    /** True when the whole message asks to start the conversation over (en/ar). */
    public static function isResetCommand(string $text): bool
    {
        $norm = mb_strtolower(trim($text));
        $norm = (string) preg_replace('/[^\p{L}\p{M}\p{N}\s]+/u', '', $norm);
        $norm = trim((string) preg_replace('/\s+/u', ' ', $norm));
        return in_array($norm, self::RESET_COMMANDS, true);
    }

    /**
     * The last {@see ConversationHistory::MAX_TURNS} turns for this number plus
     * the guided-discovery asked_slots. Stored as {history, asked_slots}; a
     * pre-guided-discovery value (a bare history list) loads with asked_slots
     * null (the planner then infers from the history). No memory at all = a
     * fresh conversation ([] asked, fresh=true: the reply leads with Ain's
     * greeting). Fails OPEN: no Redis = no memory and no guided questions
     * (straight to results), Ain still answers.
     *
     * @return array{0: ConversationHistory, 1: list<string>|null, 2: bool}
     */
    private function loadConversation(string $from): array
    {
        try {
            $raw = $this->cache->get($this->historyKey($from));
        } catch (KeyValueStoreException $e) {
            $this->logger->warning('whatsapp.history_cache_failure — no memory', ['error' => $e->getMessage()]);
            // Without memory the answers could never accumulate (Ain would
            // re-ask forever), so skip guided discovery: straight to results.
            return [ConversationHistory::empty(), DiscoveryPlanner::SLOTS, false];
        }
        if ($raw === null || $raw === '') {
            return [ConversationHistory::empty(), [], true];
        }
        $decoded = json_decode($raw, true);
        if (is_array($decoded) && array_key_exists('history', $decoded)) {
            return [
                ConversationHistory::fromMixed($decoded['history']),
                DiscoveryPlanner::sanitizeAskedSlots($decoded['asked_slots'] ?? []) ?? [],
                false,
            ];
        }
        return [ConversationHistory::fromMixed($decoded), null, false];
    }

    /**
     * Persist with a sliding idle TTL (each turn re-arms it). Fails open.
     *
     * @param list<string> $askedSlots
     */
    private function saveConversation(string $from, ConversationHistory $history, array $askedSlots): void
    {
        $json = json_encode(['history' => $history->entries(), 'asked_slots' => $askedSlots], JSON_UNESCAPED_UNICODE);
        if ($json === false) {
            return;
        }
        try {
            $this->cache->set($this->historyKey($from), $json, self::HISTORY_TTL_SECONDS);
        } catch (KeyValueStoreException $e) {
            $this->logger->warning('whatsapp.history_cache_failure — not saved', ['error' => $e->getMessage()]);
        }
    }

    private function forgetHistory(string $from): void
    {
        try {
            $this->cache->delete($this->historyKey($from));
        } catch (KeyValueStoreException $e) {
            $this->logger->warning('whatsapp.history_cache_failure — not cleared', ['error' => $e->getMessage()]);
        }
    }

    private function historyKey(string $from): string
    {
        return 'wa:conv:' . hash('sha256', ltrim(trim($from), '+'));
    }

    /**
     * Append follow-up suggestions as plain text the shopper can copy/type back
     * (the channel only sends text — no interactive quick-reply buttons yet).
     *
     * @param list<string> $followUps
     */
    private function withSuggestions(string $text, array $followUps, string $locale): string
    {
        if ($followUps === []) {
            return $text;
        }
        $lines = [];
        foreach ($followUps as $f) {
            $lines[] = '• ' . $f;
        }
        return rtrim($text) . "\n\n" . $this->message($locale, 'suggestions') . "\n" . implode("\n", $lines);
    }

    private function resolveUser(string $from): ?User
    {
        $repo = $this->em->getRepository(User::class);
        if (!$repo instanceof UserRepository) {
            return null;
        }
        $normalised = '+' . ltrim(trim($from), '+');
        return $repo->findByWhatsAppPhone($normalised);
    }

    /**
     * A linked account's Arabic locale wins; otherwise (unlinked number, or an
     * account without 'ar') answer in the script the shopper wrote in, so an
     * Arabic message gets Arabic copy, reply and suggestions.
     */
    private function resolveLocale(?User $user, string $text = ''): string
    {
        if ($user !== null && str_starts_with(strtolower($user->getLocale()), 'ar')) {
            return 'ar';
        }
        if (preg_match('/\p{Arabic}/u', $text) === 1) {
            return 'ar';
        }
        return 'en';
    }

    /**
     * Ain's reply text first, then the product cards, then the outro + the
     * follow-up suggestions — one message, so ordering can never interleave.
     *
     * @param list<ConciergeItem> $items
     * @param list<string> $followUps
     */
    private function buildReply(array $items, string $locale, string $reply = '', array $followUps = []): string
    {
        $appUrl = rtrim((string) ($_ENV['APP_URL'] ?? ''), '/');
        $outro = $this->withSuggestions($this->message($locale, 'outro'), $followUps, $locale);
        $body = ($reply !== '' ? $reply : $this->message($locale, 'intro')) . "\n\n";
        $added = 0;

        foreach ($items as $item) {
            $product = $item->product;
            $price = $product->effectivePrice() . ' AED';
            $url = $appUrl . '/product/' . $product->getSlug();
            $reason = $this->truncate(trim($item->reason), self::MAX_REASON_CHARS);
            $block = '👗 *' . $product->getName() . "*\n"
                . ($reason !== '' ? ($price . ' — ' . $reason) : $price) . "\n"
                . '🔗 ' . $url . "\n\n";

            // Bound the whole message under WhatsApp's 4096-char limit so a
            // rich answer is trimmed rather than rejected+dropped whole. Always
            // keep at least one item.
            if ($added > 0 && mb_strlen($body . $block . $outro) > self::MAX_BODY_CHARS) {
                break;
            }
            $body .= $block;
            $added++;
        }

        return rtrim($body . $outro);
    }

    private function truncate(string $text, int $max): string
    {
        return mb_strlen($text) > $max ? rtrim(mb_substr($text, 0, $max - 1)) . '…' : $text;
    }

    /**
     * Per-number hourly cap. Uses an hour-BUCKETED key and re-asserts the TTL on
     * every hit, so a single lost EXPIRE can never orphan a TTL-less key (which
     * would permanently silence the number) — the bucket self-rotates each hour.
     * Fails OPEN on a cache error so a Redis blip never silences the assistant.
     *
     * @return self::RL_OK|self::RL_JUST_OVER|self::RL_OVER
     */
    private function rateLimitState(string $from): string
    {
        $bucket = intdiv(time(), self::HOUR_SECONDS);
        $key = 'wa:rl:' . hash('sha256', ltrim(trim($from), '+')) . ':' . $bucket;
        try {
            $count = $this->cache->incr($key);
            $this->cache->expire($key, self::HOUR_SECONDS + 60);
        } catch (KeyValueStoreException $e) {
            $this->logger->warning('whatsapp.ratelimit_cache_failure — allowing', ['error' => $e->getMessage()]);
            return self::RL_OK;
        }

        if ($count <= self::PER_NUMBER_HOURLY_CAP) {
            return self::RL_OK;
        }
        return $count === self::PER_NUMBER_HOURLY_CAP + 1 ? self::RL_JUST_OVER : self::RL_OVER;
    }

    private function safeSend(string $to, string $text): void
    {
        try {
            $this->channel->sendText($to, $text);
        } catch (\Throwable $e) {
            $this->logger->warning('whatsapp.send_failed', ['error' => $e->getMessage()]);
        }
    }

    /** A stable, non-reversible analytics session key per WhatsApp number. */
    private function sessionId(string $from): string
    {
        return 'wa:' . substr(hash('sha256', ltrim(trim($from), '+')), 0, 32);
    }

    private function message(string $locale, string $key): string
    {
        $en = [
            'intro' => 'Here are a few pieces Ain picked for you:',
            'outro' => 'Tap a link to view and buy on 3bayti.',
            'empty' => "Ain couldn't find a match for that — try describing the occasion, colour or budget.",
            'fallback' => 'Ain is having a moment. Please try again shortly.',
            'rate_limited' => "You're going a little fast — please try again in a bit.",
            'reset' => "Fresh start! What are you looking for today?",
            'suggestions' => 'You can reply with:',
        ];
        $ar = [
            'intro' => 'إليكِ بعض القطع التي اختارتها عين لكِ:',
            'outro' => 'اضغطي على أي رابط لعرض القطعة وشرائها من 3bayti.',
            'empty' => 'لم تجد عين قطعة مطابقة — جرّبي وصف المناسبة أو اللون أو الميزانية.',
            'fallback' => 'واجهت عين مشكلة مؤقتة. يُرجى المحاولة بعد قليل.',
            'rate_limited' => 'أنتِ سريعة قليلاً — يُرجى المحاولة بعد قليل.',
            'reset' => 'بداية جديدة! عمّ تبحثين اليوم؟',
            'suggestions' => 'يمكنكِ الرد بـ:',
        ];
        $table = $locale === 'ar' ? $ar : $en;
        return $table[$key] ?? '';
    }
}
