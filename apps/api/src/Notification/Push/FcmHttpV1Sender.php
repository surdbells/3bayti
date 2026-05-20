<?php

declare(strict_types=1);

namespace Bayti\Api\Notification\Push;

use Firebase\JWT\JWT;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Exception\GuzzleException;
use GuzzleHttp\Exception\RequestException;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * Firebase Cloud Messaging HTTP v1 sender — production adapter.
 *
 * Sends to https://fcm.googleapis.com/v1/projects/{projectId}/messages:send.
 * FCM relays to APNs for iOS, so this single adapter covers both
 * platforms (Q-Z4=A).
 *
 * Authentication (OAuth2, service account)
 * ========================================
 * HTTP v1 requires a short-lived OAuth2 access token (unlike the old
 * legacy server key). We mint one ourselves from the service-account
 * credentials:
 *   1. Build a signed JWT assertion (RS256) over the service account's
 *      private key, scoped to firebase.messaging, aud = Google's OAuth2
 *      token endpoint.
 *   2. Exchange it (grant_type=jwt-bearer) for an access_token.
 *   3. Cache the access_token in-process until ~60s before expiry.
 *
 * This avoids a hard dependency on google/auth while using the
 * firebase/php-jwt library already in the project (the same one that
 * signs our own auth tokens).
 *
 * Credentials come from a service-account JSON (the standard Firebase
 * download). The constructor takes the already-decoded fields so DI
 * can read them from env/file once.
 *
 * Failure mode
 * ============
 * Every failure path throws PushException; callers catch + log without
 * aborting the primary action. A 404 / UNREGISTERED from FCM maps to
 * KIND_UNREGISTERED so PushNotificationService can prune the dead
 * token.
 */
final class FcmHttpV1Sender implements PushSenderInterface
{
    private const OAUTH_TOKEN_URI = 'https://oauth2.googleapis.com/token';
    private const FCM_SCOPE = 'https://www.googleapis.com/auth/firebase.messaging';
    private const JWT_BEARER_GRANT = 'urn:ietf:params:oauth:grant-type:jwt-bearer';
    private const TIMEOUT_SECONDS = 15;
    /** Refresh the access token this many seconds before it expires. */
    private const EXPIRY_SKEW_SECONDS = 60;

    private ?string $cachedAccessToken = null;
    private int $cachedTokenExpiresAt = 0;

    /**
     * @param string $projectId          Firebase project id.
     * @param string $clientEmail        Service account client_email.
     * @param string $privateKey         Service account private_key (PEM).
     * @param Client|null $httpClient    Injectable for tests.
     * @param LoggerInterface $logger
     * @param callable():int|null $clock Test seam for "now" (epoch secs).
     */
    public function __construct(
        private readonly string $projectId,
        private readonly string $clientEmail,
        private readonly string $privateKey,
        private readonly ?Client $httpClient = null,
        private readonly LoggerInterface $logger = new NullLogger(),
        private $clock = null,
    ) {
        if ($projectId === '' || $clientEmail === '' || $privateKey === '') {
            throw new \InvalidArgumentException(
                'FcmHttpV1Sender requires non-empty projectId, clientEmail, and '
                . 'privateKey. Provide a Firebase service-account JSON via env, '
                . 'or bind NullPushSender in dev.',
            );
        }
    }

    public function sendToToken(
        string $token,
        PushMessage $message,
        array $context = [],
    ): void {
        if ($token === '') {
            throw new PushException(
                kind: PushException::KIND_TRANSPORT,
                message: 'Device token cannot be empty.',
            );
        }

        $accessToken = $this->accessToken();
        $client = $this->httpClient ?? new Client(['timeout' => self::TIMEOUT_SECONDS]);

        $endpoint = sprintf(
            'https://fcm.googleapis.com/v1/projects/%s/messages:send',
            $this->projectId,
        );

        $payload = [
            'message' => [
                'token' => $token,
                'notification' => [
                    'title' => $message->title,
                    'body' => $message->body,
                ],
                'data' => $message->data,
            ],
        ];

        try {
            $response = $client->request('POST', $endpoint, [
                'headers' => [
                    'Authorization' => 'Bearer ' . $accessToken,
                    'Content-Type' => 'application/json',
                    'Accept' => 'application/json',
                ],
                'json' => $payload,
            ]);

            $statusCode = $response->getStatusCode();
            if ($statusCode < 200 || $statusCode >= 300) {
                $raw = (string) $response->getBody();
                throw $this->mapErrorResponse($statusCode, $raw, $token, $context);
            }

            $this->logger->info('push.fcm.sent', array_merge($context, [
                'token_suffix' => $this->tokenSuffix($token),
                'title' => $message->title,
                'status_code' => $statusCode,
            ]));
        } catch (ConnectException $e) {
            $this->logger->error('push.fcm.network_error', array_merge($context, [
                'token_suffix' => $this->tokenSuffix($token),
                'error' => $e->getMessage(),
            ]));
            throw new PushException(
                kind: PushException::KIND_NETWORK,
                message: "FCM network error: {$e->getMessage()}",
                previous: $e,
            );
        } catch (RequestException $e) {
            $statusCode = $e->hasResponse() ? (int) $e->getResponse()?->getStatusCode() : 0;
            $raw = $e->hasResponse() ? (string) $e->getResponse()?->getBody() : '(no response)';
            throw $this->mapErrorResponse($statusCode, $raw, $token, $context, $e);
        } catch (GuzzleException $e) {
            $this->logger->error('push.fcm.guzzle_error', array_merge($context, [
                'token_suffix' => $this->tokenSuffix($token),
                'error' => $e->getMessage(),
            ]));
            throw new PushException(
                kind: PushException::KIND_UNKNOWN,
                message: "FCM transport error: {$e->getMessage()}",
                previous: $e,
            );
        }
    }

    /**
     * Map a non-2xx FCM response to a PushException with the right kind.
     * FCM v1 surfaces a permanently-dead token as HTTP 404 with
     * error.status = "NOT_FOUND" / "UNREGISTERED".
     */
    /**
     * @param array<string, mixed> $context
     */
    private function mapErrorResponse(
        int $statusCode,
        string $rawBody,
        string $token,
        array $context,
        ?\Throwable $previous = null,
    ): PushException {
        $fcmStatus = $this->extractFcmStatus($rawBody);

        $kind = match (true) {
            $statusCode === 404
                || $fcmStatus === 'UNREGISTERED'
                || $fcmStatus === 'NOT_FOUND' => PushException::KIND_UNREGISTERED,
            $statusCode === 401 || $statusCode === 403 => PushException::KIND_AUTH,
            $statusCode === 429 => PushException::KIND_RATE_LIMIT,
            $statusCode >= 400 && $statusCode < 500 => PushException::KIND_TRANSPORT,
            default => PushException::KIND_TRANSPORT,
        };

        $logLevel = $kind === PushException::KIND_UNREGISTERED ? 'info' : 'error';
        $this->logger->log($logLevel, 'push.fcm.rejected', array_merge($context, [
            'token_suffix' => $this->tokenSuffix($token),
            'status_code' => $statusCode,
            'fcm_status' => $fcmStatus,
            'response_body' => $this->truncate($rawBody, 500),
        ]));

        return new PushException(
            kind: $kind,
            message: "FCM rejected the send with HTTP {$statusCode}"
                . ($fcmStatus !== null ? " ({$fcmStatus})" : '') . '.',
            previous: $previous,
        );
    }

    /** Pull error.status out of an FCM v1 error body, if present. */
    private function extractFcmStatus(string $rawBody): ?string
    {
        if ($rawBody === '') {
            return null;
        }
        $decoded = json_decode($rawBody, true);
        if (!is_array($decoded)) {
            return null;
        }
        $error = $decoded['error'] ?? null;
        if (!is_array($error)) {
            return null;
        }
        $status = $error['status'] ?? null;
        return is_string($status) ? $status : null;
    }

    /**
     * Return a valid OAuth2 access token, minting (and caching) a new
     * one if the cache is empty or about to expire.
     */
    private function accessToken(): string
    {
        $now = $this->now();
        if ($this->cachedAccessToken !== null && $now < $this->cachedTokenExpiresAt - self::EXPIRY_SKEW_SECONDS) {
            return $this->cachedAccessToken;
        }

        $assertion = $this->buildAssertion($now);
        $client = $this->httpClient ?? new Client(['timeout' => self::TIMEOUT_SECONDS]);

        try {
            $response = $client->request('POST', self::OAUTH_TOKEN_URI, [
                'headers' => ['Accept' => 'application/json'],
                'form_params' => [
                    'grant_type' => self::JWT_BEARER_GRANT,
                    'assertion' => $assertion,
                ],
            ]);
        } catch (ConnectException $e) {
            throw new PushException(
                kind: PushException::KIND_NETWORK,
                message: "FCM OAuth2 network error: {$e->getMessage()}",
                previous: $e,
            );
        } catch (GuzzleException $e) {
            throw new PushException(
                kind: PushException::KIND_AUTH,
                message: "FCM OAuth2 token request failed: {$e->getMessage()}",
                previous: $e,
            );
        }

        $decoded = json_decode((string) $response->getBody(), true);
        if (!is_array($decoded) || !isset($decoded['access_token']) || !is_string($decoded['access_token'])) {
            throw new PushException(
                kind: PushException::KIND_AUTH,
                message: 'FCM OAuth2 response did not contain an access_token.',
            );
        }

        $expiresIn = isset($decoded['expires_in']) && is_numeric($decoded['expires_in'])
            ? (int) $decoded['expires_in']
            : 3600;

        $this->cachedAccessToken = $decoded['access_token'];
        $this->cachedTokenExpiresAt = $now + $expiresIn;

        return $this->cachedAccessToken;
    }

    /** Build the signed JWT bearer assertion for the token exchange. */
    private function buildAssertion(int $now): string
    {
        $claims = [
            'iss' => $this->clientEmail,
            'sub' => $this->clientEmail,
            'aud' => self::OAUTH_TOKEN_URI,
            'scope' => self::FCM_SCOPE,
            'iat' => $now,
            'exp' => $now + 3600,
        ];

        return JWT::encode($claims, $this->privateKey, 'RS256');
    }

    private function now(): int
    {
        return $this->clock !== null ? (int) ($this->clock)() : time();
    }

    private function tokenSuffix(string $token): string
    {
        return strlen($token) <= 6 ? $token : '…' . substr($token, -6);
    }

    private function truncate(string $s, int $max): string
    {
        return strlen($s) > $max ? substr($s, 0, $max) . '…(truncated)' : $s;
    }
}
