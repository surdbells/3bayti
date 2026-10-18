<?php

declare(strict_types=1);

namespace Bayti\Api\Tests\Infrastructure\Otp;

use Bayti\Api\Infrastructure\Otp\OtpProviderException;
use Bayti\Api\Infrastructure\Otp\OtpProviderFailureClassifier;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Account-problem classification. The fixtures are the exact shapes the
 * providers raise (MessageCentralOtpProvider / CequensOtpProvider build
 * "upstream: HTTP <status>: <body>" and "unauthorized: auth HTTP <status>: <body>").
 */
#[CoversClass(OtpProviderFailureClassifier::class)]
#[CoversClass(OtpProviderException::class)]
final class OtpProviderFailureClassifierTest extends TestCase
{
    /** The real Oct 2026 prod reply (Sentry). */
    private const MC_508_BODY = '{"responseCode":508,"message":"insufficient credits","data":{"responseCode":"508","errorMessage":"insufficient credits"}}';

    /**
     * @return iterable<string, array{0: OtpProviderException, 1: string, 2: string|null}>
     */
    public static function cases(): iterable
    {
        // --- insufficient_credit ---------------------------------------
        yield 'messagecentral 508 body (prod Oct 2026)' => [
            new OtpProviderException('upstream', 'HTTP 400: ' . self::MC_508_BODY),
            'messagecentral',
            OtpProviderFailureClassifier::INSUFFICIENT_CREDIT,
        ];
        yield '508 code alone, no text' => [
            new OtpProviderException('upstream', 'HTTP 400: {"responseCode":508,"message":"x"}'),
            'messagecentral',
            OtpProviderFailureClassifier::INSUFFICIENT_CREDIT,
        ];
        yield '508 nested as a string only' => [
            new OtpProviderException('upstream', 'HTTP 400: {"data":{"responseCode":"508"}}'),
            'messagecentral',
            OtpProviderFailureClassifier::INSUFFICIENT_CREDIT,
        ];
        yield '508 in a body the adapter truncated' => [
            new OtpProviderException('upstream', 'HTTP 400: {"responseCode":508,"message":"x","data":{"respon...'),
            'messagecentral',
            OtpProviderFailureClassifier::INSUFFICIENT_CREDIT,
        ];
        yield '"insufficient credits" text, non-JSON body' => [
            new OtpProviderException('upstream', 'HTTP 400: Insufficient Credits'),
            'messagecentral',
            OtpProviderFailureClassifier::INSUFFICIENT_CREDIT,
        ];
        yield 'credit text wins over a 5xx status' => [
            new OtpProviderException('upstream', 'HTTP 500: {"message":"insufficient credits"}'),
            'messagecentral',
            OtpProviderFailureClassifier::INSUFFICIENT_CREDIT,
        ];
        yield 'credit reply on a 200 without verificationId' => [
            new OtpProviderException('missing_id', 'Response did not contain verificationId: ' . self::MC_508_BODY),
            'messagecentral',
            OtpProviderFailureClassifier::INSUFFICIENT_CREDIT,
        ];
        yield 'cequens insufficient balance' => [
            new OtpProviderException('upstream', 'HTTP 400: {"replyCode":1,"replyMessage":"Insufficient balance"}'),
            'cequens',
            OtpProviderFailureClassifier::INSUFFICIENT_CREDIT,
        ];
        yield 'cequens low balance' => [
            new OtpProviderException('upstream', 'HTTP 403: {"replyMessage":"Your balance is too low"}'),
            'cequens',
            OtpProviderFailureClassifier::INSUFFICIENT_CREDIT,
        ];
        yield 'HTTP 402 payment required' => [
            new OtpProviderException('upstream', 'HTTP 402: {}'),
            'cequens',
            OtpProviderFailureClassifier::INSUFFICIENT_CREDIT,
        ];

        // --- pricing_not_configured ------------------------------------
        yield 'messagecentral Pricing not found (prod Sept 30 / Oct 7 2026)' => [
            new OtpProviderException('upstream', 'HTTP 400: {"responseCode":400,"message":"Pricing not found"}'),
            'messagecentral',
            OtpProviderFailureClassifier::PRICING_NOT_CONFIGURED,
        ];
        yield 'pricing not found, any case' => [
            new OtpProviderException('upstream', 'HTTP 400: PRICING NOT FOUND'),
            'messagecentral',
            OtpProviderFailureClassifier::PRICING_NOT_CONFIGURED,
        ];
        yield 'channel not enabled' => [
            new OtpProviderException('upstream', 'HTTP 400: {"replyMessage":"Channel is not enabled for this account"}'),
            'cequens',
            OtpProviderFailureClassifier::PRICING_NOT_CONFIGURED,
        ];
        yield 'route not configured' => [
            new OtpProviderException('upstream', 'HTTP 422: route not configured'),
            'cequens',
            OtpProviderFailureClassifier::PRICING_NOT_CONFIGURED,
        ];

        // --- credentials_rejected --------------------------------------
        yield 'messagecentral auth step 4xx (unauthorized kind)' => [
            new OtpProviderException('unauthorized', 'auth HTTP 400: {"message":"Invalid customerId"}'),
            'messagecentral',
            OtpProviderFailureClassifier::CREDENTIALS_REJECTED,
        ];
        yield 'cequens auth 401 (unauthorized kind)' => [
            new OtpProviderException('unauthorized', 'auth HTTP 401: {"replyCode":1,"message":"bad key"}'),
            'cequens',
            OtpProviderFailureClassifier::CREDENTIALS_REJECTED,
        ];
        yield 'send step HTTP 401' => [
            new OtpProviderException('upstream', 'HTTP 401: {"message":"token rejected"}'),
            'messagecentral',
            OtpProviderFailureClassifier::CREDENTIALS_REJECTED,
        ];
        yield 'send step HTTP 403' => [
            new OtpProviderException('upstream', 'HTTP 403: forbidden'),
            'cequens',
            OtpProviderFailureClassifier::CREDENTIALS_REJECTED,
        ];
        yield 'account suspended text on a 400' => [
            new OtpProviderException('upstream', 'HTTP 400: {"message":"Account is suspended"}'),
            'messagecentral',
            OtpProviderFailureClassifier::CREDENTIALS_REJECTED,
        ];
        yield 'messagecentral SCREAMING_SNAKE invalid customer id on the send step' => [
            new OtpProviderException('upstream', 'HTTP 400: {"responseCode":501,"message":"INVALID_CUSTOMER_ID"}'),
            'messagecentral',
            OtpProviderFailureClassifier::CREDENTIALS_REJECTED,
        ];
        yield 'messagecentral auth body without a token (malformed kind)' => [
            new OtpProviderException('malformed', 'auth response missing token: {"responseCode":501,"message":"INVALID_CUSTOMER_ID"}'),
            'messagecentral',
            OtpProviderFailureClassifier::CREDENTIALS_REJECTED,
        ];
        yield 'messagecentral 501 code alone' => [
            new OtpProviderException('upstream', 'HTTP 400: {"responseCode":501,"message":"x"}'),
            'messagecentral',
            OtpProviderFailureClassifier::CREDENTIALS_REJECTED,
        ];
        yield 'snake case credit reply' => [
            new OtpProviderException('upstream', 'HTTP 400: {"status":"INSUFFICIENT_CREDITS"}'),
            'cequens',
            OtpProviderFailureClassifier::INSUFFICIENT_CREDIT,
        ];
        yield 'snake case pricing reply' => [
            new OtpProviderException('upstream', 'HTTP 400: {"message":"PRICING_NOT_FOUND"}'),
            'messagecentral',
            OtpProviderFailureClassifier::PRICING_NOT_CONFIGURED,
        ];

        // --- no_provider -----------------------------------------------
        yield 'router had no provider' => [
            new OtpProviderException('no_provider', 'No OTP provider is available to send the code.'),
            'none',
            OtpProviderFailureClassifier::NO_PROVIDER,
        ];

        // --- NOT an account problem → null -----------------------------
        yield 'network error' => [
            new OtpProviderException('network', 'cURL error 28: Operation timed out after 10001 milliseconds'),
            'messagecentral',
            null,
        ];
        yield 'network error on auth' => [
            new OtpProviderException('network', 'auth: cURL error 7: Failed to connect'),
            'messagecentral',
            null,
        ];
        yield 'transport error' => [
            new OtpProviderException('transport', 'cURL error 35: SSL connect error'),
            'cequens',
            null,
        ];
        yield 'network text never matches even with credit words' => [
            new OtpProviderException('network', 'insufficient credits (proxy message)'),
            'messagecentral',
            null,
        ];
        yield 'provider 5xx' => [
            new OtpProviderException('upstream', 'HTTP 503: {"message":"Service Unavailable"}'),
            'messagecentral',
            null,
        ];
        yield 'auth endpoint 5xx is an outage, not bad credentials' => [
            new OtpProviderException('unauthorized', 'auth HTTP 502: Bad Gateway'),
            'messagecentral',
            null,
        ];
        yield 'rate limited' => [
            new OtpProviderException('upstream', 'HTTP 429: {"message":"Too many requests"}'),
            'messagecentral',
            null,
        ];
        yield 'invalid phone number' => [
            new OtpProviderException('upstream', 'HTTP 400: {"responseCode":511,"message":"Invalid mobile number"}'),
            'messagecentral',
            null,
        ];
        yield 'unsupported country' => [
            new OtpProviderException('unsupported_country', 'Unsupported country dial code for OTP delivery.'),
            'messagecentral',
            null,
        ];
        yield 'malformed body' => [
            new OtpProviderException('malformed', 'Response was not JSON: <html><body>502 Bad Gateway</body></html>'),
            'messagecentral',
            null,
        ];
        yield 'malformed / truncated JSON with no account signal' => [
            new OtpProviderException('upstream', 'HTTP 400: {"responseCode":5'),
            'messagecentral',
            null,
        ];
        yield 'missing id with an ordinary body' => [
            new OtpProviderException('missing_id', 'Response did not contain verificationId: {"responseCode":200,"data":{}}'),
            'messagecentral',
            null,
        ];
        yield '508 is MessageCentral-specific: ignored for cequens' => [
            new OtpProviderException('upstream', 'HTTP 400: {"responseCode":508}'),
            'cequens',
            null,
        ];
        yield '501 is MessageCentral-specific: ignored for cequens' => [
            new OtpProviderException('upstream', 'HTTP 400: {"responseCode":501}'),
            'cequens',
            null,
        ];
        yield 'messagecentral REQUEST_ALREADY_EXISTS (506) is not an account problem' => [
            new OtpProviderException('upstream', 'HTTP 400: {"responseCode":506,"message":"REQUEST_ALREADY_EXISTS"}'),
            'messagecentral',
            null,
        ];
        yield 'email transport (email OTP path)' => [
            new OtpProviderException('email_transport', 'ZeptoMail network error'),
            'messagecentral',
            null,
        ];
    }

    #[Test]
    #[DataProvider('cases')]
    public function classifiesProviderFailures(OtpProviderException $e, string $provider, ?string $expected): void
    {
        self::assertSame($expected, (new OtpProviderFailureClassifier())->classify($e, $provider));
    }

    #[Test]
    public function exceptionExposesItsKindWithoutChangingTheMessage(): void
    {
        $e = new OtpProviderException('upstream', 'HTTP 400: x');
        self::assertSame('upstream', $e->kind);
        self::assertSame('upstream: HTTP 400: x', $e->getMessage());

        $bare = new OtpProviderException('no_provider');
        self::assertSame('no_provider', $bare->kind);
        self::assertSame('no_provider', $bare->getMessage());
    }

    #[Test]
    public function the508CodeAlsoAppliesWhenTheProviderIsUnknown(): void
    {
        $e = new OtpProviderException('upstream', 'HTTP 400: {"responseCode":508}');
        self::assertSame(OtpProviderFailureClassifier::INSUFFICIENT_CREDIT, (new OtpProviderFailureClassifier())->classify($e));
    }
}
