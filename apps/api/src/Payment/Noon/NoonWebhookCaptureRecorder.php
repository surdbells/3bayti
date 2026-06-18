<?php

declare(strict_types=1);

namespace Bayti\Api\Payment\Noon;

use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * Gated, time-boxed capture of raw Noon webhook (body + signature)
 * pairs for empirical signature-algorithm confirmation (M3.1.7).
 *
 * WHY THIS EXISTS
 * ---------------
 * Confirming Noon's webhook signing algorithm requires the EXACT raw
 * request bytes Noon signed, plus the raw signature header. Neither
 * is recoverable from what we persist today:
 *
 *   - payment_webhook_events.payload stores the RE-PARSED JSON, whose
 *     key order / whitespace can differ from the bytes Noon signed, so
 *     hash_hmac() over it won't reproduce the signature.
 *   - LoggingOnlyVerifier logs only SHA-256 hashes of the body and the
 *     signature — one-way, so they can't be fed back into hash_hmac().
 *
 * This recorder persists the raw pair verbatim so the offline
 * `bin/console noon:confirm-signature` command can brute-force
 * candidate schemes (HMAC-SHA256/512, hex/base64, prefixed, ...)
 * against real traffic and tell the operator which one Noon uses.
 *
 * SAFETY
 * ------
 *   - OFF by default (NOON_WEBHOOK_CAPTURE unset / false). Intended to
 *     run for a short rollout window, then be switched back off.
 *   - NEVER writes the shared secret — only the body and the signature.
 *     The signature is a keyed MAC; revealing it does not reveal the
 *     secret. The webhook body for our integration carries order ref +
 *     amount, not card data (Noon's hosted page owns the PAN).
 *   - Captures land under apps/api/var/captures (gitignored via /var/,
 *     not web-served). Files written 0640, dir 0750 — never world-readable.
 *   - Capture failures are swallowed (logged) — recording must NEVER
 *     break webhook receipt, which is the load-bearing payment path.
 */
final class NoonWebhookCaptureRecorder
{
    public function __construct(
        private readonly bool $enabled,
        private readonly string $captureDir,
        private readonly LoggerInterface $logger = new NullLogger(),
    ) {
    }

    public function isEnabled(): bool
    {
        return $this->enabled;
    }

    /**
     * Append one capture record as a JSON line to
     * {captureDir}/noon-webhook-{Y-m-d}.jsonl. No-op when disabled.
     */
    public function record(string $rawBody, ?string $signatureHeader, ?string $contentType): void
    {
        if (!$this->enabled) {
            return;
        }

        try {
            if (!is_dir($this->captureDir)) {
                // Owner+group only; never world-readable. @-suppressed
                // because a concurrent request may have just created it.
                @mkdir($this->captureDir, 0750, true);
            }

            $now = new \DateTimeImmutable();
            $file = $this->captureDir . '/noon-webhook-' . $now->format('Y-m-d') . '.jsonl';

            $line = json_encode([
                'received_at' => $now->format(\DateTimeInterface::ATOM),
                'content_type' => $contentType,
                'signature_header' => $signatureHeader,
                'body_length' => strlen($rawBody),
                // Raw bytes exactly as received — this is the whole point.
                'raw_body' => $rawBody,
            ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

            if ($line === false) {
                $this->logger->warning('noon.webhook.capture: json_encode failed', [
                    'json_error' => json_last_error_msg(),
                ]);
                return;
            }

            $written = @file_put_contents($file, $line . "\n", FILE_APPEND | LOCK_EX);
            if ($written === false) {
                $this->logger->warning('noon.webhook.capture: write failed', [
                    'file' => $file,
                ]);
                return;
            }

            // Tighten perms on first create (mkdir/umask may leave it
            // group/other-readable). Best-effort; ignore failures.
            @chmod($file, 0640);
        } catch (\Throwable $e) {
            $this->logger->warning('noon.webhook.capture: unexpected error', [
                'error' => $e->getMessage(),
            ]);
        }
    }
}
