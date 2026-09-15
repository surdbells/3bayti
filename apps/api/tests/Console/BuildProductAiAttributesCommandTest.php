<?php

declare(strict_types=1);

namespace Bayti\Api\Tests\Console;

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
use Symfony\Component\Console\Tester\CommandTester;

/** Enabled AI stub returning fixed tags + a fixed embedding. */
final class EnrichAiProvider implements AiProviderInterface
{
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
        return ['occasions' => ['eid'], 'colours' => ['black'], 'styles' => ['elegant'], 'search_text' => 'elegant black abaya'];
    }

    public function embed(array $texts): array
    {
        return array_map(static fn (): array => [0.1, 0.2, 0.3], $texts);
    }
}

#[CoversClass(BuildProductAiAttributesCommand::class)]
final class BuildProductAiAttributesCommandTest extends TestCase
{
    /** @var int upsert (executeStatement) calls counted on the mocked connection */
    private int $upserts = 0;

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
        $tester = $this->tester(new EnrichAiProvider(), existingHashes: $existing);
        $tester->execute([]);

        self::assertStringContainsString('2 unchanged', $tester->getDisplay());
        self::assertSame(0, $this->upserts);
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

    // ===== harness =====

    /** @param array<int, string> $existingHashes */
    private function tester(AiProviderInterface $ai, array $existingHashes): CommandTester
    {
        $this->upserts = 0;

        $productRepo = $this->createMock(ProductRepository::class);
        $productRepo->method('findActivePaginated')->willReturnCallback(
            fn (array $filters) => ($filters['offset'] ?? 0) === 0
                ? ['items' => [$this->product(1, 'Abaya 1', 'd1'), $this->product(2, 'Abaya 2', 'd2')], 'total' => 2]
                : ['items' => [], 'total' => 2],
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

        $command = new BuildProductAiAttributesCommand($ai, $em, new ProductAiAttributesStore($connection), new NullLogger());
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
