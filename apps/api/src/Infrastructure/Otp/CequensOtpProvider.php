<?php

declare(strict_types=1);

namespace Bayti\Api\Infrastructure\Otp;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Exception\GuzzleException;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * Cequens Verification Hub (MFA) OTP provider — a second, failover provider
 * beside MessageCentral. Cequens' MFA product is managed OTP (the platform
 * generates, delivers and validates the code), so it maps cleanly onto our
 * OtpProvider interface: send() creates a verification and returns its id;
 * verify() validates a code against that id.
 *
 * Two-step flow:
 *   1. Auth  — POST {AUTH_PATH} {"apiKey","userName"} → data.access_token (JWT),
 *              cached for this instance's lifetime, sent as a Bearer token.
 *   2. Send  — POST {createPath}  → a verification id.
 *   3. Verify— POST {verify path} → a status we map to true/false.
 *
 * ============================================================================
 *  WIRE FORMAT — what is CONFIRMED vs. what is ASSUMED (READ BEFORE GO-LIVE)
 * ============================================================================
 * CONFIRMED against Cequens' docs:
 *   - Base host:  https://apis.cequens.com
 *   - Auth:       POST /auth/v1/tokens/  body {"apiKey","userName"}
 *                 → {"replyCode":0,"data":{"access_token":"<JWT>"}}
 *   - The MFA verifications resource is rooted at  /mfa/v2/verifications
 *
 * ASSUMED (Cequens does NOT publish the MFA create/verify request+response
 * bodies on its public developer portal — the MFA section there is guides
 * only). The create/verify PATHS and the request/response FIELD NAMES below
 * are the best-effort mapping from the REST resource shape and must be
 * confirmed against the account's API reference (or one real request/response
 * pair) before Cequens is enabled in production. To de-risk this:
 *   - Every path + key field is OVERRIDABLE FROM ENV via the DI factory, so a
 *     correction is a config change, NOT a redeploy.
 *   - Response parsing is DEFENSIVE: the verification id and status are read
 *     from several likely locations, and success is matched against a set of
 *     status tokens. A shape we didn't anticipate fails safe (send → typed
 *     exception → router fails over to MessageCentral; verify → false).
 *   - Cequens ships DISABLED in the admin OTP-provider config, so it is only
 *     exercised once an admin turns it on after staging validation.
 * ============================================================================
 */
final class CequensOtpProvider implements OtpProvider
{
    private const AUTH_PATH = '/auth/v1/tokens/';

    /**
     * Default MFA resource paths (overridable via the DI factory / env).
     * {id} in the verify template is replaced with the verification id.
     */
    public const DEFAULT_CREATE_PATH = '/mfa/v2/verifications';
    public const DEFAULT_VERIFY_PATH_TEMPLATE = '/mfa/v2/verifications/{id}/verify';

    /**
     * Response status tokens that mean "the code was correct". Compared
     * case-insensitively. Kept broad on purpose — different Cequens channels /
     * versions have used 'verified', 'approved', 'completed', 'success'.
     *
     * @var list<string>
     */
    private const SUCCESS_STATUSES = ['verified', 'approved', 'completed', 'success', 'valid'];

    /** Cached auth token for the lifetime of this instance. */
    private ?string $cachedAuthToken = null;

    public function __construct(
        private readonly Client $http,
        private readonly string $apiKey,
        private readonly string $userName,
        // Delivery channel for the OTP. Cequens supports sms / whatsapp / voice
        // / email; SMS is the default. Override via CEQUENS_CHANNEL.
        private readonly string $channel = 'sms',
        // Optional MFA template / sender id configured in the Cequens console.
        private readonly ?string $templateId = null,
        private readonly ?string $senderId = null,
        private readonly string $createPath = self::DEFAULT_CREATE_PATH,
        private readonly string $verifyPathTemplate = self::DEFAULT_VERIFY_PATH_TEMPLATE,
        private readonly LoggerInterface $logger = new NullLogger(),
    ) {
        if ($apiKey === '' || $userName === '') {
            throw new \InvalidArgumentException(
                'CequensOtpProvider requires apiKey and userName.'
            );
        }
    }

    public function send(string $toPhone): string
    {
        $token = $this->getAuthToken();

        // Cequens wants an E.164 recipient. Normalise to a single leading '+'.
        $recipient = $this->normaliseRecipient($toPhone);

        $body = array_filter([
            'recipient' => $recipient,
            'phoneNumber' => $recipient,
            'channel' => $this->channel,
            'templateId' => $this->templateId,
            'senderName' => $this->senderId,
        ], static fn (mixed $v): bool => $v !== null && $v !== '');

        try {
            $response = $this->http->post($this->createPath, [
                'headers' => [
                    'Authorization' => 'Bearer ' . $token,
                    'Accept' => 'application/json',
                ],
                'json' => $body,
                'http_errors' => false,
            ]);
        } catch (ConnectException $e) {
            throw new OtpProviderException('network', $e->getMessage(), $e);
        } catch (GuzzleException $e) {
            throw new OtpProviderException('transport', $e->getMessage(), $e);
        }

        $status = $response->getStatusCode();
        $raw = (string) $response->getBody();

        if ($status >= 400) {
            $this->logger->error('Cequens send failed', [
                'status' => $status,
                'body' => $this->safeTruncate($raw),
            ]);
            throw new OtpProviderException('upstream', "HTTP {$status}: " . $this->safeTruncate($raw));
        }

        $decoded = json_decode($raw, true);
        if (!is_array($decoded)) {
            throw new OtpProviderException('malformed', 'Response was not JSON: ' . $this->safeTruncate($raw));
        }

        $verificationId = $this->extractVerificationId($decoded);
        if ($verificationId === null) {
            throw new OtpProviderException('missing_id', 'Response did not contain a verification id: ' . $this->safeTruncate($raw));
        }

        $this->logger->info('OTP send succeeded (cequens)', [
            'phone' => $toPhone,
            'verification_id' => $verificationId,
        ]);

        return $verificationId;
    }

    public function verify(string $verificationId, string $code): bool
    {
        $token = $this->getAuthToken();

        try {
            $response = $this->http->post($this->buildVerifyPath($verificationId), [
                'headers' => [
                    'Authorization' => 'Bearer ' . $token,
                    'Accept' => 'application/json',
                ],
                'json' => [
                    'code' => $code,
                    'pin' => $code,
                    'verificationId' => $verificationId,
                ],
                'http_errors' => false,
            ]);
        } catch (ConnectException $e) {
            throw new OtpProviderException('network', $e->getMessage(), $e);
        } catch (GuzzleException $e) {
            throw new OtpProviderException('transport', $e->getMessage(), $e);
        }

        $status = $response->getStatusCode();
        $raw = (string) $response->getBody();

        // 5xx is a provider fault → throw (mirrors MessageCentral). 4xx is a
        // failed verification (wrong / expired / exhausted) → false, so the
        // user just re-requests a code rather than getting a 500.
        if ($status >= 500) {
            $this->logger->error('Cequens verify upstream error', [
                'status' => $status,
                'body' => $this->safeTruncate($raw),
            ]);
            throw new OtpProviderException('upstream', "HTTP {$status}");
        }

        $decoded = json_decode($raw, true);
        if (!is_array($decoded)) {
            $this->logger->warning('Cequens verify returned non-JSON', [
                'status' => $status,
                'body' => $this->safeTruncate($raw),
            ]);
            return false;
        }

        // An explicit HTTP 4xx with no contradicting success marker is a fail.
        if ($status >= 400) {
            return $this->extractVerified($decoded) === true;
        }

        return $this->extractVerified($decoded);
    }

    // -------------------------------------------------------------------
    // Internal
    // -------------------------------------------------------------------

    private function getAuthToken(): string
    {
        if ($this->cachedAuthToken !== null) {
            return $this->cachedAuthToken;
        }

        try {
            $response = $this->http->post(self::AUTH_PATH, [
                'headers' => ['Accept' => 'application/json'],
                'json' => [
                    'apiKey' => $this->apiKey,
                    'userName' => $this->userName,
                ],
                'http_errors' => false,
            ]);
        } catch (ConnectException $e) {
            $this->logger->error('Cequens auth failed', ['kind' => 'network', 'detail' => $e->getMessage()]);
            throw new OtpProviderException('network', 'auth: ' . $e->getMessage(), $e);
        } catch (GuzzleException $e) {
            $this->logger->error('Cequens auth failed', ['kind' => 'transport', 'detail' => $e->getMessage()]);
            throw new OtpProviderException('transport', 'auth: ' . $e->getMessage(), $e);
        }

        $status = $response->getStatusCode();
        $raw = (string) $response->getBody();

        if ($status >= 400) {
            $this->logger->error('Cequens auth failed', [
                'kind' => 'unauthorized',
                'status' => $status,
                'body' => $this->safeTruncate($raw),
            ]);
            throw new OtpProviderException('unauthorized', "auth HTTP {$status}: " . $this->safeTruncate($raw));
        }

        $decoded = json_decode($raw, true);
        $accessToken = null;
        if (is_array($decoded)) {
            // {"replyCode":0,"data":{"access_token":"<JWT>"}}; also accept a
            // top-level access_token / accessToken just in case.
            if (isset($decoded['data']) && is_array($decoded['data'])) {
                $accessToken = $this->stringOrNull($decoded['data']['access_token'] ?? $decoded['data']['accessToken'] ?? null);
            }
            $accessToken ??= $this->stringOrNull($decoded['access_token'] ?? $decoded['accessToken'] ?? null);
        }

        if ($accessToken === null || $accessToken === '') {
            $this->logger->error('Cequens auth failed', [
                'kind' => 'malformed',
                'status' => $status,
                'body' => $this->safeTruncate($raw),
            ]);
            throw new OtpProviderException('malformed', 'auth response missing access_token: ' . $this->safeTruncate($raw));
        }

        $this->cachedAuthToken = $accessToken;
        return $this->cachedAuthToken;
    }

    private function buildVerifyPath(string $verificationId): string
    {
        return str_replace('{id}', rawurlencode($verificationId), $this->verifyPathTemplate);
    }

    /**
     * A single leading '+' then digits. Cequens' MFA API expects an E.164
     * recipient; the app already stores '+<country><number>'.
     */
    private function normaliseRecipient(string $phone): string
    {
        $digits = preg_replace('/\D/', '', $phone) ?? '';
        // '00' international access prefix → same meaning as '+'.
        if (str_starts_with($digits, '00')) {
            $digits = substr($digits, 2);
        }
        return $digits === '' ? '' : '+' . $digits;
    }

    /**
     * Pull the verification id from several likely locations: data.id,
     * data.verificationId, top-level id / verificationId.
     *
     * @param array<string, mixed> $decoded
     */
    private function extractVerificationId(array $decoded): ?string
    {
        $data = (isset($decoded['data']) && is_array($decoded['data'])) ? $decoded['data'] : [];
        foreach (['verificationId', 'id', 'verification_id', 'reference'] as $key) {
            $v = $this->stringOrNull($data[$key] ?? null) ?? $this->stringOrNull($decoded[$key] ?? null);
            if ($v !== null && $v !== '') {
                return $v;
            }
        }
        return null;
    }

    /**
     * Decide whether a verify response means "code correct". Reads a status
     * string (data.status / status) against SUCCESS_STATUSES, or an explicit
     * boolean flag (data.verified / verified / valid).
     *
     * @param array<string, mixed> $decoded
     */
    private function extractVerified(array $decoded): bool
    {
        $data = (isset($decoded['data']) && is_array($decoded['data'])) ? $decoded['data'] : [];

        foreach ([$data, $decoded] as $scope) {
            foreach (['verified', 'valid', 'isValid', 'success'] as $flag) {
                if (array_key_exists($flag, $scope) && is_bool($scope[$flag])) {
                    if ($scope[$flag] === true) {
                        return true;
                    }
                }
            }
            $status = $this->stringOrNull($scope['status'] ?? $scope['verificationStatus'] ?? null);
            if ($status !== null && in_array(strtolower($status), self::SUCCESS_STATUSES, true)) {
                return true;
            }
        }

        return false;
    }

    private function stringOrNull(mixed $v): ?string
    {
        if (is_string($v)) {
            return $v;
        }
        if (is_int($v)) {
            return (string) $v;
        }
        return null;
    }

    private function safeTruncate(string $body, int $max = 200): string
    {
        if (strlen($body) <= $max) {
            return $body;
        }
        return substr($body, 0, $max) . '...';
    }
}
