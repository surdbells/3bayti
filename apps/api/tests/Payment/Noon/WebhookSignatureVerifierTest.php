<?php

declare(strict_types=1);

namespace Bayti\Api\Tests\Payment\Noon;

use Bayti\Api\Payment\Noon\HmacSha256SignatureVerifier;
use Bayti\Api\Payment\Noon\LoggingOnlyVerifier;
use PHPUnit\Framework\TestCase;

final class WebhookSignatureVerifierTest extends TestCase
{
    public function testLoggingOnlyAlwaysAccepts(): void
    {
        $verifier = new LoggingOnlyVerifier();
        self::assertTrue($verifier->verify('{"event":"PAID"}', 'whatever-signature'));
        self::assertTrue($verifier->verify('{"event":"PAID"}', null));
        self::assertTrue($verifier->verify('', null));
    }

    public function testLoggingOnlyAcceptsEmptyBodyEmptySignature(): void
    {
        // Defensive: even adversarial inputs should never throw,
        // so the controller can apply the retrieve-order safety
        // net regardless.
        $verifier = new LoggingOnlyVerifier();
        self::assertTrue($verifier->verify('', ''));
    }

    public function testHmacSha256VerifiesValidSignature(): void
    {
        $secret = 'noon-test-shared-secret';
        $body = '{"eventId":"e-123","orderId":"o-456","status":"CAPTURED"}';
        $expected = hash_hmac('sha256', $body, $secret);

        $verifier = new HmacSha256SignatureVerifier($secret);
        self::assertTrue($verifier->verify($body, $expected));
    }

    public function testHmacSha256AcceptsSha256PrefixedSignature(): void
    {
        // GitHub-style prefix
        $secret = 'noon-test-shared-secret';
        $body = '{"orderId":"o-456"}';
        $expected = hash_hmac('sha256', $body, $secret);

        $verifier = new HmacSha256SignatureVerifier($secret);
        self::assertTrue($verifier->verify($body, 'sha256=' . $expected));
    }

    public function testHmacSha256RejectsTamperedBody(): void
    {
        $secret = 'noon-test-shared-secret';
        $original = '{"orderId":"o-456","status":"CAPTURED"}';
        $tampered = '{"orderId":"o-456","status":"FAILED"}';
        $signature = hash_hmac('sha256', $original, $secret);

        $verifier = new HmacSha256SignatureVerifier($secret);
        self::assertFalse($verifier->verify($tampered, $signature));
    }

    public function testHmacSha256RejectsWrongSecret(): void
    {
        $body = '{"orderId":"o-456"}';
        $signature = hash_hmac('sha256', $body, 'real-secret');

        $verifier = new HmacSha256SignatureVerifier('different-secret');
        self::assertFalse($verifier->verify($body, $signature));
    }

    public function testHmacSha256RejectsNullSignature(): void
    {
        $verifier = new HmacSha256SignatureVerifier('any-secret');
        self::assertFalse($verifier->verify('{"orderId":"o-456"}', null));
    }

    public function testHmacSha256RejectsEmptySignature(): void
    {
        $verifier = new HmacSha256SignatureVerifier('any-secret');
        self::assertFalse($verifier->verify('{"orderId":"o-456"}', ''));
    }

    public function testHmacSha256ConstructorRejectsEmptySecret(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new HmacSha256SignatureVerifier('');
    }

    public function testHmacSha256NormalisesSignatureCasing(): void
    {
        // PHP's hash_hmac returns lowercase hex; ensure uppercase
        // input is normalised before constant-time comparison.
        $secret = 'noon-test-shared-secret';
        $body = '{"orderId":"o-456"}';
        $expected = hash_hmac('sha256', $body, $secret);
        $uppercase = strtoupper($expected);

        $verifier = new HmacSha256SignatureVerifier($secret);
        self::assertTrue($verifier->verify($body, $uppercase));
    }

    public function testLoggingOnlyIsNotCryptographic(): void
    {
        // Passthrough verifier admits events without checking, so the
        // controller must record signature_verified=false for them.
        self::assertFalse((new LoggingOnlyVerifier())->isCryptographic());
    }

    public function testHmacSha256IsCryptographic(): void
    {
        // Real HMAC verifier — a passing verify() means the signature
        // checked out, so the controller records signature_verified=true.
        self::assertTrue((new HmacSha256SignatureVerifier('any-secret'))->isCryptographic());
    }
}
