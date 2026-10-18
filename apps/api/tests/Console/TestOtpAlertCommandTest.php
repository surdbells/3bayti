<?php

declare(strict_types=1);

namespace Bayti\Api\Tests\Console;

use Bayti\Api\Console\TestOtpAlertCommand;
use Bayti\Api\Infrastructure\Otp\OtpAlertConfig;
use Bayti\Api\Infrastructure\Otp\OtpProviderException;
use Bayti\Api\Infrastructure\Otp\OtpProviderHealthMonitor;
use Bayti\Api\Notification\InMemoryMailer;
use Bayti\Api\Notification\MailerException;
use Bayti\Api\Notification\MailerInterface;
use Bayti\Api\Notification\NullMailer;
use Bayti\Api\Tests\Support\Otp\FailingMailer;
use Bayti\Api\Tests\Support\Otp\FakeClockKeyValueStore;
use Bayti\Api\Tests\Support\Otp\RecordingOtpIncidentReporter;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

#[CoversClass(TestOtpAlertCommand::class)]
final class TestOtpAlertCommandTest extends TestCase
{
    private FakeClockKeyValueStore $store;
    private RecordingOtpIncidentReporter $reporter;

    protected function setUp(): void
    {
        parent::setUp();
        // A shared-store double: the per-process InMemoryKeyValueStore is treated
        // as "not shared" by the monitor (marker-file fallback).
        $this->store = new FakeClockKeyValueStore(static fn (): int => time());
        $this->reporter = new RecordingOtpIncidentReporter();
    }

    #[Test]
    public function sendsATestAlertToTheConfiguredRecipientsEvenDuringTheCooldown(): void
    {
        $mailer = new InMemoryMailer();
        $config = new OtpAlertConfig(['owner@3bayti.test', 'ops@3bayti.test']);
        $monitor = $this->monitor($mailer, $config);
        // A real alert just went out → the cooldown is claimed.
        $monitor->recordFailure('messagecentral', new OtpProviderException('upstream', 'HTTP 400: {"responseCode":508,"message":"insufficient credits"}'));
        self::assertCount(2, $mailer->sent());

        $tester = new CommandTester(new TestOtpAlertCommand($monitor, $mailer, $config));
        $exit = $tester->execute(['--provider' => 'messagecentral', '--reason' => 'insufficient_credit']);

        self::assertSame(Command::SUCCESS, $exit);
        $sent = array_slice($mailer->sent(), 2);
        self::assertSame(['owner@3bayti.test', 'ops@3bayti.test'], array_column($sent, 'to'));
        self::assertStringStartsWith('[3bayti] [TEST] Action needed', $sent[0]['subject']);
        self::assertStringContainsString('THIS IS A TEST', $sent[0]['text_body']);
        self::assertTrue($sent[0]['context']['test']);

        $display = $tester->getDisplay();
        self::assertStringContainsString('from OTP_ALERT_EMAILS', $display);
        self::assertStringContainsString('OK      owner@3bayti.test', $display);
        self::assertStringContainsString('OK      ops@3bayti.test', $display);
        self::assertCount(1, $this->reporter->reports, 'no Sentry test event unless --with-sentry');
    }

    #[Test]
    public function recoveryOptionSendsTheWorkingAgainEmail(): void
    {
        $mailer = new InMemoryMailer();
        $config = new OtpAlertConfig(['owner@3bayti.test']);
        $tester = new CommandTester(new TestOtpAlertCommand($this->monitor($mailer, $config), $mailer, $config));

        self::assertSame(Command::SUCCESS, $tester->execute(['--recovery' => true]));
        self::assertSame('[3bayti] [TEST] Resolved: SMS codes are working again (MessageCentral)', $mailer->sent()[0]['subject']);
    }

    #[Test]
    public function toOverridesTheRecipientsAndWithSentryReportsASeparateTestEvent(): void
    {
        $mailer = new InMemoryMailer();
        $config = new OtpAlertConfig(['owner@3bayti.test']);
        $tester = new CommandTester(new TestOtpAlertCommand($this->monitor($mailer, $config), $mailer, $config));

        $exit = $tester->execute(['--reason' => 'pricing_not_configured', '--to' => 'me@example.test', '--with-sentry' => true]);

        self::assertSame(Command::SUCCESS, $exit);
        self::assertSame(['me@example.test'], array_column($mailer->sent(), 'to'));
        self::assertStringContainsString('has no SMS pricing set up', $mailer->sent()[0]['subject']);
        self::assertCount(1, $this->reporter->reports);
        self::assertTrue($this->reporter->reports[0]['test']);
        self::assertStringContainsString('from --to', $tester->getDisplay());
    }

    #[Test]
    public function rejectsAnUnknownReason(): void
    {
        $mailer = new InMemoryMailer();
        $config = new OtpAlertConfig(['owner@3bayti.test']);
        $tester = new CommandTester(new TestOtpAlertCommand($this->monitor($mailer, $config), $mailer, $config));

        self::assertSame(Command::INVALID, $tester->execute(['--reason' => 'bogus']));
        self::assertSame([], $mailer->sent());
        self::assertStringContainsString('insufficient_credit, pricing_not_configured, credentials_rejected, no_provider', $tester->getDisplay());
    }

    #[Test]
    public function failsWithAHintWhenNoRecipientsAreConfigured(): void
    {
        $mailer = new InMemoryMailer();
        $config = new OtpAlertConfig([]);
        $tester = new CommandTester(new TestOtpAlertCommand($this->monitor($mailer, $config), $mailer, $config));

        self::assertSame(Command::FAILURE, $tester->execute([]));
        self::assertStringContainsString('OTP_ALERT_EMAILS', $tester->getDisplay());
        self::assertSame([], $mailer->sent());
    }

    #[Test]
    public function reportsAFailedSendWithANonZeroExit(): void
    {
        $mailer = new FailingMailer(MailerException::KIND_AUTH);
        $config = new OtpAlertConfig(['owner@3bayti.test']);
        $tester = new CommandTester(new TestOtpAlertCommand($this->monitor($mailer, $config), $mailer, $config));

        self::assertSame(Command::FAILURE, $tester->execute([]));
        self::assertStringContainsString('FAILED  owner@3bayti.test', $tester->getDisplay());
    }

    #[Test]
    public function warnsWhenMailIsNotConfiguredInThisEnvironment(): void
    {
        $mailer = new NullMailer();
        $config = new OtpAlertConfig(['owner@3bayti.test']);
        $tester = new CommandTester(new TestOtpAlertCommand($this->monitor($mailer, $config), $mailer, $config));

        self::assertSame(Command::SUCCESS, $tester->execute([]));
        self::assertStringContainsString('NullMailer', $tester->getDisplay());
    }

    private function monitor(MailerInterface $mailer, OtpAlertConfig $config): OtpProviderHealthMonitor
    {
        return new OtpProviderHealthMonitor(
            store: $this->store,
            mailer: $mailer,
            reporter: $this->reporter,
            config: $config,
        );
    }
}
