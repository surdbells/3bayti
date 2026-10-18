<?php

declare(strict_types=1);

namespace Bayti\Api\Tests\Infrastructure\Otp;

use Bayti\Api\Infrastructure\Cache\InMemoryKeyValueStore;
use Bayti\Api\Infrastructure\Cache\KeyValueStore;
use Bayti\Api\Infrastructure\Otp\OtpAlertConfig;
use Bayti\Api\Infrastructure\Otp\OtpProviderAlertEmailRenderer;
use Bayti\Api\Infrastructure\Otp\OtpProviderException;
use Bayti\Api\Infrastructure\Otp\OtpProviderFailureClassifier;
use Bayti\Api\Infrastructure\Otp\OtpProviderHealthMonitor;
use Bayti\Api\Infrastructure\Otp\OtpProviderHealthObserver;
use Bayti\Api\Notification\InMemoryMailer;
use Bayti\Api\Notification\MailerException;
use Bayti\Api\Notification\MailerInterface;
use Bayti\Api\Tests\Support\InMemoryLogger;
use Bayti\Api\Tests\Support\Otp\ClockAdvancingMailer;
use Bayti\Api\Tests\Support\Otp\FailingMailer;
use Bayti\Api\Tests\Support\Otp\FakeClockKeyValueStore;
use Bayti\Api\Tests\Support\Otp\HookingMailer;
use Bayti\Api\Tests\Support\Otp\RecordingOtpIncidentReporter;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(OtpProviderHealthMonitor::class)]
#[CoversClass(OtpProviderAlertEmailRenderer::class)]
final class OtpProviderHealthMonitorTest extends TestCase
{
    private const OWNER = 'owner@3bayti.test';
    private const OPS = 'ops@3bayti.test';

    private const MC_508 = 'HTTP 400: {"responseCode":508,"message":"insufficient credits","data":{"responseCode":"508","errorMessage":"insufficient credits"}}';
    private const MC_PRICING = 'HTTP 400: {"responseCode":400,"message":"Pricing not found"}';

    private int $now;
    private FakeClockKeyValueStore $store;
    private InMemoryMailer $mailer;
    private RecordingOtpIncidentReporter $reporter;
    private InMemoryLogger $logger;
    private string $dir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->now = (new \DateTimeImmutable('2026-10-14 09:12:00 UTC'))->getTimestamp();
        $this->store = new FakeClockKeyValueStore(fn (): int => $this->now);
        $this->mailer = new InMemoryMailer();
        $this->reporter = new RecordingOtpIncidentReporter();
        $this->logger = new InMemoryLogger();
        $this->dir = sys_get_temp_dir() . '/otp-alert-test-' . bin2hex(random_bytes(6));
        mkdir($this->dir, 0750, true);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->dir . '/*') ?: [] as $f) {
            @unlink($f);
        }
        @rmdir($this->dir);
        parent::tearDown();
    }

    // ------------------------------------------------------------------
    // Alerting
    // ------------------------------------------------------------------

    #[Test]
    public function firstAccountFailureReportsOneSentryEventAndEmailsEachRecipientOnce(): void
    {
        $this->monitor()->recordFailure('messagecentral', $this->upstream(self::MC_508));

        self::assertCount(1, $this->reporter->reports);
        $report = $this->reporter->reports[0];
        self::assertSame('messagecentral', $report['provider']);
        self::assertSame(OtpProviderFailureClassifier::INSUFFICIENT_CREDIT, $report['reason']);
        self::assertSame(
            'SMS provider messagecentral: out of credit — customers cannot receive login/verification codes',
            $report['message'],
        );
        self::assertTrue($report['customersBlocked']);
        self::assertFalse($report['test']);

        $sent = $this->mailer->sent();
        self::assertCount(2, $sent);
        self::assertSame([self::OWNER, self::OPS], array_column($sent, 'to'));
        self::assertSame('otp.provider_alert', $sent[0]['context']['template']);

        $email = $sent[0];
        self::assertSame(
            "[3bayti] Action needed: customers can't get SMS codes - MessageCentral is out of credit",
            $email['subject'],
        );
        $text = $email['text_body'];
        self::assertStringContainsString('MessageCentral refused to send a login/verification code because the account has run out of credit', $text);
        foreach (['sign up for a new account', 'log in with a phone code', 'verify their phone number', 'reset their password by SMS'] as $blocked) {
            self::assertStringContainsString($blocked, $text);
        }
        self::assertStringContainsString('Log in to your MessageCentral account and top up the balance.', $text);
        self::assertStringContainsString('switch on automatic top-up (auto-recharge) or a low-balance email alert in the MessageCentral account', $text);
        self::assertStringContainsString('There is no working backup SMS provider', $text);
        self::assertStringContainsString('Started:               Wed 14 Oct 2026, 13:12 UAE time (09:12 UTC)', $text);
        self::assertStringContainsString('Failed code requests:  1 so far', $text);
        self::assertStringContainsString('"responseCode":508,"message":"insufficient credits"', $text);
        self::assertStringContainsString('at most one alert per hour', $text);

        // HTML twin carries the same message, escaped.
        self::assertStringContainsString('top up the balance', $email['html_body']);
        self::assertStringContainsString('&quot;responseCode&quot;:508', $email['html_body']);
    }

    #[Test]
    public function repeatFailuresWithinTheCooldownStillReportToSentryButSendNoSecondEmail(): void
    {
        $monitor = $this->monitor();
        $monitor->recordFailure('messagecentral', $this->upstream(self::MC_508));
        $this->now += 600;
        $monitor->recordFailure('messagecentral', $this->upstream(self::MC_508));
        // A different request (new monitor instance) on another worker, same store.
        $this->monitor()->recordFailure('messagecentral', $this->upstream(self::MC_508));

        // Sentry: t and t+600 are reported; the second t+600 one falls inside the
        // 60 s Sentry throttle (each event is a synchronous HTTP call).
        self::assertCount(2, $this->reporter->reports, 'still reported, at most once a minute');
        self::assertSame(2, $this->reporter->reports[1]['details']['failed_attempts_so_far']);
        self::assertCount(2, $this->mailer->sent(), 'one email per recipient, not per failure');
        self::assertNotEmpty($this->logger->findByMessage('otp.alert.email_suppressed_cooldown'));
    }

    #[Test]
    public function aDifferentReasonGetsItsOwnEmail(): void
    {
        $monitor = $this->monitor(recipients: [self::OWNER]);
        $monitor->recordFailure('messagecentral', $this->upstream(self::MC_508));
        $monitor->recordFailure('messagecentral', $this->upstream(self::MC_PRICING));
        $monitor->recordFailure('messagecentral', $this->upstream(self::MC_PRICING));

        $subjects = array_column($this->mailer->sent(), 'subject');
        self::assertSame([
            "[3bayti] Action needed: customers can't get SMS codes - MessageCentral is out of credit",
            // Destination unknown: pricing can be per country, so not "customers" in general.
            "[3bayti] Action needed: some or all customers can't get SMS codes - MessageCentral has no SMS pricing set up",
        ], $subjects);
        self::assertStringContainsString('ask them to enable and price SMS verification (OTP) codes', $this->mailer->sent()[1]['text_body']);
        self::assertStringContainsString('This can affect every customer, or only customers in one country.', $this->mailer->sent()[1]['text_body']);
    }

    #[Test]
    public function aDifferentProviderGetsItsOwnEmail(): void
    {
        $monitor = $this->monitor(recipients: [self::OWNER]);
        $monitor->recordFailure('messagecentral', $this->upstream(self::MC_508));
        $monitor->recordFailure('cequens', $this->upstream('HTTP 400: {"replyMessage":"Insufficient balance"}'));

        self::assertCount(2, $this->mailer->sent());
        self::assertStringContainsString('Cequens is out of credit', $this->mailer->sent()[1]['subject']);
    }

    #[Test]
    public function afterTheCooldownItEmailsAgainKeepingTheOriginalStartAndCount(): void
    {
        $monitor = $this->monitor(recipients: [self::OWNER]);
        $monitor->recordFailure('messagecentral', $this->upstream(self::MC_508));
        $this->now += 1800;
        $monitor->recordFailure('messagecentral', $this->upstream(self::MC_508));
        $this->now += 1801; // > 3600 s after the first email
        $monitor->recordFailure('messagecentral', $this->upstream(self::MC_508));

        $sent = $this->mailer->sent();
        self::assertCount(2, $sent);
        self::assertStringContainsString('Started:               Wed 14 Oct 2026, 13:12 UAE time (09:12 UTC)', $sent[1]['text_body']);
        self::assertStringContainsString('This alert:            Wed 14 Oct 2026, 14:12 UAE time (10:12 UTC)', $sent[1]['text_body']);
        self::assertStringContainsString('Failed code requests:  3 so far', $sent[1]['text_body']);
    }

    #[Test]
    public function cooldownComesFromConfig(): void
    {
        $monitor = $this->monitor(recipients: [self::OWNER], cooldown: 120);
        $monitor->recordFailure('messagecentral', $this->upstream(self::MC_508));
        $this->now += 121;
        $monitor->recordFailure('messagecentral', $this->upstream(self::MC_508));

        self::assertCount(2, $this->mailer->sent());
        self::assertStringContainsString('at most one alert every 2 minutes', $this->mailer->sent()[0]['text_body']);
    }

    /**
     * @return iterable<string, array{0: OtpProviderException}>
     */
    public static function ordinaryFailures(): iterable
    {
        yield 'network' => [new OtpProviderException('network', 'cURL error 28: Operation timed out')];
        yield '5xx' => [new OtpProviderException('upstream', 'HTTP 503: Service Unavailable')];
        yield 'rate limited' => [new OtpProviderException('upstream', 'HTTP 429: slow down')];
        yield 'invalid number' => [new OtpProviderException('upstream', 'HTTP 400: {"responseCode":511,"message":"Invalid mobile number"}')];
        yield 'malformed' => [new OtpProviderException('malformed', 'Response was not JSON: <html>')];
    }

    #[Test]
    #[DataProvider('ordinaryFailures')]
    public function ordinaryFailuresAreIgnoredEntirely(OtpProviderException $e): void
    {
        $this->monitor()->recordFailure('messagecentral', $e);

        self::assertSame([], $this->reporter->reports, 'no Sentry incident (the ordinary error is already in Sentry)');
        self::assertSame([], $this->mailer->sent());
        self::assertSame([], $this->store->keys());
        self::assertSame([], $this->store->ops, 'not even a store read');
    }

    #[Test]
    public function whenABackupDeliveredTheCodeTheEmailAndSentrySaySo(): void
    {
        $this->monitor(recipients: [self::OWNER])
            ->recordFailure('messagecentral', $this->upstream(self::MC_508), handledBy: 'cequens');

        $report = $this->reporter->reports[0];
        self::assertFalse($report['customersBlocked']);
        self::assertSame('cequens', $report['details']['backup_provider']);

        $email = $this->mailer->sent()[0];
        self::assertSame('[3bayti] Action needed: MessageCentral is out of credit (backup Cequens is sending codes)', $email['subject']);
        self::assertStringContainsString('Customers were NOT affected this time: the backup provider Cequens sent the code instead.', $email['text_body']);
        self::assertStringContainsString('Cequens - delivered the code instead', $email['text_body']);
        self::assertStringNotContainsString('There is no working backup', $email['text_body']);
    }

    #[Test]
    public function noProviderIsAlertedUnderTheNoneScope(): void
    {
        $this->monitor(recipients: [self::OWNER])
            ->recordFailure('whatever', new OtpProviderException('no_provider', 'No OTP provider is available to send the code.'));

        self::assertSame(OtpProviderHealthObserver::NO_PROVIDER_KEY, $this->reporter->reports[0]['provider']);
        self::assertSame('No SMS provider available — customers cannot receive login/verification codes', $this->reporter->reports[0]['message']);
        $email = $this->mailer->sent()[0];
        self::assertSame("[3bayti] Action needed: customers can't get SMS codes - no SMS provider is available", $email['subject']);
        self::assertStringContainsString('Settings > OTP Providers', $email['text_body']);
    }

    #[Test]
    public function credentialsRejectedTellsTheOwnerWhatToCheck(): void
    {
        $this->monitor(recipients: [self::OWNER])
            ->recordFailure('messagecentral', new OtpProviderException('unauthorized', 'auth HTTP 401: {"message":"Unauthorized"}'));

        $email = $this->mailer->sent()[0];
        self::assertSame("[3bayti] Action needed: customers can't get SMS codes - MessageCentral rejected our credentials", $email['subject']);
        self::assertStringContainsString('check that it is active (not suspended or expired)', $email['text_body']);
    }

    // ------------------------------------------------------------------
    // Recovery
    // ------------------------------------------------------------------

    #[Test]
    public function recoverySendsOneWorkingAgainEmailThenClearsTheState(): void
    {
        $monitor = $this->monitor(recipients: [self::OWNER]);
        $monitor->recordFailure('messagecentral', $this->upstream(self::MC_508));
        $this->now += 600;
        $monitor->recordFailure('messagecentral', $this->upstream(self::MC_508));
        $this->now += (4 * 86400) + 3000;

        $monitor->recordSuccess('messagecentral');
        $monitor->recordSuccess('messagecentral');
        $this->monitor(recipients: [self::OWNER])->recordSuccess('messagecentral');

        $sent = $this->mailer->sent();
        self::assertCount(2, $sent, 'one alert + ONE recovery');
        $recovery = $sent[1];
        self::assertSame('otp.provider_recovered', $recovery['context']['template']);
        self::assertSame('[3bayti] Resolved: SMS codes are working again (MessageCentral)', $recovery['subject']);
        self::assertStringContainsString('Good news: MessageCentral just sent a login/verification code successfully', $recovery['text_body']);
        self::assertStringContainsString('The earlier problem was: MessageCentral out of credit.', $recovery['text_body']);
        self::assertStringContainsString('Started:               Wed 14 Oct 2026, 13:12 UAE time (09:12 UTC)', $recovery['text_body']);
        self::assertStringContainsString('Duration:              about 4 days 1 hour', $recovery['text_body']);
        self::assertStringContainsString('Failed code requests:  2 while it was broken', $recovery['text_body']);

        // State cleared: only the short-lived recovery claim remains.
        self::assertSame(['otp:alert:recovery:messagecentral:insufficient_credit'], $this->store->keys());
    }

    #[Test]
    public function aNewBreakageAfterRecoveryIsReportedImmediately(): void
    {
        $monitor = $this->monitor(recipients: [self::OWNER]);
        $monitor->recordFailure('messagecentral', $this->upstream(self::MC_508));
        $this->now += 300;
        $monitor->recordSuccess('messagecentral');
        $this->now += 60; // well inside the original alert cooldown
        $monitor->recordFailure('messagecentral', $this->upstream(self::MC_508));

        $subjects = array_column($this->mailer->sent(), 'subject');
        self::assertCount(3, $subjects);
        self::assertStringContainsString('Action needed', $subjects[2], 'the cooldown is cleared on recovery');
    }

    #[Test]
    public function flappingWithinTheCooldownKeepsStateUntilALaterSuccess(): void
    {
        $monitor = $this->monitor(recipients: [self::OWNER]);
        $monitor->recordFailure('messagecentral', $this->upstream(self::MC_508)); // alert #1
        $this->now += 60;
        $monitor->recordSuccess('messagecentral');                                // recovery #1
        $this->now += 60;
        $monitor->recordFailure('messagecentral', $this->upstream(self::MC_508)); // alert #2
        $this->now += 60;
        $monitor->recordSuccess('messagecentral');                                // recovery rate-limited → state kept
        self::assertCount(3, $this->mailer->sent());
        self::assertContains('otp:alert:active:messagecentral:insufficient_credit', $this->store->keys());

        $this->now += 3600;
        $monitor->recordSuccess('messagecentral');                                // recovery #2
        $sent = $this->mailer->sent();
        self::assertCount(4, $sent);
        self::assertStringContainsString('Resolved', $sent[3]['subject']);
    }

    #[Test]
    public function successWithNoActiveAlertIsOneReadPerScopeAndNoWrites(): void
    {
        $this->monitor()->recordSuccess('messagecentral');

        self::assertSame(['get' => 2], $this->store->ops, 'flag for the provider + the no-provider scope');
        self::assertSame(0, $this->store->writeOps());
        self::assertSame([], $this->mailer->sent());
    }

    #[Test]
    public function aSuccessByAnyProviderResolvesANoProviderAlert(): void
    {
        $monitor = $this->monitor(recipients: [self::OWNER]);
        $monitor->recordFailure('none', new OtpProviderException('no_provider', 'No OTP provider is available to send the code.'));
        $monitor->recordSuccess('cequens');

        $recovery = $this->mailer->sent()[1];
        self::assertSame('[3bayti] Resolved: SMS codes are working again (Cequens)', $recovery['subject']);
        self::assertStringContainsString('The earlier problem was: no SMS provider available.', $recovery['text_body']);
    }

    #[Test]
    public function recoveryOfOneProviderDoesNotClearAnotherProvidersAlert(): void
    {
        $monitor = $this->monitor(recipients: [self::OWNER]);
        $monitor->recordFailure('messagecentral', $this->upstream(self::MC_508), handledBy: 'cequens');
        $monitor->recordSuccess('cequens'); // the backup delivering is NOT MessageCentral recovering

        self::assertCount(1, $this->mailer->sent());
        self::assertContains('otp:alert:active:messagecentral:insufficient_credit', $this->store->keys());
    }

    // ------------------------------------------------------------------
    // Resilience: nothing may escape into the OTP path
    // ------------------------------------------------------------------

    #[Test]
    public function storeDownStillReportsToSentryAndEmailsAtMostOncePerServerViaTheMarkerFile(): void
    {
        $this->store->down = true;

        $first = $this->monitor(recipients: [self::OWNER]);
        $first->recordFailure('messagecentral', $this->upstream(self::MC_508));
        $first->recordFailure('messagecentral', $this->upstream(self::MC_508));
        // Next request on the same server: new instance, same marker directory.
        $this->monitor(recipients: [self::OWNER])->recordFailure('messagecentral', $this->upstream(self::MC_508));

        self::assertCount(3, $this->reporter->reports, 'Sentry is reported regardless of the store');
        self::assertCount(1, $this->mailer->sent(), 'one email, not one per failure');
        self::assertMatchesRegularExpression('/Started:\s+unknown - first noticed Wed 14 Oct 2026/', $this->mailer->sent()[0]['text_body']);
        self::assertStringNotContainsString('Failed code requests', $this->mailer->sent()[0]['text_body']);
        self::assertCount(2, $this->logger->findByMessage('otp.alert.store_unavailable'), 'logged once per instance (2 here), not per call (3)');

        $this->now += 3601;
        $this->monitor(recipients: [self::OWNER])->recordFailure('messagecentral', $this->upstream(self::MC_508));
        self::assertCount(2, $this->mailer->sent(), 'the marker honours the cooldown too');

        // Success with the store down: silent no-op.
        $first->recordSuccess('messagecentral');
        self::assertCount(2, $this->mailer->sent());
    }

    #[Test]
    public function storeDownAndNoWritableMarkerDirFallsBackToOncePerProcess(): void
    {
        $this->store->down = true;
        $unwritable = $this->dir . '/does-not-exist/nested';

        $monitor = $this->monitor(recipients: [self::OWNER], fallbackDir: $unwritable);
        $monitor->recordFailure('messagecentral', $this->upstream(self::MC_508));
        $monitor->recordFailure('messagecentral', $this->upstream(self::MC_508));
        self::assertCount(1, $this->mailer->sent(), 'once per process');

        $this->monitor(recipients: [self::OWNER], fallbackDir: $unwritable)
            ->recordFailure('messagecentral', $this->upstream(self::MC_508));
        self::assertCount(2, $this->mailer->sent(), 'documented degraded mode: once per process/request');
        self::assertNotEmpty($this->logger->findByMessage('otp.alert.fallback_marker_unwritable'));
    }

    #[Test]
    public function aFailingMailerNeverEscapesAndEveryRecipientIsTried(): void
    {
        $mailer = new FailingMailer(MailerException::KIND_TRANSPORT);
        $this->monitor(mailer: $mailer)->recordFailure('messagecentral', $this->upstream(self::MC_508));

        self::assertSame([self::OWNER, self::OPS], $mailer->attempts);
        self::assertCount(1, $this->reporter->reports);
        self::assertCount(2, $this->logger->findByMessage('otp.alert.email_failed'));
    }

    #[Test]
    public function aMailServiceNetworkFailureSkipsTheRemainingRecipients(): void
    {
        $mailer = new FailingMailer(MailerException::KIND_NETWORK);
        $this->monitor(mailer: $mailer, recipients: [self::OWNER, self::OPS, 'third@3bayti.test'])
            ->recordFailure('messagecentral', $this->upstream(self::MC_508));

        self::assertSame([self::OWNER], $mailer->attempts, 'one timeout, not one per recipient');
    }

    #[Test]
    public function aFailingSentryNeverEscapesAndTheEmailStillGoesOut(): void
    {
        $this->reporter = new RecordingOtpIncidentReporter(throw: true);
        $this->monitor(recipients: [self::OWNER])->recordFailure('messagecentral', $this->upstream(self::MC_508));

        self::assertCount(1, $this->mailer->sent());
        self::assertNotEmpty($this->logger->findByMessage('otp.alert.sentry_failed'));
    }

    #[Test]
    public function noRecipientsStillReportsToSentryAndLogsAHint(): void
    {
        $this->monitor(recipients: [])->recordFailure('messagecentral', $this->upstream(self::MC_508));

        self::assertCount(1, $this->reporter->reports);
        self::assertSame([], $this->mailer->sent());
        self::assertNotEmpty($this->logger->findByMessage('otp.alert.no_recipients'));
    }

    #[Test]
    public function aThrowingStoreDuringRecoveryNeverEscapes(): void
    {
        $monitor = $this->monitor(recipients: [self::OWNER]);
        $monitor->recordFailure('messagecentral', $this->upstream(self::MC_508));
        $this->store->down = true;

        $monitor->recordSuccess('messagecentral');

        self::assertCount(1, $this->mailer->sent());
        self::assertNotEmpty($this->logger->findByMessage('otp.alert.store_unavailable'));
    }

    // ------------------------------------------------------------------
    // Sentry throttle + escalation (blocked vs covered)
    // ------------------------------------------------------------------

    #[Test]
    public function sentryIsThrottledToOneEventAMinuteCarryingTheRunningCount(): void
    {
        $monitor = $this->monitor(recipients: [self::OWNER]);
        $monitor->recordFailure('messagecentral', $this->upstream(self::MC_508));
        $this->now += 20;
        $monitor->recordFailure('messagecentral', $this->upstream(self::MC_508));
        $this->now += 20;
        $this->monitor(recipients: [self::OWNER])->recordFailure('messagecentral', $this->upstream(self::MC_508));
        self::assertCount(1, $this->reporter->reports, 'one synchronous Sentry call a minute, not one per failed request');

        $this->now += 21; // 61 s after the first event
        $monitor->recordFailure('messagecentral', $this->upstream(self::MC_508));
        self::assertCount(2, $this->reporter->reports);
        self::assertSame(1, $this->reporter->reports[0]['details']['failed_attempts_so_far']);
        self::assertSame(4, $this->reporter->reports[1]['details']['failed_attempts_so_far']);
        self::assertSame('2026-10-14T09:12:00Z', $this->reporter->reports[1]['details']['alert_started_at']);

        // A backup-covered occurrence is a different (less severe) event: its own throttle.
        $monitor->recordFailure('messagecentral', $this->upstream(self::MC_508), handledBy: 'cequens');
        self::assertCount(3, $this->reporter->reports);
        self::assertFalse($this->reporter->reports[2]['customersBlocked']);
    }

    #[Test]
    public function aBackupFailingAfterACoveredAlertEscalatesAtOnce(): void
    {
        $monitor = $this->monitor(recipients: [self::OWNER]);
        $monitor->recordFailure('messagecentral', $this->upstream(self::MC_508), handledBy: 'cequens');
        $this->now += 600;
        // Cequens now fails too (an ordinary error, not alerted itself): the
        // MessageCentral failure is no longer covered — customers are blocked.
        $monitor->recordFailure('messagecentral', $this->upstream(self::MC_508));
        $this->now += 300;
        $monitor->recordFailure('messagecentral', $this->upstream(self::MC_508), handledBy: 'cequens');
        $monitor->recordFailure('messagecentral', $this->upstream(self::MC_508));

        $subjects = array_column($this->mailer->sent(), 'subject');
        self::assertSame([
            '[3bayti] Action needed: MessageCentral is out of credit (backup Cequens is sending codes)',
            "[3bayti] Action needed: customers can't get SMS codes - MessageCentral is out of credit",
        ], $subjects, 'escalation emailed at once; no "covered" email after a "blocked" one in the same window');
    }

    // ------------------------------------------------------------------
    // Pricing is per destination country
    // ------------------------------------------------------------------

    #[Test]
    public function aPricingProblemIsScopedToTheDestinationCountry(): void
    {
        $monitor = $this->monitor(recipients: [self::OWNER]);
        $monitor->recordFailure('messagecentral', $this->upstream(self::MC_PRICING), destination: '973');

        $alert = $this->mailer->sent()[0];
        self::assertSame(
            "[3bayti] Action needed: customers with +973 (Bahrain) numbers can't get SMS codes - MessageCentral has no SMS pricing for that country",
            $alert['subject'],
        );
        self::assertStringContainsString('refused to send a login/verification code to a +973 (Bahrain) number because SMS pricing is not set up on our account for that country', $alert['text_body']);
        self::assertStringContainsString('customers with +973 (Bahrain) numbers cannot:', $alert['text_body']);
        self::assertStringContainsString('price SMS verification (OTP) codes to +973 (Bahrain) numbers', $alert['text_body']);
        self::assertStringContainsString('Destination:', $alert['text_body']);
        self::assertSame('973', $this->reporter->reports[0]['details']['destination']);
        self::assertSame(OtpProviderFailureClassifier::PRICING_NOT_CONFIGURED, $this->reporter->reports[0]['reason']);

        // Codes to OTHER countries working proves nothing about Bahrain.
        $monitor->recordSuccess('messagecentral', '971');
        $monitor->recordSuccess('messagecentral');
        self::assertCount(1, $this->mailer->sent());

        $this->now += 120;
        $monitor->recordSuccess('messagecentral', '973');
        $recovery = $this->mailer->sent()[1];
        self::assertSame('[3bayti] Resolved: SMS codes to +973 (Bahrain) numbers are working again (MessageCentral)', $recovery['subject']);
        self::assertStringContainsString('Customers with +973 (Bahrain) numbers can again', $recovery['text_body']);
        self::assertSame(['otp:alert:recovery:messagecentral@973:pricing_not_configured'], $this->store->keys());
    }

    /**
     * The real Oct 7 2026 pattern: ONE customer's unpriced destination failing
     * on every retry while other customers' codes go through.
     */
    #[Test]
    public function oneCustomersUnpricedCountryRetryingDoesNotFlap(): void
    {
        $monitor = $this->monitor(recipients: [self::OWNER]);
        foreach (str_split('FSFSFFSFS') as $step) {
            $step === 'F'
                ? $monitor->recordFailure('messagecentral', $this->upstream(self::MC_PRICING), destination: '966')
                : $monitor->recordSuccess('messagecentral', '971');
            $this->now += 30;
        }

        $subjects = array_column($this->mailer->sent(), 'subject');
        self::assertCount(1, $subjects, 'one alert, no "Resolved" from other countries, no re-alert');
        self::assertStringContainsString('+966 (Saudi Arabia)', $subjects[0]);
        self::assertCount(4, $this->reporter->reports, '5 failures over 3.5 min, throttled to one Sentry event a minute');
    }

    #[Test]
    public function aPricingProblemWithAnUnknownDestinationIsProviderWide(): void
    {
        $monitor = $this->monitor(recipients: [self::OWNER]);
        $monitor->recordFailure('messagecentral', $this->upstream(self::MC_PRICING));
        $monitor->recordSuccess('messagecentral', '971');

        $subjects = array_column($this->mailer->sent(), 'subject');
        self::assertSame([
            "[3bayti] Action needed: some or all customers can't get SMS codes - MessageCentral has no SMS pricing set up",
            '[3bayti] Resolved: SMS codes are working again (MessageCentral)',
        ], $subjects);
    }

    #[Test]
    public function successWithADestinationAndNoActiveAlertIsOneReadPerScope(): void
    {
        $this->monitor()->recordSuccess('messagecentral', '971');

        self::assertSame(['get' => 3], $this->store->ops, 'provider + provider@971 + no-provider flags');
        self::assertSame(0, $this->store->writeOps());
    }

    // ------------------------------------------------------------------
    // Races: a failure overlapping a recovery (e.g. right at top-up time)
    // must never leave an "Action needed" email without a later "Resolved".
    // ------------------------------------------------------------------

    #[Test]
    public function aRecoveryBetweenTheFailuresTwoActiveKeyCallsStillEndsInAResolvedEmail(): void
    {
        $monitor = $this->monitor(recipients: [self::OWNER]);
        $monitor->recordFailure('messagecentral', $this->upstream(self::MC_508));
        $this->now += 60;

        // F's setIfAbsent(active) loses, then — before its get(active) — a
        // concurrent request's success recovers and deletes the state.
        $this->store->runOnceBefore(
            static fn (string $op, string $key): bool => $op === 'get' && $key === 'otp:alert:active:messagecentral:insufficient_credit',
            fn () => $this->monitor(recipients: [self::OWNER])->recordSuccess('messagecentral'),
        );
        $monitor->recordFailure('messagecentral', $this->upstream(self::MC_508));

        $this->now += 60;
        $monitor->recordSuccess('messagecentral'); // recovery email rate-limited: state kept
        $this->now += 3600;
        $monitor->recordSuccess('messagecentral');

        self::assertSame(['Action needed', 'Resolved', 'Action needed', 'Resolved'], $this->subjectKinds());
        self::assertSame(['otp:alert:recovery:messagecentral:insufficient_credit'], $this->store->keys(), 'no orphaned count/flag');
    }

    #[Test]
    public function aRecoveryBetweenMarkingAndClaimingTheEmailStillEndsInAResolvedEmail(): void
    {
        $monitor = $this->monitor(recipients: [self::OWNER]);
        $monitor->recordFailure('messagecentral', $this->upstream(self::MC_508));
        $this->now += 60;

        $this->store->runOnceBefore(
            static fn (string $op, string $key): bool => $op === 'setIfAbsent' && $key === 'otp:alert:cooldown:messagecentral:insufficient_credit:blocked',
            fn () => $this->monitor(recipients: [self::OWNER])->recordSuccess('messagecentral'),
        );
        $monitor->recordFailure('messagecentral', $this->upstream(self::MC_508));

        $this->now += 3700;
        $monitor->recordSuccess('messagecentral');

        self::assertSame(['Action needed', 'Resolved', 'Action needed', 'Resolved'], $this->subjectKinds());
    }

    #[Test]
    public function aFailureWhileTheRecoveryEmailIsSendingKeepsItsStateReachable(): void
    {
        $mailer = new HookingMailer($this->mailer);
        $monitor = $this->monitor(mailer: $mailer, recipients: [self::OWNER]);
        $monitor->recordFailure('messagecentral', $this->upstream(self::MC_508));
        $this->now += 60;

        // While request S is still sending "Resolved", request F fails again.
        $mailer->onceAfter('Resolved', fn () => $this->monitor(mailer: $mailer, recipients: [self::OWNER])
            ->recordFailure('messagecentral', $this->upstream(self::MC_508)));
        $monitor->recordSuccess('messagecentral');

        $this->now += 3700;
        $monitor->recordSuccess('messagecentral');

        self::assertSame(['Action needed', 'Resolved', 'Action needed', 'Resolved'], $this->subjectKinds());
    }

    // ------------------------------------------------------------------
    // Resilience: a store that isn't shared, a slow mail service
    // ------------------------------------------------------------------

    #[Test]
    public function aPerProcessInMemoryStoreFallsBackToTheMarkerFileInsteadOfEmailingEveryRequest(): void
    {
        // REDIS_DSN unset → DI binds InMemoryKeyValueStore, fresh per FPM request.
        foreach (range(1, 3) as $request) {
            $this->monitor(recipients: [self::OWNER], store: new InMemoryKeyValueStore())
                ->recordFailure('messagecentral', $this->upstream(self::MC_508));
        }

        self::assertCount(1, $this->mailer->sent(), 'one email per cooldown per server, not one per request');
        self::assertCount(3, $this->reporter->reports, 'Sentry unthrottled without a shared store');
        self::assertCount(3, $this->logger->findByMessage('otp.alert.store_not_shared'), 'once per instance (= per request)');
    }

    #[Test]
    public function aSlowFailingMailServiceSkipsTheRemainingRecipients(): void
    {
        $mailer = new ClockAdvancingMailer(fn (int $s) => $this->now += $s, seconds: 15, fail: true);
        $this->monitor(mailer: $mailer, recipients: [self::OWNER, self::OPS, 'third@3bayti.test'])
            ->recordFailure('messagecentral', $this->upstream(self::MC_508));

        self::assertSame([self::OWNER], $mailer->attempts, 'a 15 s timeout-then-5xx is paid once, not per recipient');
        self::assertNotEmpty($this->logger->findByMessage('otp.alert.email_recipients_skipped'));
    }

    #[Test]
    public function slowSendsStopOnceTheTimeBudgetIsUsedUp(): void
    {
        $mailer = new ClockAdvancingMailer(fn (int $s) => $this->now += $s, seconds: 8, fail: false);
        $this->monitor(mailer: $mailer, recipients: [self::OWNER, self::OPS, 'third@3bayti.test'])
            ->recordFailure('messagecentral', $this->upstream(self::MC_508));

        self::assertSame([self::OWNER, self::OPS], $mailer->attempts, '0 s and 8 s start; 16 s is past the 15 s budget');
    }

    #[Test]
    public function theTestDrillTriesEveryRecipientEvenWhenMailIsDown(): void
    {
        $mailer = new FailingMailer(MailerException::KIND_NETWORK);
        $result = $this->monitor(mailer: $mailer, recipients: [self::OWNER, self::OPS])
            ->sendTestAlert('messagecentral', OtpProviderFailureClassifier::INSUFFICIENT_CREDIT);

        self::assertSame([self::OWNER, self::OPS], $mailer->attempts, 'nobody is waiting on the CLI: report each recipient');
        self::assertNotNull($result['results'][self::OPS]);
    }

    // ------------------------------------------------------------------
    // Privacy
    // ------------------------------------------------------------------

    #[Test]
    public function theEmailAndSentryNeverCarryCustomerDataOrSecrets(): void
    {
        $nasty = new OtpProviderException('upstream', 'HTTP 400: {"responseCode":508,"message":"insufficient credits",'
            . '"mobileNumber":"501234567","to":"+971 50 123 4567","otp":"482913","authToken":"eyJhbGciOiJIUzI1NiJ9.eyJzdWIiOiJDLTBCMTg2QiJ9.c2lnbmF0dXJl",'
            . '"email":"customer@example.com","url":"https://cpaas.messagecentral.com/auth/v1/authentication/token?customerId=C-0B186B587F924BB&key=SuperSecretKey123"}');

        $this->monitor(recipients: [self::OWNER])->recordFailure('messagecentral', $nasty);

        $email = $this->mailer->sent()[0];
        $haystacks = [
            $email['subject'],
            $email['text_body'],
            $email['html_body'],
            (string) json_encode($this->reporter->reports[0]),
        ];
        $forbidden = ['501234567', '+971 50 123 4567', '4567', '482913', 'eyJhbGci', 'customer@example.com', 'C-0B186B587F924BB', 'SuperSecretKey123'];
        foreach ($haystacks as $haystack) {
            foreach ($forbidden as $needle) {
                self::assertStringNotContainsString($needle, $haystack);
            }
        }
        // Still useful: the account signal survives redaction.
        self::assertStringContainsString('"responseCode":508', $email['text_body']);
        self::assertStringContainsString('insufficient credits', $email['text_body']);
    }

    // ------------------------------------------------------------------
    // Test drill (otp:test-alert)
    // ------------------------------------------------------------------

    #[Test]
    public function testAlertIgnoresTheCooldownAndLeavesTheLiveStateAlone(): void
    {
        $monitor = $this->monitor(recipients: [self::OWNER]);
        $monitor->recordFailure('messagecentral', $this->upstream(self::MC_508));
        $keysBefore = $this->store->keys();

        $result = $monitor->sendTestAlert('messagecentral', OtpProviderFailureClassifier::INSUFFICIENT_CREDIT);

        self::assertSame([self::OWNER => null], $result['results']);
        self::assertSame("[3bayti] [TEST] Action needed: customers can't get SMS codes - MessageCentral is out of credit", $result['subject']);
        self::assertCount(2, $this->mailer->sent());
        self::assertStringContainsString('THIS IS A TEST', $this->mailer->sent()[1]['text_body']);
        self::assertSame($keysBefore, $this->store->keys());
        self::assertCount(1, $this->reporter->reports, 'no Sentry event unless asked');
    }

    #[Test]
    public function testAlertCanReportASeparateSentryTestEvent(): void
    {
        $this->monitor(recipients: [self::OWNER])->sendTestAlert('messagecentral', OtpProviderFailureClassifier::PRICING_NOT_CONFIGURED, withSentry: true);

        self::assertCount(1, $this->reporter->reports);
        self::assertTrue($this->reporter->reports[0]['test']);
    }

    // ------------------------------------------------------------------

    /**
     * @param list<string> $recipients
     */
    private function monitor(
        ?MailerInterface $mailer = null,
        array $recipients = [self::OWNER, self::OPS],
        int $cooldown = 3600,
        ?string $fallbackDir = null,
        ?KeyValueStore $store = null,
    ): OtpProviderHealthMonitor {
        return new OtpProviderHealthMonitor(
            store: $store ?? $this->store,
            mailer: $mailer ?? $this->mailer,
            reporter: $this->reporter,
            config: new OtpAlertConfig($recipients, $cooldown),
            logger: $this->logger,
            clock: fn (): int => $this->now,
            fallbackDir: $fallbackDir ?? $this->dir,
        );
    }

    private function upstream(string $detail): OtpProviderException
    {
        return new OtpProviderException('upstream', $detail);
    }

    /**
     * The sent emails as 'Action needed' / 'Resolved', in order.
     *
     * @return list<string>
     */
    private function subjectKinds(): array
    {
        return array_map(
            static fn (array $m): string => str_contains($m['subject'], 'Resolved') ? 'Resolved' : 'Action needed',
            $this->mailer->sent(),
        );
    }
}
