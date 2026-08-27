<?php

declare(strict_types=1);

namespace Bayti\Api\Console;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * noon:confirm-signature, offline brute-force of Noon's webhook
 * signing algorithm against captured (body + signature) pairs.
 *
 * INPUT: the JSON-lines files written by NoonWebhookCaptureRecorder
 * (apps/api/var/captures/noon-webhook-*.jsonl), enable capture with
 * NOON_WEBHOOK_CAPTURE=true for a short window, let real Noon traffic
 * accumulate, then run this command.
 *
 * For each captured pair it computes a set of candidate signatures
 * (HMAC-SHA256/512/1 × hex/base64) over the raw body using
 * NOON_WEBHOOK_SECRET, and reports which scheme, if any, reproduces
 * the captured signature across EVERY pair.
 *
 *   - A scheme that matches every pair → that's Noon's algorithm.
 *       · hmac-sha256-hex  → the existing HmacSha256SignatureVerifier
 *         is correct; set NOON_VERIFY_SIGNATURE=true.
 *       · anything else    → swap the verifier class in config/di.php
 *         (the NoonWebhookSignatureVerifier interface is unchanged).
 *   - No scheme matches all pairs → the scheme likely folds in a
 *       timestamp / header element (Stripe-style) or a different key
 *       encoding; capture more and inspect the raw headers.
 *
 * This command is READ-ONLY and touches no database or network.
 */
final class ConfirmNoonSignatureCommand extends Command
{
    /**
     * Candidate schemes: label => [hash algo, encoding].
     * Encoding 'hex' = lowercase hex digest; 'base64' = base64 of raw digest.
     */
    private const SCHEMES = [
        'hmac-sha256-hex'    => ['sha256', 'hex'],
        'hmac-sha256-base64' => ['sha256', 'base64'],
        'hmac-sha512-hex'    => ['sha512', 'hex'],
        'hmac-sha512-base64' => ['sha512', 'base64'],
        'hmac-sha1-hex'      => ['sha1', 'hex'],
        'hmac-sha1-base64'   => ['sha1', 'base64'],
    ];

    public function __construct(
        private readonly string $captureDir,
        private readonly ?string $defaultSecret = null,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->setName('noon:confirm-signature')
            ->setDescription('Confirm Noon webhook signing algorithm from captured (body+signature) pairs')
            ->addOption(
                'captures',
                null,
                InputOption::VALUE_REQUIRED,
                'Directory of *.jsonl captures, or a single .jsonl file',
                $this->captureDir,
            )
            ->addOption(
                'secret',
                null,
                InputOption::VALUE_REQUIRED,
                'Shared secret (defaults to NOON_WEBHOOK_SECRET from the environment)',
            );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $secretOpt = $input->getOption('secret');
        $secret = is_string($secretOpt) && $secretOpt !== '' ? $secretOpt : $this->defaultSecret;
        if ($secret === null || $secret === '') {
            $output->writeln('<error>No secret provided. Pass --secret or set NOON_WEBHOOK_SECRET.</error>');
            return Command::FAILURE;
        }

        $capturesOpt = $input->getOption('captures');
        $path = is_string($capturesOpt) && $capturesOpt !== '' ? $capturesOpt : $this->captureDir;

        $files = $this->resolveFiles($path);
        if ($files === []) {
            $output->writeln(sprintf('<error>No capture files found at %s</error>', $path));
            $output->writeln('Enable NOON_WEBHOOK_CAPTURE=true, let real webhooks arrive, then re-run.');
            return Command::FAILURE;
        }

        /** @var list<array{body: string, signature: string}> $pairs */
        $pairs = [];
        $skipped = 0;
        foreach ($files as $file) {
            foreach ($this->readPairs($file) as $pair) {
                if ($pair === null) {
                    $skipped++;
                    continue;
                }
                $pairs[] = $pair;
            }
        }

        $output->writeln(sprintf(
            'Loaded %d signed pair(s) from %d file(s)%s.',
            count($pairs),
            count($files),
            $skipped > 0 ? sprintf(' (%d record(s) skipped — no signature)', $skipped) : '',
        ));

        if ($pairs === []) {
            $output->writeln('<error>No usable pairs (every record was missing a signature header).</error>');
            return Command::FAILURE;
        }

        // Tally matches per scheme across every pair.
        $total = count($pairs);
        $matchCounts = array_fill_keys(array_keys(self::SCHEMES), 0);
        foreach ($pairs as $pair) {
            foreach (self::SCHEMES as $label => [$algo, $encoding]) {
                if ($this->schemeMatches($algo, $encoding, $pair['body'], $secret, $pair['signature'])) {
                    $matchCounts[$label]++;
                }
            }
        }

        $output->writeln('');
        $output->writeln('Scheme                  Matches');
        $output->writeln('----------------------  -------');
        foreach ($matchCounts as $label => $count) {
            $output->writeln(sprintf('%-22s  %d/%d', $label, $count, $total));
        }
        $output->writeln('');

        $confirmed = array_keys(array_filter(
            $matchCounts,
            static fn (int $count): bool => $count === $total,
        ));

        if ($confirmed === []) {
            $output->writeln('<comment>No candidate scheme matched ALL pairs.</comment>');
            $output->writeln('Noon may sign a timestamp+body concatenation, use a different key');
            $output->writeln('encoding, or send the signature in a header we are not capturing.');
            $output->writeln('Inspect the raw signature_header values and capture more samples.');
            return Command::FAILURE;
        }

        $winner = $confirmed[0];
        $output->writeln(sprintf('<info>Confirmed: Noon uses %s (matched all %d pairs).</info>', $winner, $total));
        if ($winner === 'hmac-sha256-hex') {
            $output->writeln('HmacSha256SignatureVerifier already implements this.');
            $output->writeln('Next step: set NOON_VERIFY_SIGNATURE=true (with NOON_WEBHOOK_SECRET).');
        } else {
            $output->writeln(sprintf(
                'Next step: implement a %s verifier and bind it in config/di.php',
                $winner,
            ));
            $output->writeln('(the NoonWebhookSignatureVerifier interface is unchanged).');
        }

        return Command::SUCCESS;
    }

    /**
     * Resolve --captures to a concrete list of .jsonl files. Accepts a
     * single file or a directory (non-recursive glob of *.jsonl).
     *
     * @return list<string>
     */
    private function resolveFiles(string $path): array
    {
        if (is_file($path)) {
            return [$path];
        }
        if (is_dir($path)) {
            $glob = glob(rtrim($path, '/\\') . '/*.jsonl');
            return $glob === false ? [] : array_values($glob);
        }
        return [];
    }

    /**
     * Yield {body, signature} pairs from a .jsonl file. Records without
     * a usable raw_body + signature_header yield null (counted as skipped).
     *
     * @return \Generator<int, array{body: string, signature: string}|null>
     */
    private function readPairs(string $file): \Generator
    {
        $handle = @fopen($file, 'rb');
        if ($handle === false) {
            return;
        }
        try {
            while (($line = fgets($handle)) !== false) {
                $line = trim($line);
                if ($line === '') {
                    continue;
                }
                $row = json_decode($line, true);
                if (!is_array($row)) {
                    yield null;
                    continue;
                }
                $body = $row['raw_body'] ?? null;
                $sig = $row['signature_header'] ?? null;
                if (!is_string($body) || !is_string($sig) || $sig === '') {
                    yield null;
                    continue;
                }
                yield ['body' => $body, 'signature' => $sig];
            }
        } finally {
            fclose($handle);
        }
    }

    /**
     * Does the given scheme reproduce $captured for ($body, $secret)?
     * Mirrors HmacSha256SignatureVerifier's normalisation: strip a
     * leading algo= prefix and compare constant-time.
     */
    private function schemeMatches(
        string $algo,
        string $encoding,
        string $body,
        string $secret,
        string $captured,
    ): bool {
        $normalised = $this->stripPrefix(trim($captured));

        if ($encoding === 'hex') {
            $expected = hash_hmac($algo, $body, $secret);
            return hash_equals(strtolower($expected), strtolower($normalised));
        }

        // base64 of the raw binary digest (case-sensitive).
        $expected = base64_encode(hash_hmac($algo, $body, $secret, true));
        return hash_equals($expected, $normalised);
    }

    /**
     * Strip a leading "<algo>=" prefix (GitHub/Stripe style) if present.
     */
    private function stripPrefix(string $signature): string
    {
        foreach (['sha256=', 'sha512=', 'sha1='] as $prefix) {
            if (stripos($signature, $prefix) === 0) {
                return substr($signature, strlen($prefix));
            }
        }
        return $signature;
    }
}
