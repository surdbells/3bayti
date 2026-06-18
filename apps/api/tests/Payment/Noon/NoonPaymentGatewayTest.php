<?php

declare(strict_types=1);

namespace Bayti\Api\Tests\Payment\Noon;

use Bayti\Api\Domain\Order\Order;
use Bayti\Api\Domain\Order\OrderAddress;
use Bayti\Api\Domain\User\User;
use Bayti\Api\Payment\CheckoutInitiation;
use Bayti\Api\Payment\Noon\NoonPaymentGateway;
use Bayti\Api\Payment\OrderStatusResponse;
use Bayti\Api\Payment\PaymentGatewayException;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\TestCase;

final class NoonPaymentGatewayTest extends TestCase
{
    private MockHandler $mock;

    /** @var list<array<string, mixed>> */
    private array $history = [];

    private NoonPaymentGateway $gateway;

    protected function setUp(): void
    {
        $this->mock = new MockHandler();
        $this->history = [];
        $stack = HandlerStack::create($this->mock);
        $stack->push(Middleware::history($this->history));
        $client = new Client(['handler' => $stack]);

        $this->gateway = new NoonPaymentGateway(
            http: $client,
            baseUrl: 'https://api-test.noonpayments.com',
            businessIdentifier: 'biz_abc',
            appIdentifier: 'app_xyz',
            appKey: 'secret_key_123',
            orderCategory: 'pay_category',
        );
    }

    /**
     * Helper: build a minimally-valid Order with billing + shipping
     * addresses attached. Total is auto-computed from subtotal.
     */
    private function buildOrder(string $reference = 'V3-ORDER-001', string $subtotal = '99.50', ?string $lastName = 'Bello'): Order
    {
        $user = new User(
            email: 'sodiq@test.local',
            phone: '500000000',
            passwordHash: 'irrelevant-for-this-test',
        );

        $order = new Order(
            user: $user,
            orderReference: $reference,
            subtotal: $subtotal,
        );

        $billing = new OrderAddress(
            type: OrderAddress::TYPE_BILLING,
            firstName: 'Sodiq',
            phone: '500000000',
            email: 'sodiq@test.local',
            street: '123 Main St',
            city: 'Dubai',
            lastName: $lastName,
        );
        $shipping = new OrderAddress(
            type: OrderAddress::TYPE_SHIPPING,
            firstName: 'Sodiq',
            phone: '500000000',
            email: 'sodiq@test.local',
            street: '123 Main St',
            city: 'Dubai',
            lastName: $lastName,
        );
        $order->addAddress($billing);
        $order->addAddress($shipping);

        return $order;
    }

    public function testInitiateCheckoutHappyPath(): void
    {
        $this->mock->append(new Response(200, [], json_encode([
            'resultCode' => 0,
            'message' => 'Success',
            'result' => [
                'order' => [
                    'id' => '123456789012',
                    'reference' => 'V3-ORDER-001',
                    'amount' => '99.50',
                    'currency' => 'AED',
                    'status' => 'INITIATED',
                ],
                'checkoutData' => [
                    'postUrl' => 'https://api-test.noonpayments.com/checkout/123456789012',
                ],
            ],
        ], JSON_THROW_ON_ERROR)));

        $result = $this->gateway->initiateCheckout(
            $this->buildOrder(),
            'https://api.3bayti.ae/v3/checkout/return/V3-ORDER-001',
            'MOBILE',
        );

        self::assertInstanceOf(CheckoutInitiation::class, $result);
        self::assertSame('123456789012', $result->providerOrderRef);
        self::assertStringContainsString('checkout/123', $result->checkoutUrl);

        // Verify outbound request shape.
        self::assertCount(1, $this->history);
        $sent = $this->history[0]['request'];
        self::assertSame('POST', $sent->getMethod());
        self::assertSame('/payment/v1/order', $sent->getUri()->getPath());

        // Auth header: Key_<Mode> <base64(biz.app:key)>; api-test => Test.
        $authHeader = $sent->getHeaderLine('Authorization');
        self::assertStringStartsWith('Key_Test ', $authHeader);
        $expected = 'Key_Test ' . base64_encode('biz_abc.app_xyz:secret_key_123');
        self::assertSame($expected, $authHeader);

        // Body shape (sanity-check critical fields)
        $body = json_decode((string) $sent->getBody(), true, 64, JSON_THROW_ON_ERROR);
        self::assertSame('INITIATE', $body['apiOperation']);
        self::assertSame('V3-ORDER-001', $body['order']['reference']);
        self::assertSame('MOBILE', $body['order']['channel']);
        self::assertSame('pay_category', $body['order']['category']);
        self::assertSame('Sodiq Bello', $body['order']['name']);
        self::assertSame('SALE', $body['configuration']['paymentAction']);
        // noon rejects our address shape (5019); these blocks are omitted and
        // the address is collected on noon's hosted page instead.
        self::assertArrayNotHasKey('billing', $body);
        self::assertArrayNotHasKey('shipping', $body);
        self::assertArrayNotHasKey('customer', $body);
        self::assertSame(
            'https://api.3bayti.ae/v3/checkout/return/V3-ORDER-001',
            $body['configuration']['returnUrl']
        );
    }

    public function testInitiateCheckoutRejectsReturnUrlWithQueryString(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Noon rejects return URLs with query strings');

        $this->gateway->initiateCheckout(
            $this->buildOrder(),
            'https://api.3bayti.ae/v3/checkout/return?ref=V3-ORDER-001',
            'MOBILE',
        );
    }

    public function testInitiateCheckoutRejectsInvalidChannel(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage("channel must be 'MOBILE' or 'WEB'");

        $this->gateway->initiateCheckout(
            $this->buildOrder(),
            'https://api.3bayti.ae/v3/checkout/return/X',
            'TABLET',
        );
    }

    public function testInitiateCheckoutNormalisesLowercaseChannelToUppercase(): void
    {
        // The web client sends channel 'web' (lowercase); it must be
        // normalised to 'WEB' rather than throwing (which surfaced as a 500).
        $this->mock->append(new Response(200, [], json_encode([
            'resultCode' => 0,
            'message' => 'Success',
            'result' => [
                'order' => ['id' => '123456789012', 'reference' => 'V3-ORDER-001'],
                'checkoutData' => ['postUrl' => 'https://api-test.noonpayments.com/checkout/1'],
            ],
        ], JSON_THROW_ON_ERROR)));

        $this->gateway->initiateCheckout(
            $this->buildOrder(),
            'https://api.3bayti.ae/v3/checkout/return/V3-ORDER-001',
            'web',
        );

        $sent = $this->history[0]['request'];
        $body = json_decode((string) $sent->getBody(), true, 64, JSON_THROW_ON_ERROR);
        self::assertSame('WEB', $body['order']['channel']);
    }

    public function testInitiateCheckoutTrimsOrderNameWhenLastNameMissing(): void
    {
        // No last name must not leave a trailing space in Order.Name (5034).
        $this->mock->append(new Response(200, [], json_encode([
            'resultCode' => 0,
            'message' => 'Success',
            'result' => [
                'order' => ['id' => '123456789012', 'reference' => 'V3-ORDER-001'],
                'checkoutData' => ['postUrl' => 'https://api-test.noonpayments.com/checkout/1'],
            ],
        ], JSON_THROW_ON_ERROR)));

        $this->gateway->initiateCheckout(
            $this->buildOrder(lastName: null),
            'https://api.3bayti.ae/v3/checkout/return/V3-ORDER-001',
            'WEB',
        );

        $sent = $this->history[0]['request'];
        $body = json_decode((string) $sent->getBody(), true, 64, JSON_THROW_ON_ERROR);
        self::assertSame('Sodiq', $body['order']['name']);
    }

    public function testInitiateCheckoutOnDuplicateReferenceRaisesDuplicateRefException(): void
    {
        // Noon resultCode 19012 — caller looks up the existing order.
        $this->mock->append(new Response(200, [], json_encode([
            'resultCode' => 19012,
            'message' => 'Duplicate reference',
            'result' => [
                'order' => ['id' => '999999999999'],
            ],
        ], JSON_THROW_ON_ERROR)));

        try {
            $this->gateway->initiateCheckout(
                $this->buildOrder(),
                'https://api.3bayti.ae/v3/checkout/return/V3-ORDER-001',
                'MOBILE',
            );
            self::fail('Expected PaymentGatewayException');
        } catch (PaymentGatewayException $e) {
            self::assertSame(PaymentGatewayException::KIND_DUPLICATE_REF, $e->kind);
            self::assertSame(19012, $e->providerCode);
            self::assertStringContainsString('999999999999', $e->getMessage());
        }
    }

    public function testInitiateCheckoutOnUnknownErrorRaisesUpstreamException(): void
    {
        $this->mock->append(new Response(200, [], json_encode([
            'resultCode' => 30001,
            'message' => 'Some Noon-specific error',
        ], JSON_THROW_ON_ERROR)));

        try {
            $this->gateway->initiateCheckout(
                $this->buildOrder(),
                'https://api.3bayti.ae/v3/checkout/return/V3-ORDER-001',
                'MOBILE',
            );
            self::fail('Expected PaymentGatewayException');
        } catch (PaymentGatewayException $e) {
            self::assertSame(PaymentGatewayException::KIND_UPSTREAM, $e->kind);
            self::assertSame(30001, $e->providerCode);
        }
    }

    public function testHttp401RaisesAuthException(): void
    {
        $this->mock->append(new Response(401, [], '{"message":"Invalid auth"}'));

        try {
            $this->gateway->retrieveOrder('123456789012');
            self::fail('Expected PaymentGatewayException');
        } catch (PaymentGatewayException $e) {
            self::assertSame(PaymentGatewayException::KIND_AUTH, $e->kind);
        }
    }

    public function testHttp429RaisesRateLimitedException(): void
    {
        $this->mock->append(new Response(429, [], '{"message":"Too many requests"}'));

        try {
            $this->gateway->retrieveOrder('123456789012');
            self::fail('Expected PaymentGatewayException');
        } catch (PaymentGatewayException $e) {
            self::assertSame(PaymentGatewayException::KIND_RATE_LIMITED, $e->kind);
        }
    }

    public function testConnectFailureRaisesNetworkException(): void
    {
        // ConnectException = DNS / TCP failure
        $this->mock->append(new ConnectException(
            'cURL error 7: Failed to connect',
            new Request('POST', 'https://api-test.noonpayments.com/payment/v1/order'),
        ));

        try {
            $this->gateway->retrieveOrder('123456789012');
            self::fail('Expected PaymentGatewayException');
        } catch (PaymentGatewayException $e) {
            self::assertSame(PaymentGatewayException::KIND_NETWORK, $e->kind);
        }
    }

    public function testNonJsonResponseRaisesMalformedException(): void
    {
        $this->mock->append(new Response(200, [], '<html>Maintenance mode</html>'));

        try {
            $this->gateway->retrieveOrder('123456789012');
            self::fail('Expected PaymentGatewayException');
        } catch (PaymentGatewayException $e) {
            self::assertSame(PaymentGatewayException::KIND_MALFORMED, $e->kind);
        }
    }

    public function testRetrieveOrderMapsPaidStatus(): void
    {
        $this->mock->append(new Response(200, [], json_encode([
            'resultCode' => 0,
            'result' => [
                'order' => [
                    'id' => '123456789012',
                    'status' => 'CAPTURED',
                    'amount' => '99.50',
                    'currency' => 'AED',
                ],
            ],
        ], JSON_THROW_ON_ERROR)));

        $result = $this->gateway->retrieveOrder('123456789012');

        self::assertInstanceOf(OrderStatusResponse::class, $result);
        self::assertSame('CAPTURED', $result->status);
        self::assertTrue($result->terminal);
        self::assertTrue($result->paid);
        self::assertSame('99.50', $result->amount);
        self::assertSame('AED', $result->currency);
    }

    public function testRetrieveOrderMapsFailedStatusAsTerminalButNotPaid(): void
    {
        $this->mock->append(new Response(200, [], json_encode([
            'resultCode' => 0,
            'result' => [
                'order' => [
                    'id' => '123456789012',
                    'status' => 'FAILED',
                ],
            ],
        ], JSON_THROW_ON_ERROR)));

        $result = $this->gateway->retrieveOrder('123456789012');
        self::assertSame('FAILED', $result->status);
        self::assertTrue($result->terminal);
        self::assertFalse($result->paid);
    }

    public function testRetrieveOrderMapsInitiatedStatusAsNonTerminal(): void
    {
        $this->mock->append(new Response(200, [], json_encode([
            'resultCode' => 0,
            'result' => [
                'order' => [
                    'id' => '123456789012',
                    'status' => 'INITIATED',
                ],
            ],
        ], JSON_THROW_ON_ERROR)));

        $result = $this->gateway->retrieveOrder('123456789012');
        self::assertFalse($result->terminal);
        self::assertFalse($result->paid);
    }

    public function testRetrieveOrderUsesGetOrderApiOperation(): void
    {
        $this->mock->append(new Response(200, [], json_encode([
            'resultCode' => 0,
            'result' => ['order' => ['id' => '123', 'status' => 'CAPTURED']],
        ], JSON_THROW_ON_ERROR)));

        $this->gateway->retrieveOrder('123');

        $body = json_decode((string) $this->history[0]['request']->getBody(), true);
        self::assertSame('GET_ORDER', $body['apiOperation']);
        self::assertSame('123', $body['order']['id']);
    }

    public function testRetrieveOrderByReferenceUsesGetOrderByReferenceApiOperation(): void
    {
        $this->mock->append(new Response(200, [], json_encode([
            'resultCode' => 0,
            'result' => ['order' => ['id' => '123', 'reference' => 'V3-ORDER-001', 'status' => 'CAPTURED']],
        ], JSON_THROW_ON_ERROR)));

        $this->gateway->retrieveOrderByReference('V3-ORDER-001');

        $body = json_decode((string) $this->history[0]['request']->getBody(), true);
        self::assertSame('GET_ORDER_BY_REFERENCE', $body['apiOperation']);
        self::assertSame('V3-ORDER-001', $body['order']['reference']);
    }

    public function testRefundUsesRefundApiOperation(): void
    {
        $this->mock->append(new Response(200, [], json_encode([
            'resultCode' => 0,
            'result' => ['order' => ['id' => '123', 'status' => 'REFUNDED']],
        ], JSON_THROW_ON_ERROR)));

        $this->gateway->refund('123', '50.00', 'AED', 'Customer dispute');

        $body = json_decode((string) $this->history[0]['request']->getBody(), true);
        self::assertSame('REFUND', $body['apiOperation']);
        self::assertSame('50.00', $body['transaction']['amount']);
        self::assertSame('Customer dispute', $body['transaction']['description']);
    }

    public function testCancelUsesCancelApiOperation(): void
    {
        $this->mock->append(new Response(200, [], json_encode([
            'resultCode' => 0,
            'result' => ['order' => ['id' => '123', 'status' => 'CANCELLED']],
        ], JSON_THROW_ON_ERROR)));

        $this->gateway->cancel('123', 'User abandoned');

        $body = json_decode((string) $this->history[0]['request']->getBody(), true);
        self::assertSame('CANCEL', $body['apiOperation']);
    }

    public function testConstructorRejectsEmptyCredentials(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('non-empty');

        new NoonPaymentGateway(
            http: new Client(),
            baseUrl: 'https://api-test.noonpayments.com',
            businessIdentifier: '',
            appIdentifier: 'app_xyz',
            appKey: 'secret',
            orderCategory: 'pay_category',
        );
    }

    public function testConstructorRejectsEmptyOrderCategory(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('order category');

        new NoonPaymentGateway(
            http: new Client(),
            baseUrl: 'https://api-test.noonpayments.com',
            businessIdentifier: 'biz_abc',
            appIdentifier: 'app_xyz',
            appKey: 'secret',
            orderCategory: '',
        );
    }

    public function testLiveBaseUrlDerivesKeyLiveScheme(): void
    {
        $gateway = new NoonPaymentGateway(
            http: new Client(),
            baseUrl: 'https://api.noonpayments.com',
            businessIdentifier: 'biz_abc',
            appIdentifier: 'app_xyz',
            appKey: 'secret_key_123',
            orderCategory: 'pay_category',
        );
        $ref = new \ReflectionProperty(NoonPaymentGateway::class, 'authHeaderValue');
        $ref->setAccessible(true);
        self::assertStringStartsWith('Key_Live ', $ref->getValue($gateway));
    }

    public function testExplicitModeOverridesBaseUrlDerivation(): void
    {
        // Live host but explicit Test mode → Key_Test wins.
        $gateway = new NoonPaymentGateway(
            http: new Client(),
            baseUrl: 'https://api.noonpayments.com',
            businessIdentifier: 'biz_abc',
            appIdentifier: 'app_xyz',
            appKey: 'secret_key_123',
            orderCategory: 'pay_category',
            mode: 'test',
        );
        $ref = new \ReflectionProperty(NoonPaymentGateway::class, 'authHeaderValue');
        $ref->setAccessible(true);
        self::assertStringStartsWith('Key_Test ', $ref->getValue($gateway));
    }
}
