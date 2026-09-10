<?php

declare(strict_types=1);

namespace Bayti\Api\Shipping\Oto;

use Bayti\Api\Shipping\ShippingException;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Exception\GuzzleException;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * Thin HTTP client for the OTO (tryoto.com) shipping API v2.
 *
 * Auth
 * ----
 * OTO issues a long-lived REFRESH token in the dashboard (Sales Channel → OTO
 * API). We exchange it for a short-lived ACCESS token via
 * POST /rest/v2/refreshToken { refresh_token } → { access_token }, then send
 * `Authorization: Bearer <access_token>` on every call. The access token is
 * cached for this client's lifetime (one auth round-trip per PHP request that
 * ships something).
 *
 * Errors mirror the Noon gateway / MessageCentral posture: connect failures →
 * ShippingException(network); auth exchange 4xx → auth; other non-2xx →
 * transport; unparsable JSON → malformed. Never leaks the refresh token.
 *
 * Base URL is env-driven: https://api.tryoto.com (prod) or
 * https://staging-api.tryoto.com (staging).
 */
final class OtoClient
{
    private const AUTH_PATH         = '/rest/v2/refreshToken';
    private const CREATE_ORDER_PATH = '/rest/v2/createOrder';
    private const DELIVERY_FEES_PATH = '/rest/v2/checkOTODeliveryFee';
    private const PICKUP_LOCATIONS_PATH = '/rest/v2/getPickupLocationList';
    private const CREATE_PICKUP_LOCATION_PATH = '/rest/v2/createPickupLocation';

    private ?string $cachedAccessToken = null;

    private LoggerInterface $logger;

    public function __construct(
        private readonly Client $http,
        private readonly string $baseUrl,
        private readonly string $refreshToken,
        ?LoggerInterface $logger = null,
    ) {
        $this->logger = $logger ?? new NullLogger();
    }

    /**
     * Create an OTO order (optionally auto-creating a shipment). Returns the
     * decoded response, e.g. { success: true, otoId: 14796940, ... }.
     *
     * @param array<string, mixed> $body
     * @return array<string, mixed>
     */
    public function createOrder(array $body): array
    {
        $decoded = $this->authedPost(self::CREATE_ORDER_PATH, $body);

        // OTO signals a soft failure with success:false + a message, even on a
        // 200 — surface it as a transport error rather than pretending it worked.
        if (($decoded['success'] ?? null) === false) {
            $msg = (string) ($decoded['message'] ?? 'OTO createOrder rejected the request.');
            throw new ShippingException(ShippingException::KIND_TRANSPORT, "OTO: {$msg}");
        }

        return $decoded;
    }

    /**
     * Available delivery options (carriers + price) for an order payload. OTO's
     * fee-check endpoint; shape varies, so parsing is best-effort and lives in
     * the caller. Returns the decoded response.
     *
     * @param array<string, mixed> $body
     * @return array<string, mixed>
     */
    public function checkDeliveryFees(array $body): array
    {
        return $this->authedPost(self::DELIVERY_FEES_PATH, $body);
    }

    /**
     * The store/warehouse pickup locations registered in the OTO portal — used to
     * offer a searchable dropdown when mapping a vendor to its OTO sender. Returns
     * the decoded response (a list of { code, name, city, … }).
     *
     * @return array<string, mixed>
     */
    public function listPickupLocations(): array
    {
        return $this->authedGet(self::PICKUP_LOCATIONS_PATH, ['status' => 'active']);
    }

    /**
     * Register a new pickup/sender location in the OTO portal. Returns the
     * decoded response, e.g.
     * { success: true, pickupLocationCode: "code-01", warhouseId: "123" }.
     * Soft failure (success:false) is surfaced as a transport error.
     *
     * @param array<string, mixed> $body
     * @return array<string, mixed>
     */
    public function createPickupLocation(array $body): array
    {
        $decoded = $this->authedPost(self::CREATE_PICKUP_LOCATION_PATH, $body);

        if (($decoded['success'] ?? null) === false) {
            $msg = (string) ($decoded['message'] ?? 'OTO createPickupLocation rejected the request.');
            throw new ShippingException(ShippingException::KIND_TRANSPORT, "OTO: {$msg}");
        }

        return $decoded;
    }

    /**
     * @param array<string, string> $query
     * @return array<string, mixed>
     */
    private function authedGet(string $path, array $query = []): array
    {
        $token = $this->accessToken();
        $url = $this->baseUrl . $path . ($query !== [] ? '?' . http_build_query($query) : '');

        try {
            $response = $this->http->get($url, [
                'headers' => [
                    'Authorization' => 'Bearer ' . $token,
                    'Accept' => 'application/json',
                ],
                'http_errors' => false,
            ]);
        } catch (ConnectException $e) {
            throw new ShippingException(ShippingException::KIND_NETWORK, $e->getMessage(), $e);
        } catch (GuzzleException $e) {
            throw new ShippingException(ShippingException::KIND_TRANSPORT, $e->getMessage(), $e);
        }

        $status = $response->getStatusCode();
        $raw = (string) $response->getBody();
        if ($status >= 400) {
            $this->logger->error('oto.request_failed', [
                'path' => $path,
                'status' => $status,
                'body' => mb_substr($raw, 0, 500),
            ]);
            throw new ShippingException(
                ShippingException::KIND_TRANSPORT,
                "OTO {$path} returned HTTP {$status}.",
            );
        }

        return $this->decode($raw);
    }

    /**
     * @param array<string, mixed> $body
     * @return array<string, mixed>
     */
    private function authedPost(string $path, array $body): array
    {
        $token = $this->accessToken();

        try {
            $response = $this->http->post($this->baseUrl . $path, [
                'headers' => [
                    'Authorization' => 'Bearer ' . $token,
                    'Accept' => 'application/json',
                ],
                'json' => $body,
                'http_errors' => false,
            ]);
        } catch (ConnectException $e) {
            throw new ShippingException(ShippingException::KIND_NETWORK, $e->getMessage(), $e);
        } catch (GuzzleException $e) {
            throw new ShippingException(ShippingException::KIND_TRANSPORT, $e->getMessage(), $e);
        }

        $status = $response->getStatusCode();
        $raw = (string) $response->getBody();

        if ($status >= 400) {
            $this->logger->error('oto.request_failed', [
                'path' => $path,
                'status' => $status,
                'body' => mb_substr($raw, 0, 500),
            ]);
            throw new ShippingException(
                ShippingException::KIND_TRANSPORT,
                "OTO {$path} returned HTTP {$status}.",
            );
        }

        return $this->decode($raw);
    }

    private function accessToken(): string
    {
        if ($this->cachedAccessToken !== null) {
            return $this->cachedAccessToken;
        }

        try {
            $response = $this->http->post($this->baseUrl . self::AUTH_PATH, [
                'headers' => ['Accept' => 'application/json'],
                'json' => ['refresh_token' => $this->refreshToken],
                'http_errors' => false,
            ]);
        } catch (ConnectException $e) {
            throw new ShippingException(ShippingException::KIND_NETWORK, 'auth: ' . $e->getMessage(), $e);
        } catch (GuzzleException $e) {
            throw new ShippingException(ShippingException::KIND_TRANSPORT, 'auth: ' . $e->getMessage(), $e);
        }

        $status = $response->getStatusCode();
        if ($status >= 400) {
            throw new ShippingException(ShippingException::KIND_AUTH, "OTO auth returned HTTP {$status}.");
        }

        $decoded = $this->decode((string) $response->getBody());
        $token = $decoded['access_token'] ?? null;
        if (!is_string($token) || $token === '') {
            throw new ShippingException(ShippingException::KIND_MALFORMED, 'OTO auth response missing access_token.');
        }

        $this->cachedAccessToken = $token;
        return $token;
    }

    /**
     * @return array<string, mixed>
     */
    private function decode(string $raw): array
    {
        $decoded = json_decode($raw, true);
        if (!is_array($decoded)) {
            throw new ShippingException(
                ShippingException::KIND_MALFORMED,
                'OTO response was not a JSON object: ' . mb_substr($raw, 0, 200),
            );
        }
        /** @var array<string, mixed> $decoded */
        return $decoded;
    }
}
