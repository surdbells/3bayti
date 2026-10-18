<?php

declare(strict_types=1);

namespace Bayti\Api\Infrastructure\Otp;

use Sentry\Event;
use Sentry\Severity;
use Sentry\State\Scope;

/**
 * Sentry adapter for OtpProviderIncidentReporter.
 *
 * Captures a MESSAGE event (not an exception) in an isolated scope with a
 * stable fingerprint ['otp-provider-account', <provider>, <reason>], so every
 * occurrence of e.g. "messagecentral out of credit" lands in ONE clearly named
 * Sentry issue instead of being buried among generic 502s. Level is `fatal`
 * when customers were blocked (no backup delivered the code) and `error` when a
 * backup provider covered it.
 *
 * Message events pass Bootstrap's before_send filter untouched (it only drops
 * expected 4xx EXCEPTIONS). No-op when Sentry isn't initialised (no SENTRY_DSN:
 * tests / local dev), same as every other capture in the app.
 *
 * Privacy: this is a provider-level incident, not a customer's, so the event
 * carries NO customer data:
 *   - the scoped user (set by AuthMiddleware for signed-in requests) is removed;
 *   - the HTTP request is stripped. The SDK's default RequestIntegration (a
 *     GLOBAL event processor) attaches the request url, query string and BODY
 *     — on the OTP endpoints that is the customer's phone (and sometimes email)
 *     — to every event, message events included. Scope::applyToEvent runs the
 *     global processors first and the scope's own after, so a scope processor
 *     added here runs last and removes it;
 *   - breadcrumbs are cleared (defensive: nothing customer-specific belongs here);
 *   - the monitor only ever passes already-redacted details.
 */
final class SentryOtpProviderIncidentReporter implements OtpProviderIncidentReporter
{
    public const FINGERPRINT_ROOT = 'otp-provider-account';

    public function reportAccountFailure(
        string $provider,
        string $reason,
        string $message,
        bool $customersBlocked,
        array $details = [],
        bool $test = false,
    ): void {
        $level = $customersBlocked ? Severity::fatal() : Severity::error();

        $fingerprint = [self::FINGERPRINT_ROOT, $provider, $reason];
        if ($test) {
            // A drill must never merge into (or resolve) the real incident issue.
            $fingerprint[] = 'test';
        }

        $tags = [
            'provider' => $provider,
            'reason' => $reason,
            'customers_blocked' => $customersBlocked ? 'yes' : 'no',
        ];
        $backup = $details['backup_provider'] ?? null;
        if (is_string($backup) && $backup !== '') {
            $tags['backup_provider'] = $backup;
        }
        $destination = $details['destination'] ?? null;
        if (is_string($destination) && OtpDestination::isValid($destination)) {
            $tags['destination'] = '+' . $destination;
        }
        if ($test) {
            $tags['otp_alert_test'] = 'yes';
        }

        \Sentry\withScope(static function (Scope $scope) use ($fingerprint, $level, $tags, $details, $message, $test): void {
            $scope->removeUser();
            $scope->clearBreadcrumbs();
            // Runs AFTER the global RequestIntegration processor (see class doc).
            $scope->addEventProcessor(static function (Event $event): Event {
                $event->setRequest([]);
                return $event;
            });
            $scope->setFingerprint($fingerprint);
            $scope->setLevel($level);
            $scope->setTags($tags);
            $scope->setContext('otp_provider_incident', $details);
            \Sentry\captureMessage($test ? '[TEST] ' . $message : $message, $level);
        });
    }
}
