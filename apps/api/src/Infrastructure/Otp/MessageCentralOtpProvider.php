<?php

declare(strict_types=1);

namespace Bayti\Api\Infrastructure\Otp;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Exception\GuzzleException;
use GuzzleHttp\Exception\RequestException;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * MessageCentral CPaaS OTP provider — production adapter.
 *
 * Three-step flow per MessageCentral's CPaaS API:
 *
 *   1. Auth: GET /auth/v1/authentication/token
 *      → returns short-lived authToken
 *      We cache it in memory for the request's lifetime; CPaaS
 *      tokens are usually long enough for a single request flow.
 *
 *   2. Send: POST /verification/v3/send
 *      → returns verificationId
 *      Body is empty; the destination + flow are query-string
 *      params per MessageCentral's spec (matches what the legacy
 *      backend does in class/Customer.php).
 *
 *   3. Verify: GET /verification/v3/validateOtp
 *      → returns success/failure
 *
 * Error handling
 * --------------
 * Network errors → OtpProviderException('network').
 * 4xx from CPaaS → OtpProviderException('upstream') with details.
 * Malformed JSON → OtpProviderException('malformed').
 * Successful 200 with no verificationId → OtpProviderException('missing_id').
 *
 * "Wrong code" is NOT an exception — verify() returns false in that
 * case. CPaaS distinguishes auth/transport failures from "OTP didn't
 * match" via the response body's responseCode field.
 *
 * Configuration
 * -------------
 * Constructor takes:
 *   - customerId  (e.g. 'C-0B186B587F924BB')
 *   - apiKey      (the encoded password)
 *   - email       (used as auth identifier)
 *   - country     ('971' for UAE; the API's "country code" not the
 *                  ISO country code)
 *   - baseUrl     (https://cpaas.messagecentral.com — overridable
 *                  for staging/sandbox)
 *
 * All come from env vars in production via the DI factory.
 */
final class MessageCentralOtpProvider implements OtpProvider
{
    private const AUTH_PATH = '/auth/v1/authentication/token';
    private const SEND_PATH = '/verification/v3/send';
    private const VERIFY_PATH = '/verification/v3/validateOtp';

    /**
     * Dial codes the app's country picker offers (UAE, GCC, + a few
     * nearby markets). MessageCentral's send API wants the destination
     * split into (countryCode, mobileNumber), so we detect the code the
     * number actually carries instead of assuming UAE. Longest-first so a
     * 2-digit code ('20') can't shadow a 3-digit one — though none of
     * these overlap as prefixes. Keep in sync with the mobile app's
     * country list.
     *
     * @var list<string>
     */
    private const KNOWN_DIAL_CODES = ['971', '966', '974', '973', '965', '968', '962', '961', '20'];

    /** Cached auth token for the lifetime of this instance. */
    private ?string $cachedAuthToken = null;

    public function __construct(
        private readonly Client $http,
        private readonly string $customerId,
        private readonly string $apiKey,
        private readonly string $email,
        private readonly string $country = '971',
        private readonly string $flowType = 'SMS',
        private readonly LoggerInterface $logger = new NullLogger(),
    ) {
        if ($customerId === '' || $apiKey === '' || $email === '') {
            throw new \InvalidArgumentException(
                'MessageCentralOtpProvider requires customerId, apiKey, and email.'
            );
        }
    }

    public function send(string $toPhone): string
    {
        $authToken = $this->getAuthToken();

        // CPaaS expects the destination split into a country code and a
        // local mobile number (both without '+'). The number the user
        // typed carries its OWN country code (they pick a dial code in the
        // app) — we must forward THAT, not a hardcoded UAE code, or e.g. a
        // Bahrain number (+973…) gets sent as countryCode=971 + 973… and
        // MessageCentral rejects the malformed +971973… destination.
        [$countryCode, $localNumber] = $this->resolveDestination($toPhone);

        try {
            $response = $this->http->post($this->buildUrl(self::SEND_PATH, [
                'countryCode' => $countryCode,
                'flowType' => $this->flowType,
                'mobileNumber' => $localNumber,
            ]), [
                'headers' => ['authToken' => $authToken],
                'http_errors' => false,
            ]);
        } catch (ConnectException $e) {
            throw new OtpProviderException('network', $e->getMessage(), $e);
        } catch (GuzzleException $e) {
            throw new OtpProviderException('transport', $e->getMessage(), $e);
        }

        $status = $response->getStatusCode();
        $body = (string) $response->getBody();

        if ($status >= 400) {
            $this->logger->error('MessageCentral send failed', [
                'status' => $status,
                'body' => $body,
                'phone' => $toPhone,
            ]);
            throw new OtpProviderException('upstream', "HTTP {$status}: " . $this->safeTruncate($body));
        }

        $decoded = json_decode($body, true);
        if (!is_array($decoded)) {
            throw new OtpProviderException('malformed', 'Response was not JSON: ' . $this->safeTruncate($body));
        }

        // MessageCentral wraps the actual data in a 'data' field.
        // Example: {"responseCode":200,"data":{"verificationId":"123","mobileNumber":"...","..."}}
        $verificationId = $this->extractVerificationId($decoded);
        if ($verificationId === null) {
            throw new OtpProviderException('missing_id', 'Response did not contain verificationId: ' . $this->safeTruncate($body));
        }

        $this->logger->info('OTP send succeeded', [
            'phone' => $toPhone,
            'verification_id' => $verificationId,
        ]);

        return $verificationId;
    }

    public function verify(string $verificationId, string $code): bool
    {
        $authToken = $this->getAuthToken();

        try {
            $response = $this->http->get($this->buildUrl(self::VERIFY_PATH, [
                'verificationId' => $verificationId,
                'code' => $code,
            ]), [
                'headers' => ['authToken' => $authToken],
                'http_errors' => false,
            ]);
        } catch (ConnectException $e) {
            throw new OtpProviderException('network', $e->getMessage(), $e);
        } catch (GuzzleException $e) {
            throw new OtpProviderException('transport', $e->getMessage(), $e);
        }

        $status = $response->getStatusCode();
        $body = (string) $response->getBody();

        // 200 with verificationStatus=VERIFICATION_COMPLETED → success.
        // 200 with verificationStatus=VERIFICATION_FAILED → wrong code.
        // 4xx → some other failure (expired, exhausted, etc.) — also false.
        // 5xx → throw, transport-level problem.
        if ($status >= 500) {
            $this->logger->error('MessageCentral verify upstream error', [
                'status' => $status,
                'body' => $this->safeTruncate($body),
            ]);
            throw new OtpProviderException('upstream', "HTTP {$status}");
        }

        $decoded = json_decode($body, true);
        if (!is_array($decoded)) {
            // Malformed but we treat it as a verification failure
            // rather than throwing — the user just sees "wrong code"
            // and can retry. Throwing would 500 the request.
            $this->logger->warning('MessageCentral verify returned non-JSON', [
                'status' => $status,
                'body' => $this->safeTruncate($body),
            ]);
            return false;
        }

        $verificationStatus = $this->extractVerificationStatus($decoded);
        return $verificationStatus === 'VERIFICATION_COMPLETED';
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
            // Log parity with the send path: the auth step used to throw
            // silently, so an auth-token failure (the most common systemic OTP
            // outage — bad/expired key, suspended account, endpoint down) left
            // no file-log trail and was only visible via the exception cause.
            $this->logger->error('MessageCentral auth failed', ['kind' => 'network', 'detail' => $e->getMessage()]);
            throw new OtpProviderException('network', 'auth: ' . $e->getMessage(), $e);
        } catch (GuzzleException $e) {
            $this->logger->error('MessageCentral auth failed', ['kind' => 'transport', 'detail' => $e->getMessage()]);
            throw new OtpProviderException('transport', 'auth: ' . $e->getMessage(), $e);
        }

        $status = $response->getStatusCode();
        $body = (string) $response->getBody();

        if ($status >= 400) {
            $this->logger->error('MessageCentral auth failed', [
                'kind' => 'unauthorized',
                'status' => $status,
                'body' => $this->safeTruncate($body),
            ]);
            throw new OtpProviderException('unauthorized', "auth HTTP {$status}: " . $this->safeTruncate($body));
        }

        $decoded = json_decode($body, true);
        if (!is_array($decoded) || !isset($decoded['token']) || !is_string($decoded['token'])) {
            $this->logger->error('MessageCentral auth failed', [
                'kind' => 'malformed',
                'status' => $status,
                'body' => $this->safeTruncate($body),
            ]);
            throw new OtpProviderException('malformed', 'auth response missing token: ' . $this->safeTruncate($body));
        }

        $this->cachedAuthToken = $decoded['token'];
        return $this->cachedAuthToken;
    }

    /**
     * Build a URL with query-string parameters. Doesn't include the
     * base; that's set on the Guzzle Client.
     *
     * @param array<string, string> $params
     */
    private function buildUrl(string $path, array $params): string
    {
        return $path . '?' . http_build_query($params);
    }

    /**
     * Split an E.164-ish destination into [countryCode, mobileNumber] for
     * CPaaS. Returns the country code the number actually carries; only
     * bare local numbers (no international prefix, no recognised code) fall
     * back to the configured default country.
     *
     *   '+97335175253'  → ['973', '35175253']   (Bahrain)
     *   '+96552550707'  → ['965', '52550707']   (Kuwait)
     *   '+971542192976' → ['971', '542192976']  (UAE)
     *   '0501234567'    → ['971', '501234567']   (bare UAE local → default)
     *
     * @return array{0: string, 1: string}
     */
    private function resolveDestination(string $phone): array
    {
        $digits = preg_replace('/\D/', '', $phone) ?? '';
        // '00' is the international access prefix — same meaning as '+'.
        if (str_starts_with($digits, '00')) {
            $digits = substr($digits, 2);
        }
        if ($digits === '') {
            return [$this->country, ''];
        }

        // Detect the carried country code. UAE mobile numbers start with
        // '5', so they never collide with any of these codes; the match
        // only fires on numbers that genuinely lead with an intl code.
        foreach (self::KNOWN_DIAL_CODES as $code) {
            if (str_starts_with($digits, $code) && strlen($digits) > strlen($code)) {
                return [$code, substr($digits, strlen($code))];
            }
        }

        // Bare local number: assume the default country, dropping a
        // stray leading trunk '0' (e.g. '0501234567' → '501234567').
        return [$this->country, ltrim($digits, '0')];
    }

    /**
     * Extract verificationId from MessageCentral's response, which
     * may be at the top level OR nested under a 'data' key.
     *
     * @param array<string, mixed> $decoded
     */
    private function extractVerificationId(array $decoded): ?string
    {
        if (isset($decoded['verificationId']) && is_string($decoded['verificationId'])) {
            return $decoded['verificationId'];
        }
        if (
            isset($decoded['data'])
            && is_array($decoded['data'])
            && isset($decoded['data']['verificationId'])
            && is_string($decoded['data']['verificationId'])
        ) {
            return $decoded['data']['verificationId'];
        }
        return null;
    }

    /**
     * Extract verificationStatus from MessageCentral's verify response.
     *
     * @param array<string, mixed> $decoded
     */
    private function extractVerificationStatus(array $decoded): ?string
    {
        if (isset($decoded['verificationStatus']) && is_string($decoded['verificationStatus'])) {
            return $decoded['verificationStatus'];
        }
        if (
            isset($decoded['data'])
            && is_array($decoded['data'])
            && isset($decoded['data']['verificationStatus'])
            && is_string($decoded['data']['verificationStatus'])
        ) {
            return $decoded['data']['verificationStatus'];
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
