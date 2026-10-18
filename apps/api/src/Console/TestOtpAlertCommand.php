<?php

declare(strict_types=1);

namespace Bayti\Api\Console;

use Bayti\Api\Infrastructure\Otp\OtpAlertConfig;
use Bayti\Api\Infrastructure\Otp\OtpProviderFailureClassifier;
use Bayti\Api\Infrastructure\Otp\OtpProviderHealthMonitor;
use Bayti\Api\Notification\MailerInterface;
use Bayti\Api\Notification\NullMailer;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * otp:test-alert — fire the SMS-provider alert email for real, WITHOUT a real
 * provider failure, so the owner can confirm the alert emails arrive.
 *
 *   php bin/console otp:test-alert
 *   php bin/console otp:test-alert --provider=messagecentral --reason=insufficient_credit
 *   php bin/console otp:test-alert --reason=pricing_not_configured
 *   php bin/console otp:test-alert --recovery            # the "working again" email
 *   php bin/console otp:test-alert --to=me@example.com    # just to you
 *   php bin/console otp:test-alert --with-sentry          # also a Sentry test event
 *
 * Sends to OTP_ALERT_EMAILS (else ADMIN_NOTIFICATION_EMAILS) — or --to —
 * IGNORING the alert cooldown, and without touching the live alert state, so
 * it is safe to run at any time, even during a real incident. The subject and
 * body are clearly marked [TEST]. --with-sentry reports a "[TEST]" event under
 * a separate fingerprint, so it never merges with (or resolves) a real issue.
 *
 * Exit: 0 every recipient accepted the email; 1 none configured / any failed;
 * 2 bad option.
 */
final class TestOtpAlertCommand extends Command
{
    public function __construct(
        private readonly OtpProviderHealthMonitor $monitor,
        private readonly MailerInterface $mailer,
        private readonly OtpAlertConfig $config,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->setName('otp:test-alert')
            ->setDescription('Send a TEST SMS-provider alert email (ignores the cooldown) to check alert emails arrive')
            ->addOption('provider', null, InputOption::VALUE_REQUIRED, 'Provider key (messagecentral, cequens)', 'messagecentral')
            ->addOption(
                'reason',
                null,
                InputOption::VALUE_REQUIRED,
                'One of: ' . implode(', ', OtpProviderFailureClassifier::REASONS),
                OtpProviderFailureClassifier::INSUFFICIENT_CREDIT,
            )
            ->addOption('recovery', null, InputOption::VALUE_NONE, 'Send the "SMS codes are working again" email instead')
            ->addOption('to', null, InputOption::VALUE_REQUIRED, 'Comma-separated recipients instead of the configured ones')
            ->addOption('with-sentry', null, InputOption::VALUE_NONE, 'Also report a [TEST] event to Sentry (separate issue)');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $provider = strtolower(trim((string) $input->getOption('provider')));
        $reason = strtolower(trim((string) $input->getOption('reason')));
        $recovery = (bool) $input->getOption('recovery');
        $withSentry = (bool) $input->getOption('with-sentry');

        if (preg_match('/^[a-z0-9_-]{1,40}$/', $provider) !== 1) {
            $output->writeln('<error>--provider must be a provider key like "messagecentral" or "cequens".</error>');
            return Command::INVALID;
        }
        if (!in_array($reason, OtpProviderFailureClassifier::REASONS, true)) {
            $output->writeln(sprintf(
                '<error>--reason must be one of: %s</error>',
                implode(', ', OtpProviderFailureClassifier::REASONS),
            ));
            return Command::INVALID;
        }

        $toOption = $input->getOption('to');
        $recipients = null;
        $source = $this->config->recipientSource;
        if (is_string($toOption) && trim($toOption) !== '') {
            $recipients = OtpAlertConfig::parseEmails($toOption);
            $source = '--to';
            if ($recipients === []) {
                $output->writeln('<error>--to contains no valid email address.</error>');
                return Command::INVALID;
            }
        }
        $effective = $recipients ?? $this->config->recipients;

        if ($effective === []) {
            $output->writeln('<error>No alert recipients configured.</error>');
            $output->writeln('Set OTP_ALERT_EMAILS (or ADMIN_NOTIFICATION_EMAILS) in the API .env, e.g.');
            $output->writeln('  OTP_ALERT_EMAILS=owner@3bayti.ae,ops@3bayti.ae');
            $output->writeln('then run this command again (or pass --to=you@example.com).');
            return Command::FAILURE;
        }

        $output->writeln(sprintf(
            'Sending a TEST %s email (provider=%s, reason=%s) to %d recipient(s) from %s, ignoring the cooldown...',
            $recovery ? 'recovery' : 'alert',
            $provider,
            $reason,
            count($effective),
            $source,
        ));

        $sent = $this->monitor->sendTestAlert($provider, $reason, $recovery, $recipients, $withSentry);
        $output->writeln('Subject: ' . $sent['subject']);

        $failed = 0;
        foreach ($sent['results'] as $to => $error) {
            if ($error === null) {
                $output->writeln("  OK      {$to}");
            } else {
                $failed++;
                $output->writeln("  FAILED  {$to}: {$error}");
            }
        }

        if ($this->mailer instanceof NullMailer) {
            $output->writeln('');
            $output->writeln('<comment>Note: email delivery is NOT configured in this environment (NullMailer) — the email was only written to the log.</comment>');
            $output->writeln('<comment>On the production server (APP_ENV=prod + ZEPTOMAIL_API_TOKEN) it is delivered for real.</comment>');
        }
        if ($withSentry && !$recovery) {
            $output->writeln('A [TEST] Sentry event was reported (no-op when SENTRY_DSN is unset).');
        }

        if ($failed > 0) {
            $output->writeln(sprintf('<error>%d of %d email(s) failed — check the mail settings (ZEPTOMAIL_*) and the API log.</error>', $failed, count($sent['results'])));
            return Command::FAILURE;
        }

        $output->writeln('Done. Check the inbox (and spam folder) of each recipient.');
        return Command::SUCCESS;
    }
}
