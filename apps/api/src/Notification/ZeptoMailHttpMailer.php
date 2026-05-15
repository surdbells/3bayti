<?php

declare(strict_types=1);

namespace Bayti\Api\Notification;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Exception\GuzzleException;
use GuzzleHttp\Exception\RequestException;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * ZeptoMail (Zoho) HTTP API mailer — production adapter.
 *
 * Uses ZeptoMail's v1.1 email endpoint at api.zeptomail.com.
 * Authentication is a single API token sent as a custom
 * Authorization header: "Zoho-enczapikey <token>".
 *
 * Body shape (per ZeptoMail docs):
 *   {
 *     "from": {"address": "noreply@3bayti.ae", "name": "3bayti"},
 *     "to": [{"email_address": {"address": "...", "name": ""}}],
 *     "subject": "...",
 *     "htmlbody": "...",
 *     "textbody": "..."
 *   }
 *
 * Why HTTP over SMTP
 * ===================
 * Cleaner failure semantics (HTTP status codes vs. SMTP cryptic
 * response strings), better observability (we log the response
 * payload directly), and no need to maintain SMTP credentials
 * + MTA infrastructure. Production cost is identical.
 *
 * Failure mode
 * ============
 * Every failure path throws MailerException. Callers (the
 * notification services) catch and log without aborting the
 * primary action. Notifications are non-critical to order
 * processing; we never block "order placed" on email succeeding.
 *
 * Token bytes
 * ===========
 * ZeptoMail tokens are typically 64+ chars (Zoho-encoded). We
 * don't validate length here — Zoho rejects malformed tokens
 * with a 4xx that surfaces as MailerException.
 */
final class ZeptoMailHttpMailer implements MailerInterface
{
    private const ENDPOINT = 'https://api.zeptomail.com/v1.1/email';
    private const TIMEOUT_SECONDS = 15;

    public function __construct(
        private readonly string $apiToken,
        private readonly string $fromEmail,
        private readonly string $fromName,
        private readonly ?Client $httpClient = null,
        private readonly LoggerInterface $logger = new NullLogger(),
    ) {
        if ($apiToken === '') {
            throw new \InvalidArgumentException(
                'ZeptoMailHttpMailer requires a non-empty API token. '
                . 'Set ZEPTOMAIL_API_TOKEN or bind NullMailer in dev.',
            );
        }
        if ($fromEmail === '') {
            throw new \InvalidArgumentException(
                'ZeptoMailHttpMailer requires a non-empty from email. '
                . 'Set ZEPTOMAIL_FROM_EMAIL.',
            );
        }
    }

    public function send(
        string $to,
        string $subject,
        string $textBody,
        string $htmlBody,
        array $context = [],
    ): void {
        if ($to === '') {
            throw new MailerException(
                kind: MailerException::KIND_TRANSPORT,
                message: 'Recipient address cannot be empty.',
            );
        }
        if (!filter_var($to, FILTER_VALIDATE_EMAIL)) {
            throw new MailerException(
                kind: MailerException::KIND_TRANSPORT,
                message: "Recipient address is not a valid email: '{$to}'.",
            );
        }

        $client = $this->httpClient ?? new Client([
            'timeout' => self::TIMEOUT_SECONDS,
        ]);

        $payload = [
            'from' => [
                'address' => $this->fromEmail,
                'name' => $this->fromName,
            ],
            'to' => [[
                'email_address' => [
                    'address' => $to,
                    'name' => '',
                ],
            ]],
            'subject' => $subject,
            'htmlbody' => $htmlBody,
            'textbody' => $textBody,
        ];

        try {
            $response = $client->request('POST', self::ENDPOINT, [
                'headers' => [
                    'Authorization' => 'Zoho-enczapikey ' . $this->apiToken,
                    'Content-Type' => 'application/json',
                    'Accept' => 'application/json',
                ],
                'json' => $payload,
            ]);

            $statusCode = $response->getStatusCode();
            $rawBody = (string) $response->getBody();

            // ZeptoMail returns 201 Created on success
            if ($statusCode < 200 || $statusCode >= 300) {
                $this->logger->error('mail.zeptomail.upstream_error', array_merge($context, [
                    'to' => $to,
                    'status_code' => $statusCode,
                    'response_body' => $this->truncate($rawBody, 500),
                ]));
                $kind = match (true) {
                    $statusCode === 401 || $statusCode === 403 => MailerException::KIND_AUTH,
                    $statusCode === 429 => MailerException::KIND_RATE_LIMIT,
                    default => MailerException::KIND_TRANSPORT,
                };
                throw new MailerException(
                    kind: $kind,
                    message: "ZeptoMail rejected the send with HTTP {$statusCode}.",
                );
            }

            $this->logger->info('mail.zeptomail.sent', array_merge($context, [
                'to' => $to,
                'subject' => $subject,
                'status_code' => $statusCode,
            ]));
        } catch (ConnectException $e) {
            $this->logger->error('mail.zeptomail.network_error', array_merge($context, [
                'to' => $to,
                'error' => $e->getMessage(),
            ]));
            throw new MailerException(
                kind: MailerException::KIND_NETWORK,
                message: "ZeptoMail network error: {$e->getMessage()}",
                previous: $e,
            );
        } catch (RequestException $e) {
            $body = $e->hasResponse()
                ? $this->truncate((string) $e->getResponse()?->getBody(), 500)
                : '(no response)';
            $statusCode = $e->hasResponse() ? $e->getResponse()?->getStatusCode() : null;
            $this->logger->error('mail.zeptomail.request_error', array_merge($context, [
                'to' => $to,
                'error' => $e->getMessage(),
                'status_code' => $statusCode,
                'response_body' => $body,
            ]));
            $kind = match (true) {
                $statusCode === 401 || $statusCode === 403 => MailerException::KIND_AUTH,
                $statusCode === 429 => MailerException::KIND_RATE_LIMIT,
                default => MailerException::KIND_TRANSPORT,
            };
            throw new MailerException(
                kind: $kind,
                message: "ZeptoMail request error: {$e->getMessage()}",
                previous: $e,
            );
        } catch (GuzzleException $e) {
            $this->logger->error('mail.zeptomail.guzzle_error', array_merge($context, [
                'to' => $to,
                'error' => $e->getMessage(),
            ]));
            throw new MailerException(
                kind: MailerException::KIND_UNKNOWN,
                message: "ZeptoMail transport error: {$e->getMessage()}",
                previous: $e,
            );
        }
    }

    private function truncate(string $s, int $max): string
    {
        return strlen($s) > $max ? substr($s, 0, $max) . '…(truncated)' : $s;
    }
}
