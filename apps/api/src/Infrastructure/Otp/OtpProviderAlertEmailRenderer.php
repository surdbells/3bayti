<?php

declare(strict_types=1);

namespace Bayti\Api\Infrastructure\Otp;

use Bayti\Api\Notification\RenderedEmail;

/**
 * Renders the SMS-provider alert and recovery emails (subject + plain text +
 * HTML) for the admin team.
 *
 * Audience: a NON-technical owner. The copy says, in plain words, which
 * provider, what is wrong, what customers can't do, whether a backup provider
 * covered it, what to do, and since when. Technical detail (reason code, the
 * provider's raw reply) is kept to a short "Details" block at the end.
 *
 * Privacy: the only provider-originated text in the email is the raw reply,
 * which goes through redactReply() first (phone numbers / long digit runs such
 * as OTP codes / emails / tokens / API keys / URL query strings removed). The
 * monitor never has a customer's phone, code or user id to begin with.
 *
 * Pure: no I/O. Times are shown in UAE time with UTC alongside.
 */
final class OtpProviderAlertEmailRenderer
{
    private const SUBJECT_PREFIX = '[3bayti] ';
    private const TIMEZONE = 'Asia/Dubai';
    private const MAX_REPLY_CHARS = 300;

    private const CUSTOMER_ACTIONS = [
        'sign up for a new account',
        'log in with a phone code',
        'verify their phone number',
        'reset their password by SMS',
    ];

    /**
     * Plain-language copy per reason. %1$s = provider display name.
     *
     * @var array<string, array{short: string, problem: string, actions: list<string>}>
     */
    private const COPY = [
        OtpProviderFailureClassifier::INSUFFICIENT_CREDIT => [
            'short' => 'out of credit',
            'problem' => '%1$s refused to send a login/verification code because the account has run out of credit (the balance is empty).',
            'actions' => [
                'Log in to your %1$s account and top up the balance.',
                'Nothing needs to change on the server: as soon as there is credit, codes start sending again automatically.',
                'To stop this happening again, switch on automatic top-up (auto-recharge) or a low-balance email alert in the %1$s account, if it offers one.',
            ],
        ],
        // Destination unknown. With a known destination country see PRICING_FOR_DESTINATION.
        OtpProviderFailureClassifier::PRICING_NOT_CONFIGURED => [
            'short' => 'SMS pricing not set up on the account',
            'problem' => '%1$s refused to send a login/verification code because SMS pricing is not set up on the account for the country or channel we sent to (the provider says the price or route is missing). This can affect every customer, or only customers in one country.',
            'actions' => [
                'Contact %1$s support and ask them to enable and price SMS verification (OTP) codes on our account for the UAE and the other countries we serve.',
                'Nothing needs to change on the server once they have done this.',
            ],
        ],
        OtpProviderFailureClassifier::CREDENTIALS_REJECTED => [
            'short' => 'credentials rejected',
            'problem' => '%1$s rejected our account login (API key / account credentials), or the account has been suspended.',
            'actions' => [
                'Log in to your %1$s account and check that it is active (not suspended or expired).',
                'Ask your developer to check that the %1$s API credentials in the server settings match the ones in the %1$s account, update them if they were changed, and restart the API.',
            ],
        ],
        OtpProviderFailureClassifier::NO_PROVIDER => [
            'short' => 'no SMS provider available',
            'problem' => 'The server has no SMS provider it can use to send login/verification codes (the provider credentials are missing from the server settings).',
            'actions' => [
                'Ask your developer to check the SMS provider settings (the MessageCentral credentials) on the server and restart the API.',
                'In the admin portal, open Settings > OTP Providers and make sure at least one provider is switched on.',
            ],
        ],
    ];

    /**
     * pricing_not_configured when the destination country is known (the usual
     * case: MessageCentral prices SMS per country). %1$s = provider, %2$s =
     * destination label ("+973 (Bahrain)").
     *
     * @var array{short: string, problem: string, actions: list<string>}
     */
    private const PRICING_FOR_DESTINATION = [
        'short' => 'SMS pricing not set up for %2$s numbers',
        'problem' => '%1$s refused to send a login/verification code to a %2$s number because SMS pricing is not set up on our account for that country. This affects customers with %2$s numbers; codes to other countries can still work.',
        'actions' => [
            'Contact %1$s support and ask them to enable and price SMS verification (OTP) codes to %2$s numbers on our account.',
            'Nothing needs to change on the server once they have done this.',
        ],
    ];

    /**
     * The alert email: an account problem was detected.
     *
     * @param string|null $handledBy  backup provider that delivered the code instead, or null
     * @param int|null    $startedAt  first failure of this alert window (unix), null if unknown
     * @param int|null    $failedAttempts code requests that failed so far, null if unknown
     * @param string      $providerReply the provider's raw reply (redacted here again, defensively)
     * @param string|null $destination destination dial code ("973") — only used
     *                                 for pricing_not_configured, which is per country
     */
    public function renderAlert(
        string $provider,
        string $reason,
        ?string $handledBy,
        ?int $startedAt,
        int $occurredAt,
        ?int $failedAttempts,
        string $providerReply,
        int $cooldownSeconds,
        bool $test = false,
        ?string $destination = null,
    ): RenderedEmail {
        $name = self::providerName($provider);
        $place = self::destinationLabel($reason, $destination);
        $copy = self::copy($reason, $name, $place);
        $short = $copy['short'];
        $isNoProvider = $reason === OtpProviderFailureClassifier::NO_PROVIDER;
        $backup = $handledBy !== null ? self::providerName($handledBy) : null;
        $who = self::affectedCustomers($reason, $place);

        $headline = $isNoProvider ? 'no SMS provider is available' : "{$name} is {$short}";
        if ($reason === OtpProviderFailureClassifier::PRICING_NOT_CONFIGURED) {
            $headline = $place !== null
                ? "{$name} has no SMS pricing for {$place} numbers"
                : "{$name} has no SMS pricing set up";
        } elseif ($reason === OtpProviderFailureClassifier::CREDENTIALS_REJECTED) {
            $headline = "{$name} rejected our credentials";
        }

        $subject = $backup === null
            ? "Action needed: {$who} can't get SMS codes - " . ($place !== null ? "{$name} has no SMS pricing for that country" : $headline)
            : "Action needed: {$headline} (backup {$backup} is sending codes)";

        $blocks = [];
        if ($test) {
            $blocks[] = ['p', 'THIS IS A TEST of the SMS-provider alert, sent with `otp:test-alert`. Nothing is actually wrong. If you can read this, alert emails reach you.'];
        }
        $blocks[] = ['p', 'Hello,'];
        $blocks[] = ['p', 'This is an automatic alert from the 3bayti API about the SMS provider that sends customers their login and verification codes.'];

        $blocks[] = ['h', "What's wrong"];
        $blocks[] = ['p', $copy['problem']];

        $blocks[] = ['h', 'What this means for customers'];
        if ($backup === null) {
            $blocks[] = ['p', $isNoProvider
                ? 'No code could be sent. Until this is fixed, customers cannot:'
                : "There is no working backup SMS provider, so the customer did NOT get their code. Until this is fixed, {$who} cannot:"];
            $blocks[] = ['ul', self::CUSTOMER_ACTIONS];
        } else {
            $codes = $place !== null ? "every code to {$place} numbers" : 'every code';
            $blocks[] = ['p', "Customers were NOT affected this time: the backup provider {$backup} sent the code instead. But {$backup} is now handling {$codes} with nothing behind it, so please fix {$name} soon. If {$backup} also fails, {$who} will not be able to:"];
            $blocks[] = ['ul', self::CUSTOMER_ACTIONS];
        }

        $blocks[] = ['h', 'What to do'];
        $blocks[] = ['ol', $copy['actions']];
        $blocks[] = ['p', $place !== null
            ? "You will get a \"SMS codes are working again\" email as soon as a code to a {$place} number is sent successfully again."
            : 'You will get a "SMS codes are working again" email as soon as a code is sent successfully again.'];

        $details = [
            'Provider' => $isNoProvider ? 'none available' : $name,
            'Problem' => "{$short} ({$reason})",
            'Started' => $startedAt !== null ? self::formatTime($startedAt) : 'unknown - first noticed ' . self::formatTime($occurredAt),
            'This alert' => self::formatTime($occurredAt),
        ];
        if ($place !== null) {
            $details['Destination'] = "{$place} numbers";
        }
        if ($failedAttempts !== null) {
            $details['Failed code requests'] = $failedAttempts . ' so far';
        }
        $details['Backup provider'] = $backup !== null
            ? "{$backup} - delivered the code instead"
            : "none - the customer's request failed";
        $details['Provider reply'] = self::redactReply($providerReply);

        $blocks[] = ['h', 'Details'];
        $blocks[] = ['dl', $details];
        $blocks[] = ['p', sprintf(
            'You will get at most one alert %s about this problem while it continues. It is also tracked in Sentry as "%s"%s.',
            self::per($cooldownSeconds),
            self::sentryMessage($provider, $reason),
            $backup !== null ? " (this time the backup {$backup} covered the customers)" : '',
        )];

        return self::build(($test ? '[TEST] ' : '') . $subject, $blocks);
    }

    /**
     * The recovery email: a provider sent a code successfully after an alert.
     *
     * @param string      $provider    the provider that had the problem (or NO_PROVIDER_KEY)
     * @param string      $recoveredBy the provider whose successful send cleared it
     * @param string|null $destination destination dial code of a per-country pricing alert
     */
    public function renderRecovery(
        string $provider,
        string $reason,
        string $recoveredBy,
        ?int $startedAt,
        int $recoveredAt,
        ?int $failedAttempts,
        bool $test = false,
        ?string $destination = null,
    ): RenderedEmail {
        $isNoProvider = $reason === OtpProviderFailureClassifier::NO_PROVIDER;
        $name = $isNoProvider ? self::providerName($recoveredBy) : self::providerName($provider);
        $place = self::destinationLabel($reason, $destination);
        $short = self::copy($reason, $name, $place)['short'];

        $subject = $place !== null
            ? "Resolved: SMS codes to {$place} numbers are working again ({$name})"
            : "Resolved: SMS codes are working again ({$name})";

        $blocks = [];
        if ($test) {
            $blocks[] = ['p', 'THIS IS A TEST of the SMS-provider recovery email, sent with `otp:test-alert --recovery`. Nothing was actually wrong.'];
        }
        $blocks[] = ['p', 'Hello,'];
        $blocks[] = ['p', $place !== null
            ? "Good news: {$name} just sent a login/verification code to a {$place} number successfully, so SMS codes to {$place} numbers are working again."
            : "Good news: {$name} just sent a login/verification code successfully, so SMS codes are working again."];
        $blocks[] = ['p', 'The earlier problem was: ' . ($isNoProvider ? $short : "{$name} {$short}") . '.'];

        $details = [];
        if ($startedAt !== null) {
            $details['Started'] = self::formatTime($startedAt);
        }
        $details['Working again since'] = self::formatTime($recoveredAt);
        if ($startedAt !== null && $recoveredAt >= $startedAt) {
            $details['Duration'] = self::duration($recoveredAt - $startedAt);
        }
        if ($failedAttempts !== null) {
            $details['Failed code requests'] = $failedAttempts . ' while it was broken';
        }
        $blocks[] = ['dl', $details];

        $blocks[] = ['p', ($place !== null ? "Customers with {$place} numbers" : 'Customers') . ' can again ' . self::joinActions() . '. Customers who tried while it was broken did not get a code; they just need to request a new one.'];
        $blocks[] = ['p', sprintf(
            'Nothing else to do. If you follow it in Sentry, you can mark the issue "%s" as resolved.',
            self::sentryMessage($provider, $reason),
        )];

        return self::build(($test ? '[TEST] ' : '') . $subject, $blocks);
    }

    /**
     * The one Sentry issue title per provider + reason (also quoted in the emails).
     */
    public static function sentryMessage(string $provider, string $reason): string
    {
        if ($reason === OtpProviderFailureClassifier::NO_PROVIDER) {
            return 'No SMS provider available — customers cannot receive login/verification codes';
        }
        if ($reason === OtpProviderFailureClassifier::PRICING_NOT_CONFIGURED) {
            // Per destination country (tagged `destination` on the event), so
            // not "customers" in general.
            return sprintf(
                'SMS provider %s: SMS pricing not set up — affected customers cannot receive login/verification codes',
                $provider,
            );
        }
        return sprintf(
            'SMS provider %s: %s — customers cannot receive login/verification codes',
            $provider,
            self::copy($reason)['short'],
        );
    }

    public static function providerName(string $provider): string
    {
        return match ($provider) {
            'messagecentral' => 'MessageCentral',
            'cequens' => 'Cequens',
            OtpProviderHealthObserver::NO_PROVIDER_KEY => 'No provider',
            default => ucfirst($provider),
        };
    }

    /**
     * Make a provider reply safe to email / send to Sentry, then truncate it.
     *
     * Removes anything that could identify a customer or leak a secret:
     * URL query strings (MessageCentral's auth URL carries the API key),
     * key/token/password-style fields, Bearer tokens and JWTs, email
     * addresses, phone numbers and every run of 4+ digits (phone numbers,
     * OTP codes, verification ids). Short codes like "HTTP 400" or
     * "responseCode":508 are kept — they're what makes the reply useful.
     */
    public static function redactReply(string $raw, int $max = self::MAX_REPLY_CHARS): string
    {
        $s = $raw;
        $rules = [
            // URL query strings → may carry key= / customerId= / email=.
            '~(https?://[^\s"\'<>?]+)\?[^\s"\'<>]*~i' => '$1?[redacted]',
            // "authToken":"…", key=…, apiKey: …, password, secret, token(s).
            '~((?:"|\b)(?:api[_-]?key|key|auth[_-]?token|access[_-]?token|token|password|passwd|secret|authorization)"?\s*[:=]\s*)("?)[^"\s,&}]+~i' => '$1$2[redacted]',
            '~\bBearer\s+[A-Za-z0-9._+/=\~-]+~i' => 'Bearer [redacted]',
            '~\beyJ[A-Za-z0-9_-]+\.[A-Za-z0-9_-]+(?:\.[A-Za-z0-9_-]+)?~' => '[redacted-token]',
            '~[A-Z0-9._%+-]+@[A-Z0-9.-]+\.[A-Z]{2,}~i' => '[redacted-email]',
            // International numbers written with separators: +971 50 123 4567.
            '~\+\d[\d\s().-]{5,}\d~' => '[redacted-number]',
            // Any remaining run of 4+ digits (bare phone numbers, OTP codes, ids).
            '~\d{4,}~' => '[redacted-number]',
        ];
        foreach ($rules as $pattern => $replacement) {
            $s = preg_replace($pattern, $replacement, $s) ?? '[reply removed]';
        }

        $s = trim(preg_replace('/\s+/', ' ', $s) ?? '');
        if (mb_strlen($s) > $max) {
            $s = mb_substr($s, 0, $max) . '...';
        }
        return $s === '' ? '(empty)' : $s;
    }

    // ---------------------------------------------------------------------

    /**
     * The copy for $reason with the provider name (and destination) filled in.
     *
     * @return array{short: string, problem: string, actions: list<string>}
     */
    private static function copy(string $reason, string $name = '', ?string $place = null): array
    {
        $copy = ($reason === OtpProviderFailureClassifier::PRICING_NOT_CONFIGURED && $place !== null)
            ? self::PRICING_FOR_DESTINATION
            : (self::COPY[$reason] ?? [
                'short' => str_replace('_', ' ', $reason),
                'problem' => '%1$s refused to send a login/verification code because of a problem with the account.',
                'actions' => ['Log in to your %1$s account and check its status, or ask your developer to look at the error in Sentry.'],
            ]);

        $fill = static fn (string $s): string => sprintf($s, $name, (string) $place);
        return [
            'short' => $fill($copy['short']),
            'problem' => $fill($copy['problem']),
            'actions' => array_map($fill, $copy['actions']),
        ];
    }

    /** "+973 (Bahrain)" for a per-country pricing problem, else null. */
    private static function destinationLabel(string $reason, ?string $destination): ?string
    {
        if ($reason !== OtpProviderFailureClassifier::PRICING_NOT_CONFIGURED || !OtpDestination::isValid($destination)) {
            return null;
        }
        return OtpDestination::label((string) $destination);
    }

    /** Who can't get codes: everyone, or only one country's customers (pricing). */
    private static function affectedCustomers(string $reason, ?string $place): string
    {
        if ($place !== null) {
            return "customers with {$place} numbers";
        }
        return $reason === OtpProviderFailureClassifier::PRICING_NOT_CONFIGURED ? 'some or all customers' : 'customers';
    }

    private static function joinActions(): string
    {
        $actions = self::CUSTOMER_ACTIONS;
        $last = array_pop($actions);
        return implode(', ', $actions) . ' and ' . $last;
    }

    private static function formatTime(int $timestamp): string
    {
        $utc = new \DateTimeImmutable('@' . $timestamp);
        $local = $utc->setTimezone(new \DateTimeZone(self::TIMEZONE));
        return $local->format('D j M Y, H:i') . ' UAE time (' . $utc->format('H:i') . ' UTC)';
    }

    private static function duration(int $seconds): string
    {
        if ($seconds < 60) {
            return 'less than a minute';
        }
        $days = intdiv($seconds, 86400);
        $hours = intdiv($seconds % 86400, 3600);
        $minutes = intdiv($seconds % 3600, 60);

        $parts = [];
        if ($days > 0) {
            $parts[] = $days . ' ' . ($days === 1 ? 'day' : 'days');
        }
        if ($hours > 0) {
            $parts[] = $hours . ' ' . ($hours === 1 ? 'hour' : 'hours');
        }
        if ($days === 0 && $minutes > 0) {
            $parts[] = $minutes . ' ' . ($minutes === 1 ? 'minute' : 'minutes');
        }
        return 'about ' . implode(' ', $parts);
    }

    private static function per(int $seconds): string
    {
        if ($seconds % 3600 === 0) {
            $h = intdiv($seconds, 3600);
            return $h === 1 ? 'per hour' : "every {$h} hours";
        }
        $m = max(1, intdiv($seconds, 60));
        return $m === 1 ? 'per minute' : "every {$m} minutes";
    }

    /**
     * @param list<array{0: string, 1: string|list<string>|array<string, string>}> $blocks
     */
    private static function build(string $subject, array $blocks): RenderedEmail
    {
        $text = [];
        $html = [];
        foreach ($blocks as [$type, $content]) {
            switch ($type) {
                case 'h':
                    $title = is_string($content) ? $content : '';
                    $text[] = strtoupper($title);
                    $html[] = '<h3 style="margin:20px 0 6px;font-size:15px;">' . self::e($title) . '</h3>';
                    break;
                case 'p':
                    $para = is_string($content) ? $content : '';
                    $text[] = $para . "\n";
                    $html[] = '<p style="margin:0 0 10px;">' . self::e($para) . '</p>';
                    break;
                case 'ul':
                case 'ol':
                    $items = is_array($content) ? array_values($content) : [];
                    $lines = [];
                    $lis = [];
                    foreach ($items as $i => $item) {
                        $lines[] = ($type === 'ol' ? '  ' . ($i + 1) . '. ' : '  - ') . $item;
                        $lis[] = '<li>' . self::e($item) . '</li>';
                    }
                    $text[] = implode("\n", $lines) . "\n";
                    $html[] = "<{$type} style=\"margin:0 0 10px;\">" . implode('', $lis) . "</{$type}>";
                    break;
                case 'dl':
                    $rows = is_array($content) ? $content : [];
                    $width = 0;
                    foreach (array_keys($rows) as $label) {
                        $width = max($width, strlen((string) $label) + 1);
                    }
                    $lines = [];
                    $trs = [];
                    foreach ($rows as $label => $value) {
                        $lines[] = '  ' . str_pad($label . ':', $width + 2) . $value;
                        $trs[] = '<tr><td style="padding:2px 12px 2px 0;color:#555;vertical-align:top;white-space:nowrap;">'
                            . self::e((string) $label) . '</td><td style="padding:2px 0;">' . self::e((string) $value) . '</td></tr>';
                    }
                    $text[] = implode("\n", $lines) . "\n";
                    $html[] = '<table style="border-collapse:collapse;margin:0 0 10px;font-size:14px;">' . implode('', $trs) . '</table>';
                    break;
            }
        }

        $textBody = rtrim(implode("\n", $text)) . "\n";
        $htmlBody = '<div style="font-family:Arial,Helvetica,sans-serif;font-size:14px;line-height:1.5;color:#222;max-width:640px;">'
            . implode("\n", $html) . '</div>';

        return new RenderedEmail(self::SUBJECT_PREFIX . $subject, $textBody, $htmlBody);
    }

    private static function e(string $s): string
    {
        return htmlspecialchars($s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}
