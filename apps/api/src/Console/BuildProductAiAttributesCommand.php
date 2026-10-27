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
use Symfony\Component\Console\Helper\ProgressBar;
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
 *
 * Progress: in a terminal a live bar (per product, elapsed / time left,
 * enriched / unchanged / failed counts); in cron logs or piped output one
 * line per batch. --limit is exact (the last page is trimmed).
 *
 * Failures are never persisted: a product whose tagging or embedding call
 * failed is skipped (not stored with empty tags + its source_hash), so the
 * next run retries it. OpenAI 429s are retried with back-off; an auth error
 * aborts at once, and MAX_CONSECUTIVE_FAILURES failed products in a row abort
 * the run (out of credit / outage) instead of burning through the catalogue.
 */
#[AsCommand(
    name: 'ai:build-product-attributes',
    description: 'Enrich active products with AI tags + embeddings for the Ain concierge.',
)]
final class BuildProductAiAttributesCommand extends Command
{
    private const DEFAULT_BATCH = 50;

    /** Seconds to wait before each retry of a rate-limited (HTTP 429) call. */
    private const RATE_LIMIT_BACKOFF = [2, 5, 15];

    /** Abort after this many products in a row fail (credit, outage, …). */
    private const MAX_CONSECUTIVE_FAILURES = 5;

    /** @var \Closure(int): void */
    private readonly \Closure $sleep;

    private ?ProgressBar $bar = null;
    private ?SymfonyStyle $io = null;

    private int $processed = 0;
    private int $enriched = 0;
    private int $skipped = 0;
    private int $failed = 0;
    private int $consecutiveFailures = 0;
    private ?string $lastError = null;

    /**
     * @param (\Closure(int): void)|null $sleep back-off sleeper (seconds);
     *        injectable so tests don't wait
     */
    public function __construct(
        private readonly AiProviderInterface $ai,
        private readonly EntityManagerInterface $em,
        private readonly ProductAiAttributesStore $store,
        private readonly LoggerInterface $logger,
        ?\Closure $sleep = null,
    ) {
        parent::__construct();
        $this->sleep = $sleep ?? static function (int $seconds): void {
            sleep($seconds);
        };
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
        $startedAt = microtime(true);

        $this->io = $io;
        $this->processed = $this->enriched = $this->skipped = $this->failed = $this->consecutiveFailures = 0;
        $this->lastError = null;

        /** @var ProductRepository $products */
        $products = $this->em->getRepository(Product::class);

        $first = $products->findActivePaginated(['limit' => $batch, 'offset' => 0]);
        $total = $first['total'];
        $target = $limit > 0 ? min($limit, $total) : $total;

        $io->text(sprintf(
            'Enriching %d of %d active product(s) · batch %d · model %s%s',
            $target,
            $total,
            $batch,
            $model,
            $dryRun ? ' · DRY RUN (nothing is saved, but OpenAI is still called and billed)' : '',
        ));

        // Live bar in a terminal; one line per batch in cron logs / pipes.
        if ($output->isDecorated() && !$output->isQuiet() && $target > 0) {
            $this->bar = $io->createProgressBar($target);
            $this->bar->setFormat(
                " %current%/%max% [%bar%] %percent:3s%%  %elapsed:6s% elapsed · ~%remaining:-6s% left\n %message%",
            );
            $this->bar->setMessage($this->countsLine());
            $this->bar->start();
        }

        $offset = 0;
        $page = $first['items'];
        $aborted = null;

        while ($page !== [] && ($limit === 0 || $this->processed < $limit)) {
            $offset += count($page);
            if ($limit > 0) {
                // Exact --limit: never tag more products than asked for.
                $page = array_slice($page, 0, $limit - $this->processed);
            }

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
                    $this->skipped++;
                    $this->processed++;
                    $this->tick();
                    continue;
                }
                $toEnrich[$id] = $p;
            }

            try {
                $this->enrichBatch($toEnrich, $hashOf, $model, $dryRun);
            } catch (AbortEnrichment $e) {
                $aborted = $e->getMessage();
                break;
            }

            if ($this->bar === null) {
                $io->writeln(sprintf(
                    '[%s] %d/%d processed · %s',
                    self::duration(microtime(true) - $startedAt),
                    $this->processed,
                    $target,
                    $this->countsLine(),
                ));
            }

            if ($limit > 0 && $this->processed >= $limit) {
                break;
            }
            $page = $products->findActivePaginated(['limit' => $batch, 'offset' => $offset])['items'];
        }

        if ($this->bar !== null) {
            if ($aborted === null) {
                $this->bar->finish();
            } else {
                $this->bar->display();
            }
            $io->newLine(2);
            $this->bar = null;
        }
        $this->em->clear();

        $summary = sprintf(
            '%s: %d processed, %d enriched, %d unchanged, %d failed in %s.',
            $dryRun ? 'Dry run' : 'Done',
            $this->processed,
            $this->enriched,
            $this->skipped,
            $this->failed,
            self::duration(microtime(true) - $startedAt),
        );

        if ($aborted !== null) {
            $io->error([$aborted, str_replace(['Done: ', 'Dry run: '], 'Stopped after ', $summary)]);
            return Command::FAILURE;
        }
        if ($this->failed > 0) {
            $io->warning([
                $summary,
                "{$this->failed} product(s) were not saved because the AI call failed"
                    . ($this->lastError !== null ? " (last error: {$this->lastError})" : '')
                    . '. Run the command again: only those (and any new or edited products) are retried.',
            ]);
            return Command::SUCCESS;
        }
        $io->success($summary);
        return Command::SUCCESS;
    }

    /** "12 enriched · 3 unchanged · 0 failed" */
    private function countsLine(): string
    {
        return sprintf('%d enriched · %d unchanged · %d failed', $this->enriched, $this->skipped, $this->failed);
    }

    /** Advance the live bar (no-op in line mode) and refresh its counts. */
    private function tick(): void
    {
        if ($this->bar !== null) {
            $this->bar->setMessage($this->countsLine());
            $this->bar->advance();
        }
    }

    /** Print a warning without tearing the live bar. */
    private function note(string $message): void
    {
        if ($this->bar !== null) {
            $this->bar->clear();
            $this->io?->warning($message);
            $this->bar->display();
            return;
        }
        $this->io?->warning($message);
    }

    private static function duration(float $seconds): string
    {
        $s = (int) round($seconds);
        return $s >= 3600
            ? sprintf('%dh%02dm%02ds', intdiv($s, 3600), intdiv($s % 3600, 60), $s % 60)
            : sprintf('%dm%02ds', intdiv($s, 60), $s % 60);
    }

    /**
     * Tag each product (one structured call each), embed the page in one
     * call, then persist. A product whose tagging or embedding failed is NOT
     * persisted (its source_hash isn't stored), so the next run retries it.
     *
     * @param array<int, Product> $toEnrich
     * @param array<int, string> $hashOf
     * @throws AbortEnrichment on an auth error or too many failures in a row
     */
    private function enrichBatch(array $toEnrich, array $hashOf, string $model, bool $dryRun): void
    {
        if ($toEnrich === []) {
            return;
        }

        // 1) Per-product tags (one structured call each), the slow part, so
        //    the bar advances per product here.
        $tags = [];
        $embedInputs = [];
        foreach ($toEnrich as $id => $p) {
            $derived = $this->deriveTags($p);
            $this->processed++;
            if ($derived === null) {
                $this->recordFailure($id, 'tagging');
                $this->tick();
                continue;
            }
            $tags[$id] = $derived;
            $embedInputs[$id] = $derived['search_text'] !== '' ? $derived['search_text'] : $this->plainText($p);
            $this->tick();
        }
        if ($embedInputs === []) {
            return;
        }

        // 2) One batched embeddings call for the products that were tagged.
        try {
            $vecList = $this->withRateLimitRetry(fn (): array => $this->ai->embed(array_values($embedInputs)));
        } catch (AiException $e) {
            $this->abortOnAuth($e);
            $this->lastError = $e->getMessage();
            $this->logger->warning('ai.enrich_embed_failed', ['error' => $e->getMessage()]);
            foreach (array_keys($embedInputs) as $id) {
                $this->recordFailure($id, 'embedding');
            }
            $this->refresh();
            return;
        }
        $vectors = [];
        foreach (array_keys($embedInputs) as $i => $id) {
            $vectors[$id] = $vecList[$i] ?? [];
        }

        // 3) Persist (skipped in a dry run).
        foreach ($embedInputs as $id => $search) {
            if (($vectors[$id] ?? []) === []) {
                $this->lastError = 'empty embedding returned';
                $this->recordFailure($id, 'embedding');
                continue;
            }
            if ($dryRun) {
                $this->recordSuccess();
                continue;
            }
            try {
                $this->store->upsert(
                    $id,
                    $tags[$id]['occasions'],
                    $tags[$id]['colours'],
                    $tags[$id]['styles'],
                    $search,
                    $vectors[$id],
                    $model,
                    $hashOf[$id],
                );
                $this->recordSuccess();
            } catch (\Throwable $e) {
                $this->failed++;
                $this->lastError = $e->getMessage();
                $this->note("Failed to save product {$id}: {$e->getMessage()}");
                $this->logger->error('ai.enrich_upsert_failed', ['product_id' => $id, 'error' => $e->getMessage()]);
            }
        }
        $this->refresh();
    }

    /**
     * @return array{occasions: list<string>, colours: list<string>, styles: list<string>, search_text: string}|null
     *         null when the AI call failed (the product is retried next run)
     * @throws AbortEnrichment on an auth error
     */
    private function deriveTags(Product $p): ?array
    {
        try {
            $raw = $this->withRateLimitRetry(
                fn (): array => $this->ai->completeJson(self::tagSystemPrompt(), $this->plainText($p), self::tagSchema(), 'product_tags'),
            );
        } catch (AiException $e) {
            $this->abortOnAuth($e);
            $this->lastError = $e->getMessage();
            $this->logger->warning('ai.enrich_tags_failed', ['product_id' => $p->getId(), 'error' => $e->getMessage()]);
            return null;
        }

        return [
            'occasions' => self::strList($raw['occasions'] ?? []),
            'colours' => self::strList($raw['colours'] ?? []),
            'styles' => self::strList($raw['styles'] ?? []),
            'search_text' => is_string($raw['search_text'] ?? null) ? trim($raw['search_text']) : '',
        ];
    }

    /**
     * Run an AI call, retrying HTTP 429 (rate limited) with back-off.
     *
     * @template T
     * @param callable(): T $call
     * @return T
     */
    private function withRateLimitRetry(callable $call): mixed
    {
        foreach (self::RATE_LIMIT_BACKOFF as $seconds) {
            try {
                return $call();
            } catch (AiException $e) {
                if ($e->kind !== AiException::KIND_RATE_LIMITED) {
                    throw $e;
                }
                ($this->sleep)($seconds);
            }
        }
        return $call();
    }

    /** A bad or revoked key fails every call: stop now with a clear message. */
    private function abortOnAuth(AiException $e): void
    {
        if ($e->kind === AiException::KIND_AUTH) {
            throw new AbortEnrichment(
                'OpenAI rejected the API key (' . $e->getMessage() . '). Check OPENAI_API_KEY in apps/api/.env, then run the command again.',
            );
        }
    }

    private function recordSuccess(): void
    {
        $this->enriched++;
        $this->consecutiveFailures = 0;
    }

    /** @throws AbortEnrichment after MAX_CONSECUTIVE_FAILURES in a row */
    private function recordFailure(int $productId, string $step): void
    {
        $this->failed++;
        $this->consecutiveFailures++;
        $this->logger->warning('ai.enrich_product_failed', ['product_id' => $productId, 'step' => $step, 'error' => $this->lastError]);
        if ($this->consecutiveFailures >= self::MAX_CONSECUTIVE_FAILURES) {
            throw new AbortEnrichment(sprintf(
                '%d products in a row failed (last error: %s). OpenAI may be out of credit, rate limiting hard or down: check your OpenAI account (billing and usage), then run the command again. It resumes where it stopped.',
                $this->consecutiveFailures,
                $this->lastError ?? 'unknown',
            ));
        }
    }

    /** Refresh the bar's counts without advancing it. */
    private function refresh(): void
    {
        if ($this->bar !== null) {
            $this->bar->setMessage($this->countsLine());
            $this->bar->display();
        }
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
