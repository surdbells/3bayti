<?php

declare(strict_types=1);

namespace Bayti\Api\Messaging\WhatsApp;

use Bayti\Api\Ai\Analytics\AiEventLogger;
use Bayti\Api\Ai\Concierge\ConciergeItem;
use Bayti\Api\Ai\Concierge\ConciergeService;
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
        $locale = $this->resolveLocale($user);

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

        try {
            $result = $this->concierge->ask($text, $locale, self::REPLY_LIMIT);
        } catch (\Throwable $e) {
            $this->logger->warning('whatsapp.concierge_failed', ['error' => $e->getMessage()]);
            $this->safeSend($from, $this->message($locale, 'fallback'));
            return;
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
            $this->safeSend($from, $this->message($locale, 'empty'));
            return;
        }

        $this->safeSend($from, $this->buildReply($items, $locale));
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

    private function resolveLocale(?User $user): string
    {
        if ($user !== null && str_starts_with(strtolower($user->getLocale()), 'ar')) {
            return 'ar';
        }
        return 'en';
    }

    /**
     * @param list<ConciergeItem> $items
     */
    private function buildReply(array $items, string $locale): string
    {
        $appUrl = rtrim((string) ($_ENV['APP_URL'] ?? ''), '/');
        $outro = $this->message($locale, 'outro');
        $body = $this->message($locale, 'intro') . "\n\n";
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
        ];
        $ar = [
            'intro' => 'إليكِ بعض القطع التي اختارتها عين لكِ:',
            'outro' => 'اضغطي على أي رابط لعرض القطعة وشرائها من 3bayti.',
            'empty' => 'لم تجد عين قطعة مطابقة — جرّبي وصف المناسبة أو اللون أو الميزانية.',
            'fallback' => 'واجهت عين مشكلة مؤقتة. يُرجى المحاولة بعد قليل.',
            'rate_limited' => 'أنتِ سريعة قليلاً — يُرجى المحاولة بعد قليل.',
        ];
        $table = $locale === 'ar' ? $ar : $en;
        return $table[$key] ?? '';
    }
}
