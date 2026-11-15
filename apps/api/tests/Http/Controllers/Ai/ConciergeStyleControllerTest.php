<?php

declare(strict_types=1);

namespace Bayti\Api\Tests\Http\Controllers\Ai;

use Bayti\Api\Ai\AiProviderInterface;
use Bayti\Api\Ai\Concierge\ConciergeCopy;
use Bayti\Api\Domain\Catalog\Product;
use Bayti\Api\Domain\Catalog\ProductRepository;
use Bayti\Api\Domain\Catalog\Vendor;
use Bayti\Api\Http\Controllers\Ai\AiStatusController;
use Bayti\Api\Http\Controllers\Ai\ConciergeStyleController;
use Bayti\Api\Tests\Http\HttpTestCase;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;

#[CoversClass(ConciergeStyleController::class)]
#[CoversClass(AiStatusController::class)]
final class ConciergeStyleControllerTest extends HttpTestCase
{
    /** Bind a disabled AI provider (deterministic keyword path, no external calls). */
    private function bindDisabledAi(): void
    {
        $ai = $this->createMock(AiProviderInterface::class);
        $ai->method('isEnabled')->willReturn(false);
        $ai->method('completeJson')->willReturn([]);
        $ai->method('embed')->willReturn([]);
        $this->bind(AiProviderInterface::class, $ai);
    }

    /** @param list<Product> $items */
    private function bindProducts(array $items): void
    {
        $productRepo = $this->createMock(ProductRepository::class);
        $productRepo->method('findActivePaginated')->willReturn(['items' => $items, 'total' => count($items)]);

        $em = $this->stubEm(function ($em) use ($productRepo): void {
            $em->method('getRepository')->willReturnCallback(
                static fn (string $class) => $class === Product::class ? $productRepo : null,
            );
        });
        $this->bind(EntityManagerInterface::class, $em);
    }

    private function makeProduct(int $id, string $name): Product
    {
        $vendor = new Vendor("v-{$id}", "Store {$id}", "v{$id}@example.test");
        $vendor->approve();
        $p = new Product(vendor: $vendor, slug: "p-{$id}", name: $name);
        $p->setStatus('active');
        $p->setPrice('250.00');
        $rp = new \ReflectionProperty($p, 'id');
        $rp->setAccessible(true);
        $rp->setValue($p, $id);
        return $p;
    }

    #[Test]
    public function returnsRankedRealProductsForAQuery(): void
    {
        $this->bindDisabledAi();
        $this->bindProducts([$this->makeProduct(1, 'Black Abaya'), $this->makeProduct(2, 'Beige Abaya')]);

        $res = $this->handle($this->jsonRequest('POST', '/v3/ai/concierge/style', [
            'query' => 'an elegant black abaya for a wedding',
            'locale' => 'en',
            'show_now' => true,
        ]));

        self::assertSame(200, $res->getStatusCode(), (string) $res->getBody());
        $decoded = json_decode((string) $res->getBody(), true);
        $data = $decoded['data'] ?? $decoded;
        self::assertArrayHasKey('interaction_id', $data);
        self::assertArrayHasKey('intent', $data);
        self::assertCount(2, $data['products']);
        // Cards are real product shapes + Ain's reason field.
        self::assertArrayHasKey('reason', $data['products'][0]);
        self::assertArrayHasKey('price', $data['products'][0]);
    }

    #[Test]
    public function withoutHistoryTheResponseStaysCompatibleAndAddsConversationalFields(): void
    {
        $this->bindDisabledAi();
        $this->bindProducts([$this->makeProduct(1, 'Black Abaya')]);

        // A specific first request is still a conversation: no shortcut to products.
        $res = $this->handle($this->jsonRequest('POST', '/v3/ai/concierge/style', ['query' => 'black abaya for eid', 'asked_slots' => []]));
        $data = $this->dataOf($res);
        self::assertSame([], $data['products']);
        self::assertSame('recipient', $data['question_slot']);
        self::assertSame(['recipient'], $data['asked_slots']);
        self::assertTrue($data['needs_clarification']);

        $res = $this->handle($this->jsonRequest('POST', '/v3/ai/concierge/style', ['query' => 'black abaya for eid', 'show_now' => true]));

        self::assertSame(200, $res->getStatusCode(), (string) $res->getBody());
        $data = $this->dataOf($res);
        // Existing contract untouched.
        self::assertArrayHasKey('interaction_id', $data);
        self::assertArrayHasKey('intent', $data);
        self::assertCount(1, $data['products']);
        // Conversational fields (AI off => localized template + refine chips).
        self::assertSame('Here are some pieces for “black abaya for eid”.', $data['reply']);
        self::assertIsArray($data['follow_ups']);
        self::assertNotEmpty($data['follow_ups']);
        self::assertLessThanOrEqual(6, count($data['follow_ups']));
        self::assertFalse($data['needs_clarification']);
        // Guided-discovery fields: results => no question, discovery complete.
        self::assertNull($data['question_slot']);
        self::assertSame(['recipient', 'occasion', 'type', 'colour', 'style', 'budget'], $data['asked_slots']);
    }

    #[Test]
    public function aBroadRequestGetsOneGuidedQuestionWithAnswerChips(): void
    {
        $this->bindDisabledAi();
        $this->bindProducts([$this->makeProduct(1, 'Black Abaya')]);

        $res = $this->handle($this->jsonRequest('POST', '/v3/ai/concierge/style', [
            'query' => 'abaya',
            'locale' => 'en',
            'asked_slots' => [],
        ]));

        self::assertSame(200, $res->getStatusCode(), (string) $res->getBody());
        $data = $this->dataOf($res);
        self::assertSame([], $data['products']);
        self::assertTrue($data['needs_clarification']);
        self::assertSame('recipient', $data['question_slot']);
        self::assertSame(['recipient'], $data['asked_slots']);
        self::assertSame('Happy to help! Is this for you, or a gift for someone special?', $data['reply']);
        self::assertSame(['For me', 'A gift', 'No preference', 'Show me what you have'], $data['follow_ups']);
    }

    #[Test]
    public function askedSlotsAreEchoedSanitisedAndDriveTheNextQuestion(): void
    {
        $this->bindDisabledAi();
        $this->bindProducts([$this->makeProduct(1, 'Black Abaya')]);

        $res = $this->handle($this->jsonRequest('POST', '/v3/ai/concierge/style', [
            'query' => 'For me',
            'locale' => 'ar',
            'asked_slots' => ['recipient', 'bogus', 7, 'RECIPIENT'],
            'history' => [
                ['role' => 'user', 'text' => 'عباية'],
                ['role' => 'ain', 'text' => 'يسعدني مساعدتكِ! هل هذه القطعة لكِ، أم هدية لشخص عزيز؟'],
            ],
        ]));

        self::assertSame(200, $res->getStatusCode(), (string) $res->getBody());
        $data = $this->dataOf($res);
        self::assertSame('occasion', $data['question_slot']);
        self::assertSame(['recipient', 'occasion'], $data['asked_slots']);
        self::assertSame('جميل! ما هي المناسبة؟', $data['reply']);
        self::assertCount(8, $data['follow_ups']);
        self::assertSame('أريني ما لديكِ', $data['follow_ups'][7]);
    }

    #[Test]
    public function showNowSkipsTheRemainingQuestions(): void
    {
        $this->bindDisabledAi();
        $this->bindProducts([$this->makeProduct(1, 'Black Abaya')]);

        /** @var list<array<string, mixed>> $events */
        $events = [];
        $conn = $this->createMock(\Doctrine\DBAL\Connection::class);
        $conn->method('insert')->willReturnCallback(static function (string $table, array $data) use (&$events): int {
            if ($table === 'ai_events') {
                $events[] = $data;
            }
            return 1;
        });
        $this->bind(\Bayti\Api\Ai\Analytics\AiEventLogger::class, new \Bayti\Api\Ai\Analytics\AiEventLogger($conn));

        $res = $this->handle($this->jsonRequest('POST', '/v3/ai/concierge/style', [
            'query' => 'Show me what you have',
            'asked_slots' => ['recipient'],
            'show_now' => true,
            'history' => [
                ['role' => 'user', 'text' => 'abaya'],
                ['role' => 'ain', 'text' => 'Happy to help! Is this for you, or a gift for someone special?'],
            ],
        ]));

        self::assertSame(200, $res->getStatusCode(), (string) $res->getBody());
        $data = $this->dataOf($res);
        self::assertCount(1, $data['products']);
        self::assertFalse($data['needs_clarification']);
        self::assertNull($data['question_slot']);
        self::assertSame(['recipient', 'occasion', 'type', 'colour', 'style', 'budget'], $data['asked_slots']);
        // The chip text is never searched: the subject comes from the intent.
        self::assertSame('Here are some pieces for “abaya”.', $data['reply']);

        $completed = null;
        foreach ($events as $e) {
            if ($e['event'] === 'ai_query_completed') {
                $completed = json_decode((string) $e['metadata'], true);
            }
        }
        self::assertIsArray($completed);
        self::assertNull($completed['question_slot']);
        self::assertSame(1, $completed['asked_count']);
        self::assertTrue($completed['show_now']);
    }

    #[Test]
    public function aLegacyClientWithoutAskedSlotsIsNeverStuckInQuestions(): void
    {
        $this->bindDisabledAi();
        $this->bindProducts([$this->makeProduct(1, 'Black Abaya')]);

        $history = [];
        foreach (['recipient', 'occasion', 'type', 'style_or_colour'] as $i => $slot) {
            $history[] = ['role' => 'user', 'text' => $i === 0 ? 'something nice' : 'No preference'];
            $history[] = ['role' => 'ain', 'text' => \Bayti\Api\Ai\Concierge\ConciergeCopy::question($slot, 'en')];
        }

        $res = $this->handle($this->jsonRequest('POST', '/v3/ai/concierge/style', [
            'query' => 'No preference',
            'history' => $history,
        ]));

        self::assertSame(200, $res->getStatusCode(), (string) $res->getBody());
        $data = $this->dataOf($res);
        self::assertCount(1, $data['products']);
        self::assertNull($data['question_slot']);
    }

    #[Test]
    public function aLegacyRequestSeesTheSkipChipWithinItsFirstThreeFollowUps(): void
    {
        $this->bindDisabledAi();
        $this->bindProducts([$this->makeProduct(1, 'Black Abaya')]);

        $res = $this->handle($this->jsonRequest('POST', '/v3/ai/concierge/style', ['query' => 'something nice']));

        self::assertSame(200, $res->getStatusCode(), (string) $res->getBody());
        $data = $this->dataOf($res);
        self::assertSame('recipient', $data['question_slot']);
        self::assertContains('Show me what you have', array_slice($data['follow_ups'], 0, 3));
    }

    #[Test]
    public function historyIsParsedLenientlyAndRecordedInEventMetadata(): void
    {
        $this->bindDisabledAi();
        $this->bindProducts([$this->makeProduct(1, 'Navy Abaya')]);

        /** @var list<array<string, mixed>> $events */
        $events = [];
        $conn = $this->createMock(\Doctrine\DBAL\Connection::class);
        $conn->method('insert')->willReturnCallback(static function (string $table, array $data) use (&$events): int {
            if ($table === 'ai_events') {
                $events[] = $data;
            }
            return 1;
        });
        $this->bind(\Bayti\Api\Ai\Analytics\AiEventLogger::class, new \Bayti\Api\Ai\Analytics\AiEventLogger($conn));

        $history = [
            'garbage',
            ['role' => 'robot', 'text' => 'nope'],
            ['role' => 'user', 'text' => 'black abaya'],
            ['role' => 'ain', 'text' => 'Here are some pieces for “black abaya”.'],
        ];
        for ($i = 0; $i < 14; $i++) {
            $history[] = ['role' => 'user', 'text' => 'abaya ' . $i];
        }

        $res = $this->handle($this->jsonRequest('POST', '/v3/ai/concierge/style', [
            'query' => 'in navy',
            'locale' => 'en',
            'history' => $history,
        ]));

        self::assertSame(200, $res->getStatusCode(), (string) $res->getBody());
        $data = $this->dataOf($res);
        // AI-off refinement: the request is rebuilt from the whole conversation
        // as structured fields (type + the latest colour); recognised words are
        // filters, not substring searches, so no keyword is left over.
        self::assertSame('abaya', $data['intent']['product_type']);
        self::assertSame(['navy'], $data['intent']['colours']);
        self::assertSame([], $data['intent']['keywords']);
        self::assertSame('Here are some pieces for “abaya 12 in navy”.', $data['reply']);

        $started = null;
        foreach ($events as $e) {
            if ($e['event'] === 'ai_query_started') {
                $started = json_decode((string) $e['metadata'], true);
            }
        }
        self::assertIsArray($started);
        self::assertSame(12, $started['history_length']);
    }

    #[Test]
    public function aNonArrayHistoryIsIgnoredNotRejected(): void
    {
        $this->bindDisabledAi();
        $this->bindProducts([$this->makeProduct(1, 'Black Abaya')]);

        $res = $this->handle($this->jsonRequest('POST', '/v3/ai/concierge/style', [
            'query' => 'black abaya',
            'history' => 'not-a-list',
            'asked_slots' => 'not-a-list',
            'show_now' => 'nope',
        ]));

        self::assertSame(200, $res->getStatusCode(), (string) $res->getBody());
        $data = $this->dataOf($res);
        // Treated as a fresh conversation: type + colour known, recipient asked.
        self::assertSame('abaya', $data['intent']['product_type']);
        self::assertSame(['black'], $data['intent']['colours']);
        self::assertSame('recipient', $data['question_slot']);
    }

    #[Test]
    public function aGreetingGetsTheFirstArabicQuestionAndNoProducts(): void
    {
        $this->bindDisabledAi();
        $this->bindProducts([$this->makeProduct(1, 'Black Abaya')]);

        $res = $this->handle($this->jsonRequest('POST', '/v3/ai/concierge/style', [
            'query' => 'مرحبا',
            'locale' => 'ar',
        ]));

        self::assertSame(200, $res->getStatusCode(), (string) $res->getBody());
        $data = $this->dataOf($res);
        self::assertSame([], $data['products']);
        self::assertSame(ConciergeCopy::question('recipient', 'ar'), $data['reply']);
        self::assertSame('recipient', $data['question_slot']);
        self::assertNotEmpty($data['follow_ups']);
        self::assertTrue($data['needs_clarification']);
    }

    #[Test]
    public function aFirstHiAfterTheLocalOpeningBubbleGetsTheFirstQuestionNotASecondGreeting(): void
    {
        // AI ON: the model calls "hi" small talk and writes its own greeting +
        // starter suggestions.
        $ai = $this->createMock(AiProviderInterface::class);
        $ai->method('isEnabled')->willReturn(true);
        $ai->method('completeJson')->willReturn([
            'keywords' => [], 'is_gift' => false,
            'reply' => "Hi there, I'm Ain! What are you looking for today?",
            'follow_ups' => ['An abaya for Eid', 'A gift for my mother'],
            'needs_clarification' => false, 'small_talk' => true,
        ]);
        $ai->method('embed')->willReturn([]);
        $this->bind(AiProviderInterface::class, $ai);
        $this->bindProducts([$this->makeProduct(1, 'Black Abaya')]);

        // A guided client (asked_slots sent) already showed Ain's opening bubble.
        $res = $this->handle($this->jsonRequest('POST', '/v3/ai/concierge/style', [
            'query' => 'hi',
            'history' => [],
            'asked_slots' => [],
        ]));

        self::assertSame(200, $res->getStatusCode(), (string) $res->getBody());
        $data = $this->dataOf($res);
        self::assertSame([], $data['products']);
        self::assertSame(ConciergeCopy::question('recipient', 'en'), $data['reply']);
        self::assertSame(ConciergeCopy::questionChips('recipient', 'en'), $data['follow_ups']);
        self::assertNotContains('An abaya for Eid', $data['follow_ups']);
        self::assertSame('recipient', $data['question_slot']);
        self::assertSame(['recipient'], $data['asked_slots']);
    }

    /** @return array<string, mixed> */
    private function dataOf(\Psr\Http\Message\ResponseInterface $res): array
    {
        $decoded = json_decode((string) $res->getBody(), true);
        return $decoded['data'] ?? $decoded;
    }

    #[Test]
    public function rejectsAnEmptyQuery(): void
    {
        $this->bindDisabledAi();
        $this->bindProducts([]);

        $res = $this->handle($this->jsonRequest('POST', '/v3/ai/concierge/style', ['query' => '   ']));
        self::assertSame(422, $res->getStatusCode(), (string) $res->getBody());
    }

    #[Test]
    public function statusReportsWhetherAinIsEnabled(): void
    {
        $this->bindDisabledAi();

        $res = $this->handle($this->jsonRequest('GET', '/v3/ai/status', []));
        self::assertSame(200, $res->getStatusCode(), (string) $res->getBody());
        $decoded = json_decode((string) $res->getBody(), true);
        $data = $decoded['data'] ?? $decoded;
        self::assertFalse($data['enabled']);
    }
}
