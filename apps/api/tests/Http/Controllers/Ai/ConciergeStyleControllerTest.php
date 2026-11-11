<?php

declare(strict_types=1);

namespace Bayti\Api\Tests\Http\Controllers\Ai;

use Bayti\Api\Ai\AiProviderInterface;
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

        $res = $this->handle($this->jsonRequest('POST', '/v3/ai/concierge/style', ['query' => 'black abaya']));

        self::assertSame(200, $res->getStatusCode(), (string) $res->getBody());
        $data = $this->dataOf($res);
        // Existing contract untouched.
        self::assertArrayHasKey('interaction_id', $data);
        self::assertArrayHasKey('intent', $data);
        self::assertCount(1, $data['products']);
        // New conversational fields (AI off => localized template + fixed follow-ups).
        self::assertSame('Here are some pieces for “black abaya”.', $data['reply']);
        self::assertIsArray($data['follow_ups']);
        self::assertNotEmpty($data['follow_ups']);
        self::assertLessThanOrEqual(3, count($data['follow_ups']));
        self::assertFalse($data['needs_clarification']);
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
        for ($i = 0; $i < 10; $i++) {
            $history[] = ['role' => 'user', 'text' => 'abaya ' . $i];
        }

        $res = $this->handle($this->jsonRequest('POST', '/v3/ai/concierge/style', [
            'query' => 'in navy',
            'locale' => 'en',
            'history' => $history,
        ]));

        self::assertSame(200, $res->getStatusCode(), (string) $res->getBody());
        $data = $this->dataOf($res);
        // Short follow-up carried WITH the earlier shopper messages it refines
        // (AI-off rule), as separate keywords so retrieval can match each alone.
        self::assertSame(['in navy', 'abaya 9', 'abaya 8'], $data['intent']['keywords']);
        self::assertSame('Here are some pieces for “abaya 8 in navy”.', $data['reply']);

        $started = null;
        foreach ($events as $e) {
            if ($e['event'] === 'ai_query_started') {
                $started = json_decode((string) $e['metadata'], true);
            }
        }
        self::assertIsArray($started);
        self::assertSame(8, $started['history_length']);
    }

    #[Test]
    public function aNonArrayHistoryIsIgnoredNotRejected(): void
    {
        $this->bindDisabledAi();
        $this->bindProducts([$this->makeProduct(1, 'Black Abaya')]);

        $res = $this->handle($this->jsonRequest('POST', '/v3/ai/concierge/style', [
            'query' => 'black abaya',
            'history' => 'not-a-list',
        ]));

        self::assertSame(200, $res->getStatusCode(), (string) $res->getBody());
        self::assertSame(['black abaya'], $this->dataOf($res)['intent']['keywords']);
    }

    #[Test]
    public function aGreetingGetsAFriendlyArabicReplyAndNoProducts(): void
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
        self::assertStringContainsString('عين', $data['reply']);
        self::assertNotEmpty($data['follow_ups']);
        self::assertFalse($data['needs_clarification']);
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
