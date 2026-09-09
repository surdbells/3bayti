<?php

declare(strict_types=1);

namespace Bayti\Api\Tests\Shipping;

use Bayti\Api\Shipping\Oto\OtoWebhookVerifier;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Slim\Psr7\Factory\ServerRequestFactory;

#[CoversClass(OtoWebhookVerifier::class)]
final class OtoWebhookVerifierTest extends TestCase
{
    /** @param array<string,string> $headers */
    private function request(array $headers = []): \Psr\Http\Message\ServerRequestInterface
    {
        $request = (new ServerRequestFactory())->createServerRequest('POST', '/v3/shipping/webhook/oto');
        foreach ($headers as $name => $value) {
            $request = $request->withHeader($name, $value);
        }
        return $request;
    }

    #[Test]
    public function acceptsWhenNothingIsConfigured(): void
    {
        $verifier = new OtoWebhookVerifier(secret: null, authKey: null);
        self::assertFalse($verifier->isConfigured());
        self::assertTrue($verifier->verify($this->request(), ['orderId' => 'x', 'status' => 'Delivered']));
    }

    #[Test]
    public function acceptsAMatchingAuthorizationKey(): void
    {
        $verifier = new OtoWebhookVerifier(secret: null, authKey: 'super-secret-key');
        self::assertTrue($verifier->verify($this->request(['Authorization' => 'super-secret-key']), []));
        // …or in the body.
        self::assertTrue($verifier->verify($this->request(), ['authorizationKey' => 'super-secret-key']));
    }

    #[Test]
    public function rejectsAWrongOrMissingAuthorizationKey(): void
    {
        $verifier = new OtoWebhookVerifier(secret: null, authKey: 'super-secret-key');
        self::assertFalse($verifier->verify($this->request(['Authorization' => 'nope']), []));
        self::assertFalse($verifier->verify($this->request(), []));
    }

    #[Test]
    public function acceptsAValidHmacSignatureOverOrderIdStatusTimestamp(): void
    {
        $secret = 'hmac-secret';
        $body = ['orderId' => '3B-9-V7', 'status' => 'Delivered', 'timestamp' => '1788500000'];
        $expected = base64_encode(hash_hmac('sha256', '3B-9-V7:Delivered:1788500000', $secret, true));

        $verifier = new OtoWebhookVerifier(secret: $secret, authKey: null);
        self::assertTrue($verifier->verify($this->request(['X-OTO-Signature' => $expected]), $body));
        // …or a `signature` body field.
        self::assertTrue($verifier->verify($this->request(), $body + ['signature' => $expected]));
    }

    #[Test]
    public function rejectsAForgedSignature(): void
    {
        $verifier = new OtoWebhookVerifier(secret: 'hmac-secret', authKey: null);
        $body = ['orderId' => '3B-9-V7', 'status' => 'Delivered', 'timestamp' => '1788500000'];
        self::assertFalse($verifier->verify($this->request(['X-OTO-Signature' => 'forged']), $body));
    }
}
