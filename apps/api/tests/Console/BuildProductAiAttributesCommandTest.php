<?php

declare(strict_types=1);

namespace Bayti\Api\Tests\Console;

use Bayti\Api\Ai\AiException;
use Bayti\Api\Ai\AiProviderInterface;
use Bayti\Api\Ai\Enrichment\ProductAiAttributesStore;
use Bayti\Api\Console\BuildProductAiAttributesCommand;
use Bayti\Api\Domain\Catalog\Product;
use Bayti\Api\Domain\Catalog\ProductRepository;
use Bayti\Api\Domain\Catalog\Vendor;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * Enabled AI stub returning fixed tags + a fixed embedding. Optional hooks
 * let a test make individual calls fail (keyed by the product name in the
 * prompt) and count calls.
 */
final class EnrichAiProvider implements AiProviderInterface
{
    public int $tagCalls = 0;
    public int $embedCalls = 0;

    /**
     * @param (\Closure(string, int): void)|null $onTag   called with (user prompt, call #); may throw
     * @param (\Closure(int): void)|null         $onEmbed called with (call #); may throw
     */
    public function __construct(
        private readonly ?\Closure $onTag = null,
        private readonly ?\Closure $onEmbed = null,
    ) {
    }

    public function isEnabled(): bool
    {
        return true;
    }

    public function embedModel(): ?string
    {
        return 'm1';
    }

    public function completeJson(string $system, string $user, array $schema, string $schemaName = 'result'): array
    {
        $this->tagCalls++;
        if ($this->onTag !== null) {
            ($this->onTag)($user, $this->tagCalls);
        }
        return ['occasions' => ['eid'], 'colours' => ['black'], 'styles' => ['elegant'], 'search_text' => 'elegant black abaya'];
    }

    public function embed(array $texts): array
    {
        $this->embedCalls++;
        if ($this->onEmbed !== null) {
            ($this->onEmbed)($this->embedCalls);
        }
        return array_map(static fn (): array => [0.1, 0.2, 0.3], $texts);
    }
}

#[CoversClass(BuildProductAiAttributesCommand::class)]
final class BuildProductAiAttributesCommandTest extends TestCase
{
    /** @var int upsert (executeStatement) calls counted on the mocked connection */
    private int $upserts = 0;

    /** @var list<int> back-off sleeps the command asked for (seconds) */
    private array $sleeps = [];

    #[Test]
    public function enrichesNewProducts(): void
    {
        $tester = $this->tester(new EnrichAiProvider(), existingHashes: []);
        $tester->execute([]);

        self::assertSame(0, $tester->getStatusCode());
        self::assertStringContainsString('2 enriched', $tester->getDisplay());
        self::assertSame(2, $this->upserts);
    }

    #[Test]
    public function dryRunComputesButWritesNothing(): void
    {
        $tester = $this->tester(new EnrichAiProvider(), existingHashes: []);
        $tester->execute(['--dry-run' => true]);

        self::assertStringContainsString('Dry run', $tester->getDisplay());
        self::assertStringContainsString('2 enriched', $tester->getDisplay());
        self::assertStringContainsString('OpenAI is still called', $tester->getDisplay());
        self::assertSame(0, $this->upserts);
    }

    #[Test]
    public function skipsProductsWhoseSourceHashIsUnchanged(): void
    {
        // Feed back the exact hashes the command computes → nothing to do.
        $existing = [
            1 => $this->hash('m1', 'Abaya 1', 'd1'),
            2 => $this->hash('m1', 'Abaya 2', 'd2'),
        ];
        $ai = new EnrichAiProvider();
        $tester = $this->tester($ai, existingHashes: $existing);
        $tester->execute([]);

        self::assertStringContainsString('2 unchanged', $tester->getDisplay());
        self::assertSame(0, $this->upserts);
        self::assertSame(0, $ai->tagCalls, 'unchanged products must not call OpenAI');
    }

    #[Test]
    public function noOpWhenAiIsDisabled(): void
    {
        $disabled = $this->createMock(AiProviderInterface::class);
        $disabled->method('isEnabled')->willReturn(false);

        $tester = $this->tester($disabled, existingHashes: []);
        $tester->execute([]);

        self::assertStringContainsString('disabled', $tester->getDisplay());
        self::assertSame(0, $this->upserts);
    }

    #[Test]
    public function showsALiveProgressBarInATerminal(): void
    {
        $tester = $this->tester(new EnrichAiProvider(), existingHashes: []);
        $tester->execute([], ['decorated' => true]);

        $display = $tester->getDisplay();
        self::assertStringContainsString('Enriching 2 of 2 active product(s)', $display);
        self::assertStringContainsString('2/2', $display);
        self::assertStringContainsString('100%', $display);
        self::assertStringContainsString('2 enriched · 0 unchanged · 0 failed', $display);
    }

    #[Test]
    public function printsOneLinePerBatchInLogs(): void
    {
        $tester = $this->tester(new EnrichAiProvider(), existingHashes: []);
        $tester->execute(['--batch' => '1'], ['decorated' => false]);

        $display = $tester->getDisplay();
        self::assertStringContainsString('1/2 processed · 1 enriched · 0 unchanged · 0 failed', $display);
        self::assertStringContainsString('2/2 processed · 2 enriched · 0 unchanged · 0 failed', $display);
    }

    #[Test]
    public function limitIsExactEvenInsideOnePage(): void
    {
        $ai = new EnrichAiProvider();
        $tester = $this->tester($ai, existingHashes: [], count: 5);
        $tester->execute(['--limit' => '2', '--batch' => '50']);

        self::assertSame(2, $ai->tagCalls, '--limit 2 must not tag (and bill) a whole page of 50');
        self::assertSame(2, $this->upserts);
        self::assertStringContainsString('Enriching 2 of 5', $tester->getDisplay());
        self::assertStringContainsString('2 processed', $tester->getDisplay());
    }

    #[Test]
    public function aFailedTaggingCallIsNotSavedSoTheNextRunRetriesIt(): void
    {
        $ai = new EnrichAiProvider(onTag: static function (string $user): void {
            if (str_starts_with($user, 'Abaya 1')) {
                throw new AiException(AiException::KIND_TRANSPORT, 'OpenAI /chat/completions returned HTTP 500.');
            }
        });
        $tester = $this->tester($ai, existingHashes: []);
        $tester->execute([]);

        self::assertSame(Command::SUCCESS, $tester->getStatusCode());
        self::assertSame(1, $this->upserts, 'only the product that was tagged is saved');
        $display = $tester->getDisplay();
        self::assertStringContainsString('1 enriched', $display);
        self::assertStringContainsString('1 failed', $display);
        self::assertStringContainsString('Run the command again', $display);
    }

    #[Test]
    public function aFailedEmbeddingCallSavesNothingFromThatBatch(): void
    {
        $ai = new EnrichAiProvider(onEmbed: static function (): void {
            throw new AiException(AiException::KIND_NETWORK, 'timeout');
        });
        $tester = $this->tester($ai, existingHashes: []);
        $tester->execute([]);

        self::assertSame(0, $this->upserts);
        self::assertStringContainsString('2 failed', $tester->getDisplay());
    }

    #[Test]
    public function rateLimitsAreRetriedWithBackOff(): void
    {
        $ai = new EnrichAiProvider(onTag: static function (string $user, int $call): void {
            if ($call === 1) {
                throw new AiException(AiException::KIND_RATE_LIMITED, 'OpenAI rate limit reached.');
            }
        });
        $tester = $this->tester($ai, existingHashes: []);
        $tester->execute([]);

        self::assertSame([2], $this->sleeps, 'one back-off before the retry');
        self::assertSame(2, $this->upserts);
        self::assertStringContainsString('2 enriched', $tester->getDisplay());
    }

    #[Test]
    public function aRejectedApiKeyStopsTheRunAtOnce(): void
    {
        $ai = new EnrichAiProvider(onTag: static function (): void {
            throw new AiException(AiException::KIND_AUTH, 'OpenAI auth failed (HTTP 401).');
        });
        $tester = $this->tester($ai, existingHashes: [], count: 4);
        $tester->execute([]);

        self::assertSame(Command::FAILURE, $tester->getStatusCode());
        self::assertSame(1, $ai->tagCalls, 'no further calls after the key is rejected');
        self::assertSame(0, $this->upserts);
        self::assertStringContainsString('OPENAI_API_KEY', $tester->getDisplay());
    }

    #[Test]
    public function tooManyFailuresInARowStopTheRun(): void
    {
        $ai = new EnrichAiProvider(onTag: static function (): void {
            throw new AiException(AiException::KIND_TRANSPORT, 'OpenAI /chat/completions returned HTTP 503.');
        });
        $tester = $this->tester($ai, existingHashes: [], count: 8);
        $tester->execute([]);

        self::assertSame(Command::FAILURE, $tester->getStatusCode());
        self::assertSame(5, $ai->tagCalls);
        self::assertSame(0, $this->upserts);
        // The error block is word-wrapped; compare on collapsed whitespace.
        $display = (string) preg_replace('/\s+/', ' ', $tester->getDisplay());
        self::assertStringContainsString('5 products in a row failed', $display);
        self::assertStringContainsString('out of credit', $display);
    }

    // ===== harness =====

    /** @param array<int, string> $existingHashes */
    private function tester(AiProviderInterface $ai, array $existingHashes, int $count = 2): CommandTester
    {
        $this->upserts = 0;
        $this->sleeps = [];

        $all = [];
        for ($i = 1; $i <= $count; $i++) {
            $all[] = $this->product($i, "Abaya {$i}", "d{$i}");
        }

        $productRepo = $this->createMock(ProductRepository::class);
        $productRepo->method('findActivePaginated')->willReturnCallback(
            static fn (array $filters): array => [
                'items' => array_slice($all, (int) ($filters['offset'] ?? 0), (int) ($filters['limit'] ?? 50)),
                'total' => count($all),
            ],
        );

        $em = $this->createMock(EntityManagerInterface::class);
        $em->method('getRepository')->willReturn($productRepo);

        $connection = $this->createMock(Connection::class);
        $connection->method('fetchAllAssociative')->willReturn(
            array_map(static fn (int $id, string $h) => ['product_id' => $id, 'source_hash' => $h], array_keys($existingHashes), $existingHashes),
        );
        $connection->method('executeStatement')->willReturnCallback(function (): int {
            $this->upserts++;
            return 1;
        });

        $command = new BuildProductAiAttributesCommand(
            $ai,
            $em,
            new ProductAiAttributesStore($connection),
            new NullLogger(),
            function (int $seconds): void {
                $this->sleeps[] = $seconds;
            },
        );
        return new CommandTester($command);
    }

    private function product(int $id, string $name, string $desc): Product
    {
        $vendor = new Vendor("v-{$id}", "Store {$id}", "v{$id}@example.test");
        $vendor->approve();
        $p = new Product(vendor: $vendor, slug: "p-{$id}", name: $name);
        $p->setStatus('active');
        $p->setDescription($desc);
        $rp = new \ReflectionProperty($p, 'id');
        $rp->setAccessible(true);
        $rp->setValue($p, $id);
        return $p;
    }

    /** Mirrors the command's private sourceHash() for the skip test. */
    private function hash(string $model, string $name, string $desc): string
    {
        return hash('sha256', $model . '|' . trim($name . "\n" . $desc));
    }
}
