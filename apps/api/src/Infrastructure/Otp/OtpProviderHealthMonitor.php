<?php

declare(strict_types=1);

namespace Bayti\Api\Infrastructure\Otp;

use Bayti\Api\Infrastructure\Cache\InMemoryKeyValueStore;
use Bayti\Api\Infrastructure\Cache\KeyValueStore;
use Bayti\Api\Notification\MailerException;
use Bayti\Api\Notification\MailerInterface;
use Bayti\Api\Notification\RenderedEmail;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * "Alert on provider problems": tells the admin team when the SMS provider
 * refuses to send OTPs for an ACCOUNT reason (out of credit, pricing / channel
 * not set up, credentials rejected, or no provider at all), and again once
 * codes work again.
 *
 * Why: the MessageCentral account ran out of credit twice in 2026 (Sept 18-20,
 * ~520 failed sends; Oct 14-18, ~650 failed sends and 120+ customers blocked),
 * both noticed only by chance because they looked like any other 502.
 *
 * Alert scopes
 * ------------
 * State, cooldowns and recovery are tracked per SCOPE + reason:
 *   '<provider>'         out of credit / credentials rejected / pricing with an
 *                        unknown destination — account-wide problems.
 *   '<provider>@<dial>'  pricing_not_configured for ONE destination country.
 *                        MessageCentral prices SMS per country: in production
 *                        "Pricing not found" was one customer's country while
 *                        every other send worked, so a success to ANOTHER
 *                        country must not "resolve" it (that made it flap:
 *                        alert, resolved, alert, ... on each retry).
 *   'none'               no provider at all.
 *
 * On an ACCOUNT failure (see OtpProviderFailureClassifier; everything else is
 * ignored here — it already shows in Sentry as an ordinary error):
 *   1. State: an "active alert" marker (start time + failed-request counter,
 *      TTL 7 days, refreshed while failures continue).
 *   2. Sentry: ONE grouped issue per provider + reason (stable fingerprint),
 *      at most one event per minute per scope + reason (+ whether customers
 *      were blocked), carrying the running failure count. Each event is a
 *      synchronous HTTP call, so it is throttled rather than sent per failure.
 *   3. Email: the admin team (OTP_ALERT_EMAILS, else ADMIN_NOTIFICATION_EMAILS)
 *      at most once per cooldown (OTP_ALERT_COOLDOWN_SECONDS, default 1 h) per
 *      scope + reason — claimed with an atomic SET NX EX on the shared
 *      KeyValueStore, so N FPM workers send ONE email, not N. "Customers
 *      blocked" and "a backup covered it" have separate cooldowns, so if the
 *      backup then fails too the owner is told straight away; a "backup is
 *      covering" email is never sent after a "customers blocked" one in the
 *      same window.
 *
 * On a SUCCESS: one cheap KeyValueStore GET per involved scope (the provider,
 * the provider + destination, and 'none') — nothing more unless an alert is
 * active. When one is, ONE "SMS codes are working again" email is sent (itself
 * rate-limited by the same cooldown) and the state is cleared, including the
 * alert cooldowns, so a NEW breakage after a recovery is reported immediately.
 * A failure racing a recovery always leaves its state reachable (the flag is
 * dropped BEFORE the state and re-raised if anything is still active), so an
 * alert email is always followed by a recovery email eventually.
 *
 * Cost on the OTP path (it runs inside the customer's request)
 * ---------------------------------------------------------------
 *   - Every side effect is wrapped; failures are logged at warning and
 *     swallowed. recordFailure()/recordSuccess() never throw.
 *   - Email is sent synchronously (MailerInterface is synchronous) but ONLY by
 *     the request that newly claims the cooldown — at most one request per
 *     scope + reason per window pays for it. Each send is bounded by the
 *     mailer's own HTTP timeout (ZeptoMail: 15 s); after a NETWORK failure or
 *     any failure that took >= 3 s the remaining recipients are skipped, and no
 *     new recipient is started once 15 s have gone. Worst case for that one
 *     request is therefore roughly one mailer timeout plus one send.
 *   - KeyValueStore (Redis) down: Sentry is still reported (unthrottled). The
 *     email cooldown falls back to (a) this process's memory and (b) a marker
 *     file in a local directory (var/cache in prod), so without Redis you get at
 *     most one email per cooldown per server (best effort: two workers racing on
 *     the marker may both send), or — if that directory isn't writable — at most
 *     one per process/request. After the first store error, this instance stops
 *     calling the store (one connect timeout per request, not one per call).
 *     Recovery emails need the shared store and are skipped while it's down.
 *   - The same fallback is used from the start when the store is the
 *     per-process InMemoryKeyValueStore (REDIS_DSN unset): under PHP-FPM it is
 *     fresh per request, so "set if absent" would always win and every failed
 *     request would email. Logged once per instance as otp.alert.store_not_shared.
 *
 * Privacy: the monitor is never given a phone number, OTP code or user id —
 * only the destination's dial code; the provider's reply is redacted
 * (OtpProviderAlertEmailRenderer::redactReply) before it reaches the email or
 * Sentry; no credentials are ever included.
 */
final class OtpProviderHealthMonitor implements OtpProviderHealthObserver
{
    private const KEY_PREFIX = 'otp:alert:';

    /**
     * How long an un-recovered alert is remembered (refreshed on each failure).
     * Generous on purpose: the Oct 2026 outage ran 4 days, and a quiet spell
     * (no sign-ups over a weekend) must not silently drop the recovery email.
     */
    private const ACTIVE_TTL_SECONDS = 604800; // 7 days

    /** At most one Sentry event per scope + reason + blocked/covered per this many seconds. */
    private const SENTRY_THROTTLE_SECONDS = 60;

    /** A failed email send that took this long means the mail service is struggling. */
    private const SLOW_SEND_SECONDS = 3;

    /** Don't start another recipient's email once the hot path has spent this long. */
    private const DELIVER_BUDGET_SECONDS = 15;

    private const MODE_BLOCKED = 'blocked';
    private const MODE_COVERED = 'covered';

    /** Reasons tracked in a provider-wide scope. */
    private const PROVIDER_REASONS = [
        OtpProviderFailureClassifier::INSUFFICIENT_CREDIT,
        OtpProviderFailureClassifier::PRICING_NOT_CONFIGURED,
        OtpProviderFailureClassifier::CREDENTIALS_REJECTED,
    ];

    /** Sample provider replies used by sendTestAlert(). */
    private const SAMPLE_REPLIES = [
        OtpProviderFailureClassifier::INSUFFICIENT_CREDIT => 'upstream: HTTP 400: {"responseCode":508,"message":"insufficient credits","data":{"responseCode":"508","errorMessage":"insufficient credits"}}',
        OtpProviderFailureClassifier::PRICING_NOT_CONFIGURED => 'upstream: HTTP 400: {"responseCode":400,"message":"Pricing not found"}',
        OtpProviderFailureClassifier::CREDENTIALS_REJECTED => 'unauthorized: auth HTTP 401: {"message":"Unauthorized"}',
        OtpProviderFailureClassifier::NO_PROVIDER => 'no_provider: No OTP provider is available to send the code.',
    ];

    /** @var \Closure(): int */
    private readonly \Closure $clock;

    private readonly string $fallbackDir;

    /** False for the per-process InMemoryKeyValueStore: claims can't be shared through it. */
    private readonly bool $storeShared;

    /** @var array<string, int> process-local fallback claims while the store is down: key => expires-at */
    private array $processClaims = [];

    /** Set after the first store error: don't keep paying its timeout. */
    private bool $storeUnavailable = false;

    private bool $notSharedWarned = false;

    /**
     * @param (\Closure(): int)|null $clock       unix-time source (tests)
     * @param string|null            $fallbackDir dir for the store-down cooldown markers
     *                                            (default: the system temp dir)
     */
    public function __construct(
        private readonly KeyValueStore $store,
        private readonly MailerInterface $mailer,
        private readonly OtpProviderIncidentReporter $reporter,
        private readonly OtpAlertConfig $config,
        private readonly LoggerInterface $logger = new NullLogger(),
        private readonly OtpProviderFailureClassifier $classifier = new OtpProviderFailureClassifier(),
        private readonly OtpProviderAlertEmailRenderer $renderer = new OtpProviderAlertEmailRenderer(),
        ?\Closure $clock = null,
        ?string $fallbackDir = null,
    ) {
        $this->clock = $clock ?? static fn (): int => time();
        $this->fallbackDir = rtrim($fallbackDir ?? sys_get_temp_dir(), '/\\');
        $this->storeShared = !$store instanceof InMemoryKeyValueStore;
    }

    public function recordFailure(
        string $provider,
        OtpProviderException $e,
        ?string $handledBy = null,
        ?string $destination = null,
    ): void {
        try {
            $reason = $this->classifier->classify($e, $provider);
            if ($reason === null) {
                return; // not an account problem: ordinary error, no alert
            }
            if ($reason === OtpProviderFailureClassifier::NO_PROVIDER) {
                $provider = self::NO_PROVIDER_KEY;
            }
            $destination = $reason === OtpProviderFailureClassifier::PRICING_NOT_CONFIGURED
                && OtpDestination::isValid($destination) ? $destination : null;
            $scope = $destination !== null ? $provider . '@' . $destination : $provider;
            $mode = $handledBy === null ? self::MODE_BLOCKED : self::MODE_COVERED;

            $now = $this->now();
            $reply = OtpProviderAlertEmailRenderer::redactReply($e->getMessage());

            [$startedAt, $failedAttempts] = $this->markActive($scope, $reason, $now);

            if ($this->shouldReportToSentry($scope, $reason, $mode)) {
                $this->reportToSentry($provider, $reason, $handledBy, $reply, false, $destination, $startedAt, $failedAttempts);
            }

            if (!$this->claimAlertEmail($scope, $reason, $mode)) {
                $this->logger->info('otp.alert.email_suppressed_cooldown', [
                    'provider' => $provider,
                    'scope' => $scope,
                    'reason' => $reason,
                ]);
                return;
            }
            // A recovery may have cleared the state between markActive() and
            // the claim: make sure this alert is followed by a recovery email.
            $this->reassertActive($scope, $reason, $now);

            $email = $this->renderer->renderAlert(
                provider: $provider,
                reason: $reason,
                handledBy: $handledBy,
                startedAt: $startedAt,
                occurredAt: $now,
                failedAttempts: $failedAttempts,
                providerReply: $reply,
                cooldownSeconds: $this->config->cooldownSeconds,
                destination: $destination,
            );
            $this->deliver($email, $this->config->recipients, [
                'template' => 'otp.provider_alert',
                'provider' => $provider,
                'scope' => $scope,
                'reason' => $reason,
            ], hotPath: true);
        } catch (\Throwable $t) {
            $this->warn('otp.alert.record_failure_failed', $t);
        }
    }

    public function recordSuccess(string $provider, ?string $destination = null): void
    {
        if ($this->storeUnavailable) {
            return;
        }
        try {
            if ($provider === self::NO_PROVIDER_KEY) {
                $scopes = [$provider];
            } else {
                $scopes = [$provider];
                if (OtpDestination::isValid($destination)) {
                    $scopes[] = $provider . '@' . $destination;
                }
                $scopes[] = self::NO_PROVIDER_KEY;
            }
            foreach ($scopes as $scope) {
                // The hot-path read: is any alert active for this scope?
                if ($this->store->get($this->flagKey($scope)) === null) {
                    continue;
                }
                $this->recoverScope($scope, $provider);
            }
        } catch (\Throwable $t) {
            $this->markStoreUnavailable($t);
        }
    }

    /**
     * Send a TEST alert (or recovery) email to the configured recipients right
     * now, ignoring — and not touching — the cooldown and alert state. Used by
     * `bin/console otp:test-alert` so the owner can confirm emails arrive.
     * Every recipient is tried (no hot-path time budget: nobody is waiting).
     *
     * @param list<string>|null $recipients override the configured recipients
     *
     * @return array{subject: string, results: array<string, string|null>}
     *         results: recipient => null when sent, or the error message
     */
    public function sendTestAlert(
        string $provider,
        string $reason,
        bool $recovery = false,
        ?array $recipients = null,
        bool $withSentry = false,
    ): array {
        if ($reason === OtpProviderFailureClassifier::NO_PROVIDER) {
            $provider = self::NO_PROVIDER_KEY;
        }
        $now = $this->now();
        $recoveredBy = $provider === self::NO_PROVIDER_KEY ? 'messagecentral' : $provider;

        $email = $recovery
            ? $this->renderer->renderRecovery(
                provider: $provider,
                reason: $reason,
                recoveredBy: $recoveredBy,
                startedAt: $now - 3600,
                recoveredAt: $now,
                failedAttempts: 12,
                test: true,
            )
            : $this->renderer->renderAlert(
                provider: $provider,
                reason: $reason,
                handledBy: null,
                startedAt: $now,
                occurredAt: $now,
                failedAttempts: 1,
                providerReply: self::SAMPLE_REPLIES[$reason] ?? $reason,
                cooldownSeconds: $this->config->cooldownSeconds,
                test: true,
            );

        if ($withSentry && !$recovery) {
            $this->reportToSentry($provider, $reason, null, self::SAMPLE_REPLIES[$reason] ?? $reason, true, null, $now, 1);
        }

        $results = $this->deliver($email, $recipients ?? $this->config->recipients, [
            'template' => $recovery ? 'otp.provider_recovered' : 'otp.provider_alert',
            'provider' => $provider,
            'reason' => $reason,
            'test' => true,
        ], hotPath: false);

        return ['subject' => $email->subject, 'results' => $results];
    }

    // ---------------------------------------------------------------------
    // Internals
    // ---------------------------------------------------------------------

    /**
     * Record that an alert is active for scope + reason. Returns the start of
     * the alert window and the failed-request count so far (null when the store
     * is down: start = unknown, count = unknown).
     *
     * @return array{0: int|null, 1: int|null}
     */
    private function markActive(string $scope, string $reason, int $now): array
    {
        if ($this->storeUnavailable) {
            return [null, null];
        }
        try {
            $startedAt = $this->ensureActive($scope, $reason, $now);

            $countKey = $this->key('count', $scope, $reason);
            $count = $this->store->incr($countKey);
            $this->store->expire($countKey, self::ACTIVE_TTL_SECONDS);

            // Scope flag LAST, so a success never sees the flag without the state.
            $this->store->set($this->flagKey($scope), '1', self::ACTIVE_TTL_SECONDS);

            return [$startedAt, $count];
        } catch (\Throwable $t) {
            $this->markStoreUnavailable($t);
            return [null, null];
        }
    }

    /**
     * Make sure the active marker exists (and refresh its TTL); returns the
     * start of the alert window.
     */
    private function ensureActive(string $scope, string $reason, int $now): int
    {
        $activeKey = $this->key('active', $scope, $reason);
        // Twice at most: if the key vanishes between "set if absent" (lost) and
        // "get" (null), a concurrent success just recovered this alert — go
        // round once more and start a NEW window, rather than leave the flag
        // (set by the caller) pointing at no state.
        for ($attempt = 0; $attempt < 2; $attempt++) {
            if ($this->store->setIfAbsent($activeKey, (string) $now, self::ACTIVE_TTL_SECONDS)) {
                return $now;
            }
            $raw = $this->store->get($activeKey);
            if ($raw !== null) {
                $this->store->expire($activeKey, self::ACTIVE_TTL_SECONDS);
                return ctype_digit($raw) ? (int) $raw : $now;
            }
        }
        return $now;
    }

    /**
     * Called after winning the alert-email claim: if a concurrent recovery
     * cleared the state after markActive(), recreate it, so the email we are
     * about to send is followed by a "working again" one.
     */
    private function reassertActive(string $scope, string $reason, int $now): void
    {
        if ($this->storeUnavailable) {
            return;
        }
        try {
            $this->store->setIfAbsent($this->key('active', $scope, $reason), (string) $now, self::ACTIVE_TTL_SECONDS);
            $this->store->set($this->flagKey($scope), '1', self::ACTIVE_TTL_SECONDS);
        } catch (\Throwable $t) {
            $this->markStoreUnavailable($t);
        }
    }

    /**
     * An alert flag is up for $scope and $recoveredBy just sent a code (to that
     * scope's destination, for a per-country scope): send ONE recovery email
     * per active reason and clear its state.
     */
    private function recoverScope(string $scope, string $recoveredBy): void
    {
        $reasons = $this->reasonsFor($scope);
        [$provider, $destination] = $this->splitScope($scope);
        $now = $this->now();
        $stillActive = false;

        // Drop the flag FIRST: a failure racing this recovery sets it again
        // after us (markActive / reassertActive), and must not be wiped out.
        $this->store->delete($this->flagKey($scope));

        foreach ($reasons as $reason) {
            $activeKey = $this->key('active', $scope, $reason);
            $raw = $this->store->get($activeKey);
            if ($raw === null) {
                continue;
            }

            // Rate-limit the recovery email too (and make concurrent successes
            // agree on ONE sender). Losing the claim means a recovery email for
            // this problem already went out within the cooldown (it's
            // flapping): keep the state so a later success reports it.
            if (!$this->store->setIfAbsent($this->key('recovery', $scope, $reason), (string) $now, $this->config->cooldownSeconds)) {
                $stillActive = true;
                continue;
            }

            $countKey = $this->key('count', $scope, $reason);
            $countRaw = $this->store->get($countKey);

            $this->store->delete($activeKey);
            $this->store->delete($countKey);
            // Clear the alert cooldowns + Sentry throttle: if it breaks again, say so immediately.
            foreach ([self::MODE_BLOCKED, self::MODE_COVERED] as $mode) {
                $this->store->delete($this->modeKey('cooldown', $scope, $reason, $mode));
                $this->store->delete($this->modeKey('sentry', $scope, $reason, $mode));
            }

            $email = $this->renderer->renderRecovery(
                provider: $provider,
                reason: $reason,
                recoveredBy: $recoveredBy,
                startedAt: ctype_digit($raw) ? (int) $raw : null,
                recoveredAt: $now,
                failedAttempts: ($countRaw !== null && ctype_digit($countRaw)) ? (int) $countRaw : null,
                destination: $destination,
            );
            $this->deliver($email, $this->config->recipients, [
                'template' => 'otp.provider_recovered',
                'provider' => $provider,
                'scope' => $scope,
                'reason' => $reason,
            ], hotPath: true);
        }

        // Re-raise the flag while anything is (still, or again) active.
        if ($stillActive || $this->anyActive($scope, $reasons)) {
            $this->store->set($this->flagKey($scope), '1', self::ACTIVE_TTL_SECONDS);
        }
    }

    /**
     * @param list<string> $reasons
     */
    private function anyActive(string $scope, array $reasons): bool
    {
        foreach ($reasons as $reason) {
            if ($this->store->get($this->key('active', $scope, $reason)) !== null) {
                return true;
            }
        }
        return false;
    }

    /**
     * May this failure be reported to Sentry now? Throttled through the SHARED
     * store only; without one (down / per-process) every occurrence is reported.
     */
    private function shouldReportToSentry(string $scope, string $reason, string $mode): bool
    {
        if (!$this->storeShared || $this->storeUnavailable) {
            return true;
        }
        try {
            return $this->store->setIfAbsent(
                $this->modeKey('sentry', $scope, $reason, $mode),
                (string) $this->now(),
                self::SENTRY_THROTTLE_SECONDS,
            );
        } catch (\Throwable $t) {
            $this->markStoreUnavailable($t);
            return true;
        }
    }

    /**
     * Claim the right to send the alert email for this window. "Customers
     * blocked" and "a backup covered it" have separate cooldowns so that an
     * escalation (the backup failing too) is emailed at once; a "covered" email
     * is not sent once a "blocked" one went out in the same window.
     */
    private function claimAlertEmail(string $scope, string $reason, string $mode): bool
    {
        $ttl = $this->config->cooldownSeconds;
        $blockedKey = $this->modeKey('cooldown', $scope, $reason, self::MODE_BLOCKED);
        if ($mode === self::MODE_BLOCKED) {
            return $this->claim($blockedKey, $ttl);
        }
        if ($this->isClaimed($blockedKey, $ttl)) {
            return false;
        }
        return $this->claim($this->modeKey('cooldown', $scope, $reason, self::MODE_COVERED), $ttl);
    }

    /**
     * Atomic "first one wins" claim with TTL on the shared store; falls back to
     * process memory + a local marker file when the store is down or not shared.
     */
    private function claim(string $key, int $ttlSeconds): bool
    {
        if ($this->storeShared && !$this->storeUnavailable) {
            try {
                return $this->store->setIfAbsent($key, (string) $this->now(), $ttlSeconds);
            } catch (\Throwable $t) {
                $this->markStoreUnavailable($t);
            }
        }
        $this->warnStoreNotShared();
        return $this->claimWithoutStore($key, $ttlSeconds);
    }

    private function isClaimed(string $key, int $ttlSeconds): bool
    {
        if ($this->storeShared && !$this->storeUnavailable) {
            try {
                return $this->store->get($key) !== null;
            } catch (\Throwable $t) {
                $this->markStoreUnavailable($t);
            }
        }
        $now = $this->now();
        if (($this->processClaims[$key] ?? 0) > $now) {
            return true;
        }
        clearstatcache(true, $this->markerPath($key));
        $mtime = @filemtime($this->markerPath($key));
        return $mtime !== false && $mtime + $ttlSeconds > $now;
    }

    private function claimWithoutStore(string $key, int $ttlSeconds): bool
    {
        $now = $this->now();
        if (($this->processClaims[$key] ?? 0) > $now) {
            return false;
        }
        $this->processClaims[$key] = $now + $ttlSeconds;

        try {
            $path = $this->markerPath($key);
            clearstatcache(true, $path);
            $mtime = @filemtime($path);
            if ($mtime !== false && $mtime + $ttlSeconds > $now) {
                return false; // another request on this server already emailed
            }
            if (@touch($path, $now) === false) {
                $this->logger->warning('otp.alert.fallback_marker_unwritable', ['dir' => $this->fallbackDir]);
            }
        } catch (\Throwable $t) {
            $this->warn('otp.alert.fallback_marker_failed', $t);
        }
        return true;
    }

    private function markerPath(string $key): string
    {
        return $this->fallbackDir . DIRECTORY_SEPARATOR . 'otp-alert-' . sha1($key) . '.marker';
    }

    /**
     * @param list<string>         $recipients
     * @param array<string, mixed> $context
     * @param bool                 $hotPath    inside a customer's OTP request: apply the time budget
     *
     * @return array<string, string|null> recipient => null (sent) | error message
     */
    private function deliver(RenderedEmail $email, array $recipients, array $context, bool $hotPath): array
    {
        if ($recipients === []) {
            $this->logger->warning('otp.alert.no_recipients', $context + [
                'hint' => 'Set OTP_ALERT_EMAILS (or ADMIN_NOTIFICATION_EMAILS) to receive SMS-provider alerts.',
            ]);
            return [];
        }

        $results = [];
        $start = $this->now();
        foreach ($recipients as $i => $to) {
            if ($hotPath && $i > 0 && $this->now() - $start >= self::DELIVER_BUDGET_SECONDS) {
                // Don't hold this customer's OTP request any longer.
                $this->skipRest($results, $recipients, $i, 'skipped: alert email time budget used up', $context);
                break;
            }
            $sendStart = $this->now();
            try {
                $this->mailer->send($to, $email->subject, $email->textBody, $email->htmlBody, $context);
                $results[$to] = null;
            } catch (\Throwable $t) {
                $results[$to] = $t->getMessage();
                $this->logger->warning('otp.alert.email_failed', $context + [
                    'to' => $to,
                    'error' => $t->getMessage(),
                    'kind' => $t instanceof MailerException ? $t->kind : $t::class,
                ]);
                $unreachable = $t instanceof MailerException && $t->kind === MailerException::KIND_NETWORK;
                $slow = $this->now() - $sendStart >= self::SLOW_SEND_SECONDS;
                if ($hotPath && ($unreachable || $slow)) {
                    // The mail service is unreachable / struggling. Don't make
                    // this customer's OTP request wait one timeout per recipient.
                    $this->skipRest($results, $recipients, $i + 1, 'skipped: mail service unreachable or too slow', $context);
                    break;
                }
            }
        }
        return $results;
    }

    /**
     * @param array<string, string|null> $results
     * @param list<string>               $recipients
     * @param array<string, mixed>       $context
     */
    private function skipRest(array &$results, array $recipients, int $from, string $why, array $context): void
    {
        $skipped = array_slice($recipients, $from);
        foreach ($skipped as $to) {
            $results[$to] = $why;
        }
        if ($skipped !== []) {
            $this->logger->warning('otp.alert.email_recipients_skipped', $context + [
                'skipped' => count($skipped),
                'why' => $why,
            ]);
        }
    }

    private function reportToSentry(
        string $provider,
        string $reason,
        ?string $handledBy,
        string $reply,
        bool $test,
        ?string $destination,
        ?int $startedAt,
        ?int $failedAttempts,
    ): void {
        try {
            $this->reporter->reportAccountFailure(
                provider: $provider,
                reason: $reason,
                message: OtpProviderAlertEmailRenderer::sentryMessage($provider, $reason),
                customersBlocked: $handledBy === null,
                details: [
                    'provider' => $provider,
                    'reason' => $reason,
                    'backup_provider' => $handledBy,
                    'destination' => $destination,
                    // Events are throttled (one a minute), so carry the impact.
                    'failed_attempts_so_far' => $failedAttempts,
                    'alert_started_at' => $startedAt !== null ? gmdate('Y-m-d\TH:i:s\Z', $startedAt) : null,
                    'provider_reply' => OtpProviderAlertEmailRenderer::redactReply($reply),
                ],
                test: $test,
            );
        } catch (\Throwable $t) {
            $this->warn('otp.alert.sentry_failed', $t);
        }
    }

    /**
     * @return list<string>
     */
    private function reasonsFor(string $scope): array
    {
        if ($scope === self::NO_PROVIDER_KEY) {
            return [OtpProviderFailureClassifier::NO_PROVIDER];
        }
        if (str_contains($scope, '@')) {
            return [OtpProviderFailureClassifier::PRICING_NOT_CONFIGURED];
        }
        return self::PROVIDER_REASONS;
    }

    /**
     * @return array{0: string, 1: string|null} [provider, destination dial code]
     */
    private function splitScope(string $scope): array
    {
        $at = strpos($scope, '@');
        if ($at === false) {
            return [$scope, null];
        }
        return [substr($scope, 0, $at), substr($scope, $at + 1)];
    }

    private function markStoreUnavailable(\Throwable $t): void
    {
        if (!$this->storeUnavailable) {
            $this->storeUnavailable = true;
            $this->warn('otp.alert.store_unavailable', $t);
        }
    }

    private function warnStoreNotShared(): void
    {
        if ($this->storeShared || $this->notSharedWarned) {
            return;
        }
        $this->notSharedWarned = true;
        try {
            $this->logger->warning('otp.alert.store_not_shared', [
                'hint' => 'REDIS_DSN is not set: the OTP alert cooldown falls back to marker files; recovery emails are off. Set REDIS_DSN in the API .env.',
            ]);
        } catch (\Throwable) {
            // Logging itself failed; nothing left to do without risking the OTP path.
        }
    }

    private function warn(string $event, \Throwable $t): void
    {
        try {
            $this->logger->warning($event, ['error' => $t->getMessage(), 'exception' => $t::class]);
        } catch (\Throwable) {
            // Logging itself failed; nothing left to do without risking the OTP path.
        }
    }

    private function key(string $kind, string $scope, string $reason): string
    {
        return self::KEY_PREFIX . $kind . ':' . $scope . ':' . $reason;
    }

    private function modeKey(string $kind, string $scope, string $reason, string $mode): string
    {
        return $this->key($kind, $scope, $reason) . ':' . $mode;
    }

    private function flagKey(string $scope): string
    {
        return self::KEY_PREFIX . 'any:' . $scope;
    }

    private function now(): int
    {
        return ($this->clock)();
    }
}
