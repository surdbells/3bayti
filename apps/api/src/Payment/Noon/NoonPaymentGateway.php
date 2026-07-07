<?php

declare(strict_types=1);

namespace Bayti\Api\Payment\Noon;

use Bayti\Api\Domain\Order\Order;
use Bayti\Api\Payment\CheckoutInitiation;
use Bayti\Api\Payment\OrderStatusResponse;
use Bayti\Api\Payment\PaymentGatewayException;
use Bayti\Api\Payment\PaymentGatewayInterface;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Exception\GuzzleException;
use GuzzleHttp\Exception\RequestException;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * Noon Payments adapter — implements PaymentGatewayInterface
 * against api.noonpayments.com.
 *
 * Reference: docs.noonpayments.com (recon captured in
 * docs/runbooks/m3/m3.1.6-noon-api-reference.md after M3.1.6
 * closure).
 *
 * Endpoint
 * --------
 * Single endpoint handles all operations:
 *   POST {baseUrl}/payment/v1/order
 *
 * Operations are differentiated by the JSON body's `apiOperation`
 * field (INITIATE / SALE / AUTHORIZE / CAPTURE / REVERSE / REFUND
 * / CANCEL / GET_ORDER / GET_ORDER_BY_REFERENCE).
 *
 * Authentication
 * --------------
 * Header: `Authorization: Key <base64({biz}.{app}:{key})>`
 * Source: docs.noonpayments.com/sdk/reference/ios — verbatim example
 *   `paymentRequest.authHeader = "Key e0J1c2luZXNzSWRlbnRpZmllcn0..."`
 *
 * Three identifiers required:
 *   - NOON_BUSINESS_IDENTIFIER — merchant account identifier
 *   - NOON_APP_IDENTIFIER      — application within the merchant
 *   - NOON_APP_KEY             — the secret key
 *
 * Back-compat: if NOON_APP_KEY is unset, falls back to the legacy
 * NOON_API_KEY with a deprecation log line.
 *
 * Error handling
 * --------------
 * - Connection timeout / refused → PaymentGatewayException::network
 * - Read timeout                  → PaymentGatewayException::timeout
 * - HTTP 401/403                  → PaymentGatewayException::auth
 * - HTTP 429                      → PaymentGatewayException::rateLimited
 * - Body parse failure            → PaymentGatewayException::malformed
 * - resultCode 19012              → PaymentGatewayException::duplicateRef
 *                                    (caller looks up existing order via
 *                                    retrieveOrderByReference)
 * - Other non-zero resultCodes    → PaymentGatewayException::upstream
 *
 * Polling safety
 * --------------
 * Noon explicitly bans IPs that abuse GET_ORDER polling
 * (docs.noonpayments.com/test/evaluating-api). retrieveOrder()
 * is called at most ONCE per state transition by NoonWebhookController
 * — never in a loop. GetCheckoutStatusController reads v3's cached
 * order state, never Noon's.
 */
final class NoonPaymentGateway implements PaymentGatewayInterface
{
    private const PROVIDER_NAME = 'noon';
    private const PATH = '/payment/v1/order';
    private const DEFAULT_CONNECT_TIMEOUT = 5.0;
    private const DEFAULT_REQUEST_TIMEOUT = 15.0;

    private readonly string $authHeaderValue;

    public function __construct(
        private readonly Client $http,
        private readonly string $baseUrl,
        string $businessIdentifier,
        string $appIdentifier,
        string $appKey,
        private readonly string $orderCategory,
        ?string $mode = null,
        private readonly LoggerInterface $logger = new NullLogger(),
    ) {
        if ($businessIdentifier === '' || $appIdentifier === '' || $appKey === '') {
            throw new \InvalidArgumentException(
                'NoonPaymentGateway requires non-empty business/app/key identifiers. '
                . 'Set NOON_BUSINESS_IDENTIFIER, NOON_APP_IDENTIFIER, NOON_APP_KEY.'
            );
        }
        if ($orderCategory === '') {
            throw new \InvalidArgumentException(
                'NoonPaymentGateway requires a non-empty order category. '
                . 'Set NOON_ORDER_CATEGORY to a category pre-configured on your noon account.'
            );
        }
        // Resolve Test|Live. Explicit NOON_MODE wins; otherwise derive from
        // the API host (api-test* => Test, anything else => Live).
        $resolvedMode = ($mode !== null && trim($mode) !== '')
            ? ucfirst(strtolower(trim($mode)))
            : (str_contains($baseUrl, 'api-test') ? 'Test' : 'Live');
        if ($resolvedMode !== 'Test' && $resolvedMode !== 'Live') {
            throw new \InvalidArgumentException(
                "NOON_MODE must be 'Test' or 'Live', got '{$mode}'."
            );
        }
        // noon's server Order API authenticates with a MODE-qualified scheme:
        //   Authorization: Key_<Mode> <base64(biz.app:key)>
        // (The iOS SDK shows a bare "Key …"; the server API rejects that with
        // resultCode 1500 "Invalid credentials".)
        $this->authHeaderValue = 'Key_' . $resolvedMode . ' ' . base64_encode(
            "{$businessIdentifier}.{$appIdentifier}:{$appKey}"
        );
    }

    public function initiateCheckout(
        Order $order,
        string $returnUrl,
        string $channel,
    ): CheckoutInitiation {
        /* Normalise casing so a lowercase channel from any caller is
           corrected rather than throwing a raw InvalidArgumentException
           (which would surface as a 500, not a clean validation error). */
        $channel = strtoupper(trim($channel));
        $this->guardChannel($channel);
        $this->guardReturnUrl($returnUrl);

        $billing = $order->getBillingAddress();
        $shipping = $order->getShippingAddress();

        if ($billing === null || $shipping === null) {
            throw new \InvalidArgumentException(
                'Order must have both billing and shipping addresses before initiating checkout.'
            );
        }

        // noon's Order.Name (resultCode 5034) rejects empty or
        // whitespace-padded values. firstName is guaranteed non-empty by
        // OrderAddress; collapse internal whitespace and trim so a null
        // lastName doesn't leave a trailing space. Fall back defensively so a
        // pathological all-whitespace name can never reach noon.
        $rawName = $billing->getFirstName() . ' ' . ($billing->getLastName() ?? '');
        $payerName = trim((string) preg_replace('/\s+/', ' ', $rawName));
        if ($payerName === '') {
            $payerName = 'Customer';
        }

        $body = [
            'apiOperation' => 'INITIATE',
            'order' => [
                'reference' => $order->getOrderReference(),
                // Charge what is actually due at the gateway: total minus any
                // gift-card credit already applied to this order. Using
                // getTotal() double-charged a buyer who part-paid with a gift
                // card (card debited AND full total charged at Noon).
                'amount' => $order->gatewayChargeAmount(),
                'currency' => $order->getCurrency(),
                'channel' => $channel,
                'category' => $this->orderCategory,
                'name' => $payerName,
            ],
            'configuration' => [
                'returnUrl' => $returnUrl,
                'paymentAction' => 'SALE',
            ],
            // billing / shipping / customer are intentionally NOT sent.
            // noon's hosted checkout collects the payer + address on its own
            // page, and its strict billing schema rejects our address shape
            // (resultCode 5019 "Member 'street' is not allowed"). The full
            // address is retained in our OrderAddress snapshot; an INITIATE
            // with order + configuration only is the verified-good request
            // (the exact shape that returned resultCode 0 from noon).
        ];

        $response = $this->call($body, 'INITIATE');

        $resultCode = $this->extractResultCode($response);

        if (NoonResultCodes::isDuplicateReference($resultCode)) {
            $existing = $this->extractExistingOrderDetails($response);
            throw PaymentGatewayException::duplicateRef(
                $resultCode,
                "Reference {$order->getOrderReference()} already exists at Noon"
                . ($existing !== '' ? "; existing orderId={$existing}" : '')
            );
        }

        if (!NoonResultCodes::isSuccess($resultCode)) {
            throw PaymentGatewayException::upstream(
                $resultCode,
                $this->extractMessage($response),
            );
        }

        $checkoutUrl = $this->extractCheckoutUrl($response);
        $providerOrderRef = $this->extractProviderOrderRef($response);

        return new CheckoutInitiation(
            checkoutUrl: $checkoutUrl,
            providerOrderRef: $providerOrderRef,
            rawResponse: $response,
        );
    }

    public function retrieveOrder(string $providerOrderRef): OrderStatusResponse
    {
        $body = [
            'apiOperation' => 'GET_ORDER',
            'order' => [
                'id' => $providerOrderRef,
            ],
        ];
        $response = $this->call($body, 'GET_ORDER');
        return $this->buildOrderStatus($response, $providerOrderRef);
    }

    public function retrieveOrderByReference(string $merchantReference): OrderStatusResponse
    {
        $body = [
            'apiOperation' => 'GET_ORDER_BY_REFERENCE',
            'order' => [
                'reference' => $merchantReference,
            ],
        ];
        $response = $this->call($body, 'GET_ORDER_BY_REFERENCE');
        $providerOrderRef = $this->extractProviderOrderRef($response);
        return $this->buildOrderStatus($response, $providerOrderRef);
    }

    public function refund(
        string $providerOrderRef,
        string $amount,
        string $currency,
        string $reason,
    ): OrderStatusResponse {
        $body = [
            'apiOperation' => 'REFUND',
            'order' => [
                'id' => $providerOrderRef,
            ],
            'transaction' => [
                'amount' => $amount,
                'currency' => $currency,
                'description' => $reason,
            ],
        ];
        $response = $this->call($body, 'REFUND');
        return $this->buildOrderStatus($response, $providerOrderRef);
    }

    public function cancel(string $providerOrderRef, string $reason): OrderStatusResponse
    {
        $body = [
            'apiOperation' => 'CANCEL',
            'order' => [
                'id' => $providerOrderRef,
            ],
            'transaction' => [
                'description' => $reason,
            ],
        ];
        $response = $this->call($body, 'CANCEL');
        return $this->buildOrderStatus($response, $providerOrderRef);
    }

    // -----------------------------------------------------------------
    // Internals
    // -----------------------------------------------------------------

    /**
     * @param array<string, mixed> $body
     *
     * @return array<string, mixed> Parsed response body
     */
    private function call(array $body, string $opLabel): array
    {
        try {
            $response = $this->http->request('POST', $this->baseUrl . self::PATH, [
                'headers' => [
                    'Authorization' => $this->authHeaderValue,
                    'Content-Type' => 'application/json',
                    'Accept' => 'application/json',
                ],
                'json' => $body,
                'connect_timeout' => self::DEFAULT_CONNECT_TIMEOUT,
                'timeout' => self::DEFAULT_REQUEST_TIMEOUT,
                'http_errors' => false,
            ]);
        } catch (ConnectException $e) {
            // Connection refused / DNS failure / connect timeout
            throw PaymentGatewayException::network(
                "Noon {$opLabel}: connection failed",
                $e,
            );
        } catch (RequestException $e) {
            // Read timeout, broken response, etc. — treat as timeout
            // because we don't know if Noon processed it.
            throw PaymentGatewayException::timeout(
                "Noon {$opLabel}: request failed without response",
                $e,
            );
        } catch (GuzzleException $e) {
            throw PaymentGatewayException::network(
                "Noon {$opLabel}: unexpected Guzzle failure: " . $e->getMessage(),
                $e,
            );
        }

        $status = $response->getStatusCode();
        $raw = (string) $response->getBody();

        // Auth + rate-limit short-circuits — these have specific
        // semantics independent of the body
        if ($status === 401 || $status === 403) {
            $this->logger->error('Noon authentication rejected', [
                'op' => $opLabel,
                'status' => $status,
                'body_excerpt' => substr($raw, 0, 200),
            ]);
            throw PaymentGatewayException::auth(
                "Noon {$opLabel}: HTTP {$status} authentication rejected"
            );
        }

        if ($status === 429) {
            throw PaymentGatewayException::rateLimited(
                "Noon {$opLabel}: HTTP 429 rate limited — back off"
            );
        }

        // Parse JSON. Noon returns JSON for both 2xx and most 4xx/5xx.
        try {
            /** @var mixed $parsed */
            $parsed = json_decode($raw, true, 64, JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            $this->logger->warning('Noon returned non-JSON body', [
                'op' => $opLabel,
                'status' => $status,
                'body_excerpt' => substr($raw, 0, 200),
            ]);
            throw PaymentGatewayException::malformed(
                "Noon {$opLabel}: response body is not valid JSON (HTTP {$status})",
                $e,
            );
        }

        if (!is_array($parsed)) {
            throw PaymentGatewayException::malformed(
                "Noon {$opLabel}: response is not a JSON object (HTTP {$status})"
            );
        }

        /** @var array<string, mixed> $parsed */
        return $parsed;
    }

    /**
     * @param array<string, mixed> $response
     */
    private function extractResultCode(array $response): int
    {
        if (!isset($response['resultCode'])) {
            throw PaymentGatewayException::malformed(
                'Noon response missing resultCode field'
            );
        }
        if (!is_int($response['resultCode']) && !is_string($response['resultCode'])) {
            throw PaymentGatewayException::malformed(
                'Noon response resultCode has unexpected type'
            );
        }
        return (int) $response['resultCode'];
    }

    /**
     * @param array<string, mixed> $response
     */
    private function extractMessage(array $response): string
    {
        if (isset($response['message']) && is_string($response['message'])) {
            return $response['message'];
        }
        if (isset($response['resultMessage']) && is_string($response['resultMessage'])) {
            return $response['resultMessage'];
        }
        return 'no message';
    }

    /**
     * Extract the hosted-checkout URL the user must be redirected to.
     * Path: result.checkoutData.postUrl (confirmed via Noon hosted-checkout
     * docs + mobile checkout.page.ts).
     *
     * @param array<string, mixed> $response
     */
    private function extractCheckoutUrl(array $response): string
    {
        $result = $response['result'] ?? null;
        if (!is_array($result)) {
            throw PaymentGatewayException::malformed(
                'Noon INITIATE response missing result block'
            );
        }
        $checkoutData = $result['checkoutData'] ?? null;
        if (!is_array($checkoutData)) {
            throw PaymentGatewayException::malformed(
                'Noon INITIATE response missing result.checkoutData'
            );
        }
        $url = $checkoutData['postUrl'] ?? null;
        if (!is_string($url) || $url === '') {
            throw PaymentGatewayException::malformed(
                'Noon INITIATE response missing result.checkoutData.postUrl'
            );
        }
        return $url;
    }

    /**
     * Extract Noon's order.id from the response. Path: result.order.id.
     *
     * @param array<string, mixed> $response
     */
    private function extractProviderOrderRef(array $response): string
    {
        $result = $response['result'] ?? null;
        if (!is_array($result)) {
            throw PaymentGatewayException::malformed(
                'Noon response missing result block'
            );
        }
        $order = $result['order'] ?? null;
        if (!is_array($order)) {
            throw PaymentGatewayException::malformed(
                'Noon response missing result.order block'
            );
        }
        $id = $order['id'] ?? null;
        // Noon docs warn: "global endpoint uses a 12-digit number,
        // local endpoints (KSA, EGY) use 16-digit number. Exercise
        // caution when handling bigint values as certain
        // programming languages, such as JavaScript, have
        // limitations when parsing JSON objects containing bigint."
        // PHP int is 64-bit on most platforms so we can hold either,
        // but we store as string for consistency with Noon's own
        // recommendation and downstream JSON marshalling safety.
        if (is_int($id)) {
            return (string) $id;
        }
        if (is_string($id) && $id !== '') {
            return $id;
        }
        throw PaymentGatewayException::malformed(
            'Noon response missing result.order.id'
        );
    }

    /**
     * For duplicate-reference rejections, attempt to extract the
     * existing order's id so the caller has a hint when logging.
     *
     * @param array<string, mixed> $response
     */
    private function extractExistingOrderDetails(array $response): string
    {
        try {
            return $this->extractProviderOrderRef($response);
        } catch (PaymentGatewayException) {
            return '';
        }
    }

    /**
     * @param array<string, mixed> $response
     */
    private function buildOrderStatus(array $response, string $providerOrderRef): OrderStatusResponse
    {
        $resultCode = $this->extractResultCode($response);

        if (!NoonResultCodes::isSuccess($resultCode)) {
            throw PaymentGatewayException::upstream(
                $resultCode,
                $this->extractMessage($response),
            );
        }

        $result = $response['result'] ?? null;
        if (!is_array($result)) {
            throw PaymentGatewayException::malformed('Noon response missing result block');
        }
        $order = $result['order'] ?? null;
        if (!is_array($order)) {
            throw PaymentGatewayException::malformed('Noon response missing result.order');
        }

        $status = $order['status'] ?? '';
        if (!is_string($status)) {
            $status = '';
        }

        // Terminal-state detection matches Noon's documented statuses.
        // CAPTURED / AUTHORIZED+CAPTURED = paid.
        // FAILED / EXPIRED / CANCELLED   = terminal-non-paid.
        // INITIATED / AUTHORIZED         = transient (still working).
        // Mapping is conservative — anything we don't recognise is
        // treated as non-terminal to avoid silently marking orders
        // paid in error.
        $statusUpper = strtoupper($status);
        $terminal = in_array($statusUpper, [
            'CAPTURED', 'PAID', 'FAILED', 'EXPIRED', 'CANCELLED', 'CANCELED', 'REFUNDED',
        ], true);
        $paid = in_array($statusUpper, ['CAPTURED', 'PAID'], true);

        // amount + currency: prefer order.amount, fall back to total fields.
        $amount = $this->extractStringField($order, ['amount', 'totalAmount']) ?? '0';
        $currency = $this->extractStringField($order, ['currency']) ?? 'AED';

        return new OrderStatusResponse(
            providerOrderRef: $providerOrderRef,
            status: $status,
            terminal: $terminal,
            paid: $paid,
            amount: $amount,
            currency: $currency,
            rawResponse: $response,
        );
    }

    /**
     * @param array<string, mixed> $haystack
     * @param list<string>         $keys
     */
    private function extractStringField(array $haystack, array $keys): ?string
    {
        foreach ($keys as $k) {
            if (!isset($haystack[$k])) {
                continue;
            }
            $v = $haystack[$k];
            if (is_string($v)) {
                return $v;
            }
            if (is_int($v) || is_float($v)) {
                return (string) $v;
            }
        }
        return null;
    }

    private function guardChannel(string $channel): void
    {
        if ($channel !== 'MOBILE' && $channel !== 'WEB') {
            throw new \InvalidArgumentException(
                "Noon channel must be 'MOBILE' or 'WEB', got '{$channel}'"
            );
        }
    }

    private function guardReturnUrl(string $returnUrl): void
    {
        if ($returnUrl === '') {
            throw new \InvalidArgumentException('returnUrl cannot be empty.');
        }
        // Noon constraint: 'Query string parameters on the return URL
        // are not allowed' (docs.noonpayments.com hosted-checkout pages).
        if (str_contains($returnUrl, '?')) {
            throw new \InvalidArgumentException(
                'Noon rejects return URLs with query strings. Use a path-based URL '
                . "(received: {$returnUrl})"
            );
        }
    }

    /**
     * @return array<string, string|null>
     */
    public static function getProviderName(): string
    {
        return self::PROVIDER_NAME;
    }
}
