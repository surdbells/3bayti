<?php

declare(strict_types=1);

namespace Bayti\Api\Console;

use Bayti\Api\Ai\AiException;
use Bayti\Api\Ai\AiProviderInterface;
use Bayti\Api\Ai\Enrichment\ProductAiAttributesStore;
use Bayti\Api\Domain\Catalog\Product;
use Bayti\Api\Domain\Catalog\ProductRepository;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * ai:build-product-attributes — enrich active products with AI retrieval
 * metadata (occasion / colour / style tags + a semantic embedding) into
 * product_ai_attributes, powering Ain's semantic retrieval.
 *
 * Incremental: a source_hash (product text + embedding model) skips unchanged
 * products, so nightly runs only touch new/edited items. No-op when AI is
 * disabled. Run via aaPanel cron like build:recommendations.
 */
#[AsCommand(
    name: 'ai:build-product-attributes',
    description: 'Enrich active products with AI tags + embeddings for the Ain concierge.',
)]
final class BuildProductAiAttributesCommand extends Command
{
    private const DEFAULT_BATCH = 50;

    public function __construct(
        private readonly AiProviderInterface $ai,
        private readonly EntityManagerInterface $em,
        private readonly ProductAiAttributesStore $store,
        private readonly LoggerInterface $logger,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('limit', null, InputOption::VALUE_REQUIRED, 'Max products to process (0 = all).', '0')
            ->addOption('batch', null, InputOption::VALUE_REQUIRED, 'Page size.', (string) self::DEFAULT_BATCH)
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Compute but do not write.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        if (!$this->ai->isEnabled()) {
            $io->warning('AI is disabled (AI_ENABLED). Nothing to enrich.');
            return Command::SUCCESS;
        }

        $limit = max(0, (int) $input->getOption('limit'));
        $batch = max(1, (int) $input->getOption('batch'));
        $dryRun = (bool) $input->getOption('dry-run');
        $model = $this->ai->embedModel() ?? 'unknown';

        /** @var ProductRepository $products */
        $products = $this->em->getRepository(Product::class);

        $processed = 0;
        $enriched = 0;
        $skipped = 0;
        $offset = 0;

        while (true) {
            $page = $products->findActivePaginated(['limit' => $batch, 'offset' => $offset])['items'];
            if ($page === []) {
                break;
            }
            $offset += count($page);

            // Work out which products in this page actually need (re-)enriching.
            /** @var array<int, Product> $byId */
            $byId = [];
            $hashOf = [];
            foreach ($page as $p) {
                $id = $p->getId();
                if ($id === null) {
                    continue;
                }
                $byId[$id] = $p;
                $hashOf[$id] = $this->sourceHash($p, $model);
            }
            $existing = $this->store->sourceHashesFor(array_keys($byId));

            $toEnrich = [];
            foreach ($byId as $id => $p) {
                if (($existing[$id] ?? null) === $hashOf[$id]) {
                    $skipped++;
                    continue;
                }
                $toEnrich[$id] = $p;
            }

            $this->enrichBatch($toEnrich, $hashOf, $model, $dryRun, $io, $enriched);

            $processed += count($byId);
            if ($limit > 0 && $processed >= $limit) {
                break;
            }
        }

        $this->em->clear();
        $io->success(sprintf(
            '%s: %d processed, %d enriched, %d unchanged.',
            $dryRun ? 'Dry run' : 'Done',
            $processed,
            $enriched,
            $skipped,
        ));
        return Command::SUCCESS;
    }

    /**
     * @param array<int, Product> $toEnrich
     * @param array<int, string> $hashOf
     */
    private function enrichBatch(array $toEnrich, array $hashOf, string $model, bool $dryRun, SymfonyStyle $io, int &$enriched): void
    {
        if ($toEnrich === []) {
            return;
        }

        // 1) Per-product tags (one structured call each).
        $tags = [];
        $embedInputs = [];
        foreach ($toEnrich as $id => $p) {
            $tags[$id] = $this->deriveTags($p);
            $search = $tags[$id]['search_text'] !== '' ? $tags[$id]['search_text'] : $this->plainText($p);
            $embedInputs[$id] = $search;
        }

        // 2) One batched embeddings call for the whole page.
        $vectors = [];
        try {
            $vecList = $this->ai->embed(array_values($embedInputs));
            $ids = array_keys($embedInputs);
            foreach ($ids as $i => $id) {
                $vectors[$id] = $vecList[$i] ?? [];
            }
        } catch (AiException $e) {
            $this->logger->warning('ai.enrich_embed_failed', ['error' => $e->getMessage()]);
        }

        // 3) Persist.
        foreach ($toEnrich as $id => $p) {
            if ($dryRun) {
                $enriched++;
                continue;
            }
            try {
                $this->store->upsert(
                    $id,
                    $tags[$id]['occasions'],
                    $tags[$id]['colours'],
                    $tags[$id]['styles'],
                    $embedInputs[$id],
                    $vectors[$id] ?? [],
                    $model,
                    $hashOf[$id],
                );
                $enriched++;
            } catch (\Throwable $e) {
                $io->warning("Failed to enrich product {$id}: {$e->getMessage()}");
                $this->logger->error('ai.enrich_upsert_failed', ['product_id' => $id, 'error' => $e->getMessage()]);
            }
        }
    }

    /**
     * @return array{occasions: list<string>, colours: list<string>, styles: list<string>, search_text: string}
     */
    private function deriveTags(Product $p): array
    {
        $default = ['occasions' => [], 'colours' => [], 'styles' => [], 'search_text' => ''];
        try {
            $raw = $this->ai->completeJson(self::tagSystemPrompt(), $this->plainText($p), self::tagSchema(), 'product_tags');
        } catch (AiException) {
            return $default;
        }

        return [
            'occasions' => self::strList($raw['occasions'] ?? []),
            'colours' => self::strList($raw['colours'] ?? []),
            'styles' => self::strList($raw['styles'] ?? []),
            'search_text' => is_string($raw['search_text'] ?? null) ? trim($raw['search_text']) : '',
        ];
    }

    private function plainText(Product $p): string
    {
        $category = $p->getCategory()?->getName();
        $desc = mb_substr($p->getDescription() ?? '', 0, 800);
        return trim($p->getName() . "\n" . ($category !== null ? $category . "\n" : '') . $desc);
    }

    private function sourceHash(Product $p, string $model): string
    {
        return hash('sha256', $model . '|' . $this->plainText($p));
    }

    private static function tagSystemPrompt(): string
    {
        return <<<PROMPT
            You tag modest-fashion products for a UAE marketplace (abayas, mukhawars,
            kaftans, hijabs, bags, shoes, accessories). From the product text, return
            canonical ENGLISH tags. occasions: events it suits (wedding, eid, ramadan,
            graduation, everyday …). colours: colour terms present. styles: descriptive
            style words (elegant, traditional, minimal, embellished …). search_text: one
            concise English phrase (<= 20 words) capturing what it is and its vibe, for
            semantic search. Leave arrays empty if unsure; never invent facts.
            PROMPT;
    }

    /**
     * @return array<string, mixed>
     */
    private static function tagSchema(): array
    {
        $arr = ['type' => 'array', 'items' => ['type' => 'string']];
        return [
            'type' => 'object',
            'properties' => [
                'occasions' => $arr,
                'colours' => $arr,
                'styles' => $arr,
                'search_text' => ['type' => 'string'],
            ],
            'required' => ['search_text'],
            'additionalProperties' => false,
        ];
    }

    /**
     * @param mixed $v
     * @return list<string>
     */
    private static function strList(mixed $v): array
    {
        if (!is_array($v)) {
            return [];
        }
        $out = [];
        foreach ($v as $s) {
            if (is_string($s) && trim($s) !== '') {
                $out[] = trim($s);
            }
        }
        return array_values(array_unique($out));
    }
}
