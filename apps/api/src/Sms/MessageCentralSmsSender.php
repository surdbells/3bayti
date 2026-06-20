<?php

declare(strict_types=1);

namespace Bayti\Api\Sms;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Exception\GuzzleException;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * MessageCentral CPaaS general-SMS sender — production adapter.
 *
 * Reuses the same auth-token mechanism as MessageCentralOtpProvider
 * (GET /auth/v1/authentication/token → {token}, sent back as the
 * `authToken` header), then POSTs the SMS to a CONFIGURABLE send
 * endpoint. The OTP send endpoint cannot carry arbitrary text, so a
 * separate general-SMS endpoint is required.
 *
 * IMPORTANT — endpoint is unconfirmed
 * ===================================
 * MessageCentral's exact general-SMS send path + parameter names are
 * not yet confirmed by the operator. Everything here is therefore
 * ENV-DRIVEN with safe defaults, and this sender is only selected by
 * the DI factory when SMS is EXPLICITLY enabled + configured
 * (MESSAGECENTRAL_SMS_ENABLED=true). Otherwise NullSmsSender is used,
 * so the app boots + no-ops safely until the operator confirms.
 *
 * Operator must set (see di.php + .env.example):
 *   - MESSAGECENTRAL_SMS_ENABLED=true
 *   - MESSAGECENTRAL_CUSTOMER_ID / MESSAGECENTRAL_KEY / MESSAGECENTRAL_EMAIL
 *     (shared with the OTP provider — same account)
 *   - MESSAGECENTRAL_SMS_SENDER_ID   (the approved alphanumeric/DLT sender)
 *   - MESSAGECENTRAL_SMS_PATH        (the confirmed send path; default below)
 *   - MESSAGECENTRAL_COUNTRY         (default '971')
 *   - MESSAGECENTRAL_BASE_URL        (default https://cpaas.messagecentral.com)
 *
 * Error handling mirrors MessageCentralOtpProvider:
 *   network → SmsException('network'); auth 4xx → 'unauthorized';
 *   send 4xx/5xx → 'transport'; unparsable JSON → 'malformed'.
 */
final class MessageCentralSmsSender implements SmsSenderInterface
{
    private const AUTH_PATH = '/auth/v1/authentication/token';

    /** Default general-SMS send path. Overridable via MESSAGECENTRAL_SMS_PATH. */
    public const DEFAULT_SEND_PATH = '/verification/v3/send';

    /** Cached auth token for the lifetime of this instance. */
    private ?string $cachedAuthToken = null;

    private LoggerInterface $logger;

    public function __construct(
        private readonly Client $http,
        private readonly string $customerId,
        private readonly string $apiKey,
        private readonly string $email,
        private readonly string $senderId,
        private readonly string $sendPath = self::DEFAULT_SEND_PATH,
        private readonly string $country = '971',
        ?LoggerInterface $logger = null,
    ) {
        if ($customerId === '' || $apiKey === '' || $email === '') {
            throw new \InvalidArgumentException(
                'MessageCentralSmsSender requires customerId, apiKey, and email.'
            );
        }
        $this->logger = $logger ?? new NullLogger();
    }

    public function send(string $toPhone, string $message): void
    {
        $authToken = $this->getAuthToken();

        // CPaaS expects the mobile number WITHOUT the leading '+' and
        // WITHOUT the country code (country is a separate param).
        $localNumber = $this->stripCountryCode($toPhone);

        try {
            $response = $this->http->post($this->buildUrl($this->sendPath, [
                'countryCode' => $this->country,
                'flowType' => 'SMS',
                'mobileNumber' => $localNumber,
                'senderId' => $this->senderId,
                'type' => 'SMS',
                'message' => $message,
            ]), [
                'headers' => ['authToken' => $authToken],
                'http_errors' => false,
            ]);
        } catch (ConnectException $e) {
            throw new SmsException(SmsException::KIND_NETWORK, $e->getMessage(), $e);
        } catch (GuzzleException $e) {
            throw new SmsException(SmsException::KIND_TRANSPORT, $e->getMessage(), $e);
        }

        $status = $response->getStatusCode();
        $body = (string) $response->getBody();

        if ($status >= 400) {
            $this->logger->error('MessageCentral SMS send failed', [
                'status' => $status,
                'body' => $this->safeTruncate($body),
                'phone' => $toPhone,
            ]);
            throw new SmsException(
                SmsException::KIND_TRANSPORT,
                "HTTP {$status}: " . $this->safeTruncate($body),
            );
        }

        $decoded = json_decode($body, true);
        if (!is_array($decoded)) {
            throw new SmsException(
                SmsException::KIND_MALFORMED,
                'Response was not JSON: ' . $this->safeTruncate($body),
            );
        }

        $this->logger->info('SMS send succeeded', ['phone' => $toPhone]);
    }

    private function getAuthToken(): string
    {
        if ($this->cachedAuthToken !== null) {
            return $this->cachedAuthToken;
        }

        try {
            $response = $this->http->get($this->buildUrl(self::AUTH_PATH, [
                'customerId' => $this->customerId,
                'key' => $this->apiKey,
                'scope' => 'NEW',
                'country' => $this->country,
                'email' => $this->email,
            ]), [
                'headers' => ['accept' => '*/*'],
                'http_errors' => false,
            ]);
        } catch (ConnectException $e) {
            throw new SmsException(SmsException::KIND_NETWORK, 'auth: ' . $e->getMessage(), $e);
        } catch (GuzzleException $e) {
            throw new SmsException(SmsException::KIND_TRANSPORT, 'auth: ' . $e->getMessage(), $e);
        }

        $status = $response->getStatusCode();
        $body = (string) $response->getBody();

        if ($status >= 400) {
            throw new SmsException(
                SmsException::KIND_UNAUTHORIZED,
                "auth HTTP {$status}: " . $this->safeTruncate($body),
            );
        }

        $decoded = json_decode($body, true);
        if (!is_array($decoded) || !isset($decoded['token']) || !is_string($decoded['token'])) {
            throw new SmsException(
                SmsException::KIND_MALFORMED,
                'auth response missing token: ' . $this->safeTruncate($body),
            );
        }

        $this->cachedAuthToken = $decoded['token'];
        return $this->cachedAuthToken;
    }

    /**
     * @param array<string, string> $params
     */
    private function buildUrl(string $path, array $params): string
    {
        return $path . '?' . http_build_query($params);
    }

    private function stripCountryCode(string $phone): string
    {
        $stripped = ltrim($phone, '+');
        if (str_starts_with($stripped, $this->country)) {
            $stripped = substr($stripped, strlen($this->country));
        }
        return $stripped;
    }

    private function safeTruncate(string $body, int $max = 200): string
    {
        if (strlen($body) <= $max) {
            return $body;
        }
        return substr($body, 0, $max) . '...';
    }
}
