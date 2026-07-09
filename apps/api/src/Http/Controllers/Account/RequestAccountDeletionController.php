<?php

declare(strict_types=1);

namespace Bayti\Api\Http\Controllers\Account;

use Bayti\Api\Http\Controllers\Account\Dto\RequestAccountDeletionInput;
use Bayti\Api\Http\Errors\HttpException;
use Bayti\Api\Http\RequestContext;
use Bayti\Api\Http\Responder;
use Bayti\Api\Http\Validator\RequestValidator;
use Bayti\Api\Infrastructure\Cache\KeyValueStore;
use Bayti\Api\Infrastructure\Cache\KeyValueStoreException;
use Bayti\Api\Notification\MailerException;
use Bayti\Api\Notification\MailerInterface;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Log\LoggerInterface;

/**
 * POST /v3/account/deletion-request  (PUBLIC — no auth)
 *
 * A user requests deletion of their account + associated data from the
 * public web. Google Play requires a publicly reachable (no login) URL
 * where users can request account + data deletion; the authenticated
 * DELETE /v3/me can't serve that because (a) it needs a valid session
 * and (b) social-only accounts (no password) can't self-authenticate a
 * destructive call. This endpoint accepts the request and emails ops to
 * process it manually.
 *
 * Anti-enumeration
 * ----------------
 * We NEVER reveal whether the submitted email matches an account. Any
 * well-formed request returns 202 { "status": "received" } — identical
 * whether or not an account exists. (Mirrors the vendor-application and
 * OTP-send controllers.)
 *
 * Anti-spam
 * ---------
 * Throttled at two axes via KeyValueStore (Redis):
 *   - per-IP  (CF-Connecting-IP):  short cooldown + hourly cap
 *   - per-email:                    short cooldown + hourly cap
 * A Redis outage degrades to "allow" (fail-open) rather than blocking a
 * legitimate user — mirrors SubmitVendorApplicationController.
 *
 * Ops notification
 * ----------------
 * Emails the addresses in ADMIN_NOTIFICATION_EMAILS (comma-separated).
 * Entirely best-effort: a mailer failure NEVER fails the HTTP response.
 */
final class RequestAccountDeletionController
{
    use Responder;
    use RequestContext;

    // Cooldown: at most one submit per axis per this many seconds.
    private const COOLDOWN_SECONDS = 60;
    // Hourly cap per axis.
    private const HOUR_SECONDS = 3600;
    private const PER_IP_HOURLY_CAP = 10;
    private const PER_EMAIL_HOURLY_CAP = 3;

    /**
     * @param list<string> $adminRecipients Parsed ADMIN_NOTIFICATION_EMAILS.
     */
    public function __construct(
        protected readonly ResponseFactoryInterface $responseFactory,
        private readonly RequestValidator $validator,
        private readonly KeyValueStore $cache,
        private readonly MailerInterface $mailer,
        private readonly LoggerInterface $logger,
        private readonly array $adminRecipients,
    ) {
    }

    protected function getResponseFactory(): ResponseFactoryInterface
    {
        return $this->responseFactory;
    }

    public function __invoke(ServerRequestInterface $request): ResponseInterface
    {
        $input = $this->validator->parse($request, RequestAccountDeletionInput::class);

        $ip = $this->extractIp($request);

        // Anti-spam throttle (per-IP + per-email). Throws 429 if tripped.
        $this->enforceRateLimits($ip, $input->email);

        // Best-effort ops notification. Anti-enumeration: we do NOT look
        // up the account here, so the response is identical whether or
        // not the email matches a real user.
        $this->notifyOps($input, $ip);

        // Always 202 for a well-formed request — no account disclosure.
        $response = $this->getResponseFactory()->createResponse(202);
        $response->getBody()->write('{"status":"received"}');
        return $response->withHeader('Content-Type', 'application/json');
    }

    /**
     * Per-IP + per-email cooldown and hourly caps. Each axis is
     * independent. A Redis outage degrades to "allow" rather than block.
     *
     * @throws HttpException 429 when a limit trips.
     */
    private function enforceRateLimits(?string $ip, string $email): void
    {
        // Per-email (always available — email is in the body).
        $this->enforceCooldown('acctdel:cd:email:' . $email);
        $this->enforceHourlyCap('acctdel:rl:email:' . $email, self::PER_EMAIL_HOURLY_CAP);

        // Per-IP (skipped entirely when the IP can't be resolved — never
        // block a legit user because we couldn't read their IP).
        if ($ip !== null && $ip !== '') {
            $this->enforceCooldown('acctdel:cd:ip:' . $ip);
            $this->enforceHourlyCap('acctdel:rl:ip:' . $ip, self::PER_IP_HOURLY_CAP);
        }
    }

    /**
     * Short cooldown via Redis SET NX EX. The FIRST caller in the window
     * claims the key (proceeds); a subsequent caller inside the window
     * fails the claim → 429. Redis error → allow (degrade).
     */
    private function enforceCooldown(string $key): void
    {
        try {
            $claimed = $this->cache->setIfAbsent($key, (string) time(), self::COOLDOWN_SECONDS);
            if ($claimed === false) {
                throw HttpException::rateLimited(
                    message: 'Please wait a moment before submitting another request.',
                );
            }
        } catch (KeyValueStoreException $e) {
            $this->logger->warning('account-deletion cooldown cache failure — allowing', [
                'key' => $key,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Hourly cap via atomic INCR + first-hit TTL. Over the cap → 429.
     * Redis error → allow (degrade).
     */
    private function enforceHourlyCap(string $key, int $cap): void
    {
        try {
            $count = $this->cache->incr($key);
            if ($count === 1) {
                $this->cache->expire($key, self::HOUR_SECONDS);
            }
            if ($count > $cap) {
                throw HttpException::rateLimited(
                    message: 'Too many requests submitted. Please try again later.',
                );
            }
        } catch (KeyValueStoreException $e) {
            $this->logger->warning('account-deletion hourly-cap cache failure — allowing', [
                'key' => $key,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Email the configured ops recipients that a deletion request
     * arrived. Entirely non-blocking — a mailer failure never fails the
     * HTTP response. No-ops when ADMIN_NOTIFICATION_EMAILS is empty.
     */
    private function notifyOps(RequestAccountDeletionInput $input, ?string $ip): void
    {
        if ($this->adminRecipients === []) {
            return;
        }

        $timestamp = gmdate('Y-m-d\TH:i:s\Z');
        $subject = 'Account deletion request';
        $text = sprintf(
            "An account + data deletion request was submitted from the web.\n\n"
            . "Email:   %s\n"
            . "Phone:   %s\n"
            . "Reason:  %s\n"
            . "IP:      %s\n"
            . "Time:    %s\n\n"
            . "Locate the account by email and process deletion per policy.",
            $input->email,
            $input->phone ?? '-',
            $input->reason ?? '-',
            $ip ?? '-',
            $timestamp,
        );
        $html = nl2br(htmlspecialchars($text, ENT_QUOTES, 'UTF-8'));

        foreach ($this->adminRecipients as $to) {
            try {
                $this->mailer->send($to, $subject, $text, $html, [
                    'template' => 'account.deletion_request',
                    'requested_email' => $input->email,
                ]);
            } catch (MailerException $e) {
                $this->logger->warning('account-deletion ops notification failed (non-blocking)', [
                    'error' => $e->getMessage(),
                ]);
            }
        }
    }
}
