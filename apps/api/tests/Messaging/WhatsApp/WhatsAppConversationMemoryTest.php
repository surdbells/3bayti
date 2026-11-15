<?php

declare(strict_types=1);

namespace Bayti\Api\Tests\Messaging\WhatsApp;

use Bayti\Api\Ai\Analytics\AiEventLogger;
use Bayti\Api\Ai\Concierge\ConciergeCopy;
use Bayti\Api\Ai\Concierge\ConciergeRanker;
use Bayti\Api\Ai\Concierge\ConciergeService;
use Bayti\Api\Ai\Concierge\IntentParser;
use Bayti\Api\Ai\Concierge\ProductRetrievalService;
use Bayti\Api\Ai\Enrichment\ProductAiAttributesStore;
use Bayti\Api\Ai\NullAiProvider;
use Bayti\Api\Domain\Catalog\Product;
use Bayti\Api\Domain\Catalog\ProductRepository;
use Bayti\Api\Domain\Catalog\Vendor;
use Bayti\Api\Infrastructure\Cache\InMemoryKeyValueStore;
use Bayti\Api\Infrastructure\Cache\KeyValueStore;
use Bayti\Api\Infrastructure\Cache\KeyValueStoreException;
use Bayti\Api\Messaging\MessageResult;
use Bayti\Api\Messaging\MessagingChannelInterface;
use Bayti\Api\Messaging\WhatsApp\WhatsAppConversationService;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/** Records every outbound text. */
final class SpyMessagingChannel implements MessagingChannelInterface
{
    /** @var list<array{to: string, text: string}> */
    public array $sent = [];

    public function isEnabled(): bool
    {
        return true;
    }

    public function sendText(string $to, string $text): MessageResult
    {
        $this->sent[] = ['to' => $to, 'text' => $text];
        return new MessageResult(true, 'wamid.' . count($this->sent));
    }

    public function last(): string
    {
        return $this->sent[count($this->sent) - 1]['text'] ?? '';
    }
}

/** In-memory store that records the TTL of every set(), and can fail like a dead Redis. */
final class SpyKeyValueStore implements KeyValueStore
{
    public InMemoryKeyValueStore $inner;
    public bool $down = false;
    /** @var array<string, int> */
    public array $ttls = [];

    public function __construct()
    {
        $this->inner = new InMemoryKeyValueStore();
    }

    public function get(string $key): ?string
    {
        $this->guard();
        return $this->inner->get($key);
    }

    public function set(string $key, string $value, int $ttlSeconds = 0): void
    {
        $this->guard();
        $this->ttls[$key] = $ttlSeconds;
        $this->inner->set($key, $value, $ttlSeconds);
    }

    public function incr(string $key): int
    {
        $this->guard();
        return $this->inner->incr($key);
    }

    public function setIfAbsent(string $key, string $value, int $ttlSeconds): bool
    {
        $this->guard();
        return $this->inner->setIfAbsent($key, $value, $ttlSeconds);
    }

    public function expire(string $key, int $ttlSeconds): void
    {
        $this->guard();
        $this->inner->expire($key, $ttlSeconds);
    }

    public function delete(string $key): void
    {
        $this->guard();
        $this->inner->delete($key);
    }

    public function ping(): bool
    {
        return !$this->down;
    }

    private function guard(): void
    {
        if ($this->down) {
            throw new KeyValueStoreException('redis down');
        }
    }
}

#[CoversClass(WhatsAppConversationService::class)]
final class WhatsAppConversationMemoryTest extends TestCase
{
    private const FROM = '+971501234567';

    private SpyMessagingChannel $channel;
    private SpyKeyValueStore $store;

    protected function setUp(): void
    {
        $this->channel = new SpyMessagingChannel();
        $this->store = new SpyKeyValueStore();
    }

    #[Test]
    public function remembersTheConversationAndRefinesTheNextMessage(): void
    {
        $svc = $this->service();

        // Even a specific request is a conversation: Ain greets, then asks.
        $svc->handleInbound(self::FROM, 'black abaya for eid');
        self::assertStringStartsWith(ConciergeCopy::opening('en') . "\n\n" . ConciergeCopy::question('recipient', 'en'), $this->channel->last());
        self::assertStringNotContainsString('/product/', $this->channel->last());

        $svc->handleInbound(self::FROM, 'show me what you have');
        self::assertStringContainsString('/product/p-1', $this->channel->last());
        // Refine chips offered as text suggestions.
        self::assertStringContainsString('You can reply with:', $this->channel->last());
        self::assertStringContainsString('• Show more like these', $this->channel->last());

        $svc->handleInbound(self::FROM, 'in navy');
        self::assertStringContainsString('/product/p-1', $this->channel->last());

        $stored = $this->storedHistory();
        self::assertCount(6, $stored);
        self::assertSame(['role' => 'user', 'text' => 'black abaya for eid'], $stored[0]);
        // Only the question is remembered — never the greeting.
        self::assertSame(['role' => 'ain', 'text' => ConciergeCopy::question('recipient', 'en')], $stored[1]);
        self::assertSame('in navy', $stored[4]['text']);
        // Product payloads are never stored — only the reply text.
        self::assertStringNotContainsString('/product/', (string) json_encode($stored));
        // 30-minute idle TTL, re-armed every turn.
        self::assertSame([1800], array_values(array_unique(array_values(array_filter(
            $this->store->ttls,
            static fn (string $k): bool => str_starts_with($k, 'wa:conv:'),
            ARRAY_FILTER_USE_KEY,
        )))));
    }

    #[Test]
    public function memoryIsCappedAtTheLastTwelveEntries(): void
    {
        $svc = $this->service();
        for ($i = 0; $i < 8; $i++) {
            $svc->handleInbound(self::FROM, 'abaya number ' . $i . ' please now');
        }

        $stored = $this->storedHistory();
        self::assertCount(12, $stored);
        self::assertSame('abaya number 2 please now', $stored[0]['text']);
    }

    #[Test]
    public function resetCommandsClearTheMemory(): void
    {
        $svc = $this->service();
        $svc->handleInbound(self::FROM, 'black abaya');
        self::assertNotSame([], $this->storedHistory());

        $svc->handleInbound(self::FROM, 'Start over!');
        self::assertSame([], $this->storedHistory());
        self::assertStringStartsWith('Fresh start!', $this->channel->last());

        self::assertSame([], $this->storedAskedSlots());

        // The guided flow starts over from the first question.
        $svc->handleInbound(self::FROM, 'in navy');
        self::assertStringStartsWith(ConciergeCopy::question('recipient', 'en'), $this->channel->last());

        // A "hi" right after a reset: the first question — no second greeting,
        // no preset starter suggestions.
        $svc->handleInbound(self::FROM, 'start over');
        $svc->handleInbound(self::FROM, 'hi');
        self::assertStringStartsWith(ConciergeCopy::question('recipient', 'en'), $this->channel->last());
        self::assertStringNotContainsString(ConciergeCopy::opening('en'), $this->channel->last());
        self::assertStringNotContainsString('An abaya for Eid', $this->channel->last());
        self::assertSame(['recipient'], $this->storedAskedSlots());

        self::assertTrue(WhatsAppConversationService::isResetCommand('new'));
        self::assertTrue(WhatsAppConversationService::isResetCommand('ابدأ من جديد'));
        self::assertTrue(WhatsAppConversationService::isResetCommand('محادثة جديدة'));
        self::assertFalse(WhatsAppConversationService::isResetCommand('new black abaya'));
    }

    #[Test]
    public function anExpiredMemoryStartsAFreshConversation(): void
    {
        $svc = $this->service();
        $svc->handleInbound(self::FROM, 'black abaya');
        // Simulate the 30-minute idle TTL lapsing.
        $this->store->inner->flushAll();

        // A fresh conversation: Ain greets again, then the first question.
        $svc->handleInbound(self::FROM, 'in navy');
        self::assertStringStartsWith(ConciergeCopy::opening('en') . "\n\n" . ConciergeCopy::question('recipient', 'en'), $this->channel->last());
        self::assertSame(['recipient'], $this->storedAskedSlots());
    }

    #[Test]
    public function failsOpenWhenRedisIsDown(): void
    {
        $this->store->down = true;
        $svc = $this->service();

        $svc->handleInbound(self::FROM, 'black abaya');
        $svc->handleInbound(self::FROM, 'start over');
        $svc->handleInbound(self::FROM, 'in navy');

        self::assertCount(3, $this->channel->sent);
        self::assertStringContainsString('/product/p-1', $this->channel->sent[0]['text']);
        self::assertStringStartsWith('Here are some pieces for “in navy”.', $this->channel->last());
    }

    #[Test]
    public function aFreshGreetingGetsTheOpeningLineAndTheFirstQuestionInOneMessage(): void
    {
        $this->service()->handleInbound(self::FROM, 'hello');

        self::assertCount(1, $this->channel->sent);
        self::assertStringStartsWith(
            ConciergeCopy::opening('en') . "\n\n" . ConciergeCopy::question('recipient', 'en'),
            $this->channel->last(),
        );
        self::assertStringStartsWith("Hi, I'm Ain, your personal stylist at 3bayti.", $this->channel->last());
        self::assertStringNotContainsString('/product/', $this->channel->last());
        self::assertStringContainsString('• For me', $this->channel->last());
        self::assertStringContainsString('• Show me what you have', $this->channel->last());
        self::assertSame(['recipient'], $this->storedAskedSlots());
        self::assertSame([
            ['role' => 'user', 'text' => 'hello'],
            ['role' => 'ain', 'text' => ConciergeCopy::question('recipient', 'en')],
        ], $this->storedHistory());

        // Discovery continues: the next message is answered with the next question only.
        $this->service()->handleInbound(self::FROM, 'For me');
        self::assertStringStartsWith(ConciergeCopy::question('occasion', 'en'), $this->channel->last());
    }

    #[Test]
    public function aSpecificFirstMessageStillGetsEveryRemainingQuestionBeforeProducts(): void
    {
        $svc = $this->service();
        $svc->handleInbound(self::FROM, 'an embellished black abaya for my mother for eid');
        self::assertStringStartsWith(ConciergeCopy::opening('en') . "\n\n" . ConciergeCopy::question('budget', 'en'), $this->channel->last());
        self::assertStringNotContainsString('/product/', $this->channel->last());

        $svc->handleInbound(self::FROM, 'Under 300 AED');
        self::assertStringContainsString('/product/p-1', $this->channel->last());
        self::assertStringNotContainsString(ConciergeCopy::opening('en'), $this->channel->last());
    }

    #[Test]
    public function anArabicMessageFromAnUnlinkedNumberGetsArabicCopy(): void
    {
        $svc = $this->service();

        $svc->handleInbound(self::FROM, 'أبحث عن عباية سوداء للعيد');
        self::assertStringStartsWith(ConciergeCopy::opening('ar') . "\n\n" . ConciergeCopy::question('recipient', 'ar'), $this->channel->last());
        self::assertStringContainsString('يمكنكِ الرد بـ:', $this->channel->last());
        self::assertStringContainsString('• لي أنا', $this->channel->last());

        // Small talk mid-discovery (AI off) repeats the pending question.
        $svc->handleInbound(self::FROM, 'مرحبا');
        self::assertStringStartsWith(ConciergeCopy::question('recipient', 'ar'), $this->channel->last());

        $svc->handleInbound(self::FROM, 'ابدأ من جديد');
        self::assertStringStartsWith('بداية جديدة!', $this->channel->last());

        // A Latin-script message stays English (no second greeting after the reset).
        $svc->handleInbound(self::FROM, 'black abaya for a wedding');
        self::assertStringStartsWith(ConciergeCopy::question('recipient', 'en'), $this->channel->last());
    }

    #[Test]
    public function guidedQuestionsPersistAskedSlotsUntilTheShopperSkips(): void
    {
        $svc = $this->service();

        $svc->handleInbound(self::FROM, 'abaya');
        self::assertStringStartsWith(ConciergeCopy::opening('en') . "\n\n" . ConciergeCopy::question('recipient', 'en'), $this->channel->last());
        self::assertStringContainsString('• For me', $this->channel->last());
        self::assertStringContainsString('• Show me what you have', $this->channel->last());
        self::assertStringNotContainsString('/product/', $this->channel->last());
        self::assertSame(['recipient'], $this->storedAskedSlots());

        $svc->handleInbound(self::FROM, 'For me');
        self::assertStringStartsWith(ConciergeCopy::question('occasion', 'en'), $this->channel->last());
        self::assertStringContainsString('• Party / evening', $this->channel->last());
        self::assertSame(['recipient', 'occasion'], $this->storedAskedSlots());

        // The skip phrase, typed as plain text, shows products.
        $svc->handleInbound(self::FROM, 'show me what you have');
        self::assertStringContainsString('/product/p-1', $this->channel->last());
        self::assertSame(['recipient', 'occasion', 'type', 'colour', 'style', 'budget'], $this->storedAskedSlots());

        // Refinements now go straight to results.
        $svc->handleInbound(self::FROM, 'in navy');
        self::assertStringContainsString('/product/p-1', $this->channel->last());
    }

    #[Test]
    public function theArabicSkipPhraseIsUnderstood(): void
    {
        $svc = $this->service();
        $svc->handleInbound(self::FROM, 'عباية');
        self::assertStringStartsWith(ConciergeCopy::opening('ar') . "\n\n" . ConciergeCopy::question('recipient', 'ar'), $this->channel->last());

        $svc->handleInbound(self::FROM, 'أريني ما لديكِ');
        self::assertStringContainsString('/product/p-1', $this->channel->last());
    }

    #[Test]
    public function aMemoryStoredBeforeGuidedDiscoveryStillLoads(): void
    {
        // Legacy value: a bare history list that already showed results.
        $this->store->inner->set('wa:conv:' . hash('sha256', ltrim(self::FROM, '+')), (string) json_encode([
            ['role' => 'user', 'text' => 'black abaya'],
            ['role' => 'ain', 'text' => 'Here are some pieces for “black abaya”.'],
        ]), 1800);

        $this->service()->handleInbound(self::FROM, 'in navy');

        self::assertStringStartsWith('Here are some pieces for “black abaya in navy”.', $this->channel->last());
        self::assertCount(4, $this->storedHistory());
    }
    // ===== fixtures =====

    /** @return list<array{role: string, text: string}> */
    private function storedHistory(): array
    {
        $decoded = $this->storedConversation();
        /** @var list<array{role: string, text: string}> $history */
        $history = $decoded['history'] ?? [];
        return $history;
    }

    /** @return list<string> */
    private function storedAskedSlots(): array
    {
        /** @var list<string> $asked */
        $asked = $this->storedConversation()['asked_slots'] ?? [];
        return $asked;
    }

    /** @return array<string, mixed> */
    private function storedConversation(): array
    {
        $raw = $this->store->inner->get('wa:conv:' . hash('sha256', ltrim(self::FROM, '+')));
        if ($raw === null) {
            return [];
        }
        $decoded = json_decode($raw, true);
        self::assertIsArray($decoded);
        self::assertArrayHasKey('history', $decoded);
        self::assertArrayHasKey('asked_slots', $decoded);
        return $decoded;
    }

    private function service(): WhatsAppConversationService
    {
        $vendor = new Vendor('v-1', 'Store 1', 'v1@example.test');
        $vendor->approve();
        $product = new Product(vendor: $vendor, slug: 'p-1', name: 'Black Abaya');
        $product->setStatus('active');
        $product->setPrice('250.00');
        $ref = new \ReflectionProperty(Product::class, 'id');
        $ref->setAccessible(true);
        $ref->setValue($product, 1);

        $repo = $this->createMock(ProductRepository::class);
        $repo->method('findActivePaginated')->willReturn(['items' => [$product], 'total' => 1]);
        $catalogEm = $this->createMock(EntityManagerInterface::class);
        $catalogEm->method('getRepository')->willReturnCallback(
            static fn (string $class) => $class === Product::class ? $repo : null,
        );

        $ai = new NullAiProvider();
        $concierge = new ConciergeService(
            new IntentParser($ai),
            new ProductRetrievalService($catalogEm, $ai, new ProductAiAttributesStore($this->createMock(Connection::class))),
            new ConciergeRanker($ai),
        );

        // No linked user (repository is not a UserRepository) => locale en.
        $userEm = $this->createMock(EntityManagerInterface::class);
        $userEm->method('getRepository')->willReturn($repo);

        return new WhatsAppConversationService(
            $this->channel,
            $concierge,
            $userEm,
            new AiEventLogger($this->createMock(Connection::class)),
            $this->store,
            new NullLogger(),
        );
    }
}
