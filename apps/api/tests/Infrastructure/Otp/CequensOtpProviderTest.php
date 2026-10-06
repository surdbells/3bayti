<?php

declare(strict_types=1);

namespace Bayti\Api\Tests\Infrastructure\Otp;

use Bayti\Api\Infrastructure\Otp\CequensOtpProvider;
use Bayti\Api\Infrastructure\Otp\OtpProviderException;
use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Cequens provider, driven entirely by a Guzzle MockHandler so no real network
 * is touched. Covers: auth → send → id extraction; verify success/failure by
 * status token; auth failure surfaced as a typed exception; defensive parsing
 * of the verification id. The recorded transactions let us assert the auth
 * token is forwarded as a Bearer header and the create/verify bodies are sent.
 */
#[CoversClass(CequensOtpProvider::class)]
final class CequensOtpProviderTest extends TestCase
{
    /** @var list<array<string, mixed>> */
    private array $transactions = [];

    /**
     * @param list<Response> $responses
     */
    private function provider(array $responses): CequensOtpProvider
    {
        $this->transactions = [];
        $mock = new MockHandler($responses);
        $stack = HandlerStack::create($mock);
        $stack->push(Middleware::history($this->transactions));
        $http = new Client(['handler' => $stack, 'base_uri' => 'https://apis.cequens.com']);

        return new CequensOtpProvider(
            http: $http,
            apiKey: 'test-key',
            userName: 'test-user',
        );
    }

    private function authOk(): Response
    {
        return new Response(200, [], (string) json_encode([
            'replyCode' => 0,
            'data' => ['access_token' => 'jwt-abc'],
        ]));
    }

    #[Test]
    public function sendAuthenticatesThenCreatesAVerificationAndReturnsItsId(): void
    {
        $provider = $this->provider([
            $this->authOk(),
            new Response(200, [], (string) json_encode(['data' => ['id' => 'ver-123', 'status' => 'pending']])),
        ]);

        $id = $provider->send('+971500000001');

        self::assertSame('ver-123', $id);
        self::assertCount(2, $this->transactions);

        // Auth first.
        /** @var Request $authReq */
        $authReq = $this->transactions[0]['request'];
        self::assertSame('POST', $authReq->getMethod());
        self::assertSame('/auth/v1/tokens/', $authReq->getUri()->getPath());

        // Then the create call carries the bearer token + the recipient.
        /** @var Request $sendReq */
        $sendReq = $this->transactions[1]['request'];
        self::assertSame('/mfa/v2/verifications', $sendReq->getUri()->getPath());
        self::assertSame('Bearer jwt-abc', $sendReq->getHeaderLine('Authorization'));
        self::assertStringContainsString('+971500000001', (string) $sendReq->getBody());
    }

    #[Test]
    public function sendThrowsUpstreamOn4xx(): void
    {
        $provider = $this->provider([
            $this->authOk(),
            new Response(400, [], (string) json_encode(['error' => 'bad recipient'])),
        ]);

        $this->expectException(OtpProviderException::class);
        $this->expectExceptionMessage('upstream');
        $provider->send('+971500000001');
    }

    #[Test]
    public function sendThrowsMissingIdWhenResponseHasNoVerificationId(): void
    {
        $provider = $this->provider([
            $this->authOk(),
            new Response(200, [], (string) json_encode(['data' => ['status' => 'pending']])),
        ]);

        $this->expectException(OtpProviderException::class);
        $this->expectExceptionMessage('missing_id');
        $provider->send('+971500000001');
    }

    #[Test]
    public function authFailureSurfacesAsUnauthorized(): void
    {
        $provider = $this->provider([
            new Response(401, [], (string) json_encode(['replyCode' => 1, 'message' => 'bad key'])),
        ]);

        $this->expectException(OtpProviderException::class);
        $this->expectExceptionMessage('unauthorized');
        $provider->send('+971500000001');
    }

    #[Test]
    public function verifyReturnsTrueOnAVerifiedStatus(): void
    {
        $provider = $this->provider([
            $this->authOk(),
            new Response(200, [], (string) json_encode(['data' => ['status' => 'verified']])),
        ]);

        self::assertTrue($provider->verify('ver-123', '123456'));

        /** @var Request $verifyReq */
        $verifyReq = $this->transactions[1]['request'];
        self::assertSame('POST', $verifyReq->getMethod());
        self::assertSame('/mfa/v2/verifications/ver-123/verify', $verifyReq->getUri()->getPath());
        self::assertStringContainsString('123456', (string) $verifyReq->getBody());
    }

    #[Test]
    public function verifyReturnsTrueOnAnExplicitBooleanFlag(): void
    {
        $provider = $this->provider([
            $this->authOk(),
            new Response(200, [], (string) json_encode(['data' => ['verified' => true]])),
        ]);

        self::assertTrue($provider->verify('ver-123', '123456'));
    }

    #[Test]
    public function verifyReturnsFalseOnAWrongCode(): void
    {
        $provider = $this->provider([
            $this->authOk(),
            new Response(400, [], (string) json_encode(['data' => ['status' => 'failed']])),
        ]);

        self::assertFalse($provider->verify('ver-123', '000000'));
    }

    #[Test]
    public function verifyThrowsOn5xx(): void
    {
        $provider = $this->provider([
            $this->authOk(),
            new Response(500, [], 'boom'),
        ]);

        $this->expectException(OtpProviderException::class);
        $this->expectExceptionMessage('upstream');
        $provider->verify('ver-123', '123456');
    }

    #[Test]
    public function authTokenIsCachedAcrossCalls(): void
    {
        // One auth response only; a second send must reuse the cached token
        // (if it re-authed, the MockHandler would run dry and throw).
        $provider = $this->provider([
            $this->authOk(),
            new Response(200, [], (string) json_encode(['data' => ['id' => 'ver-1']])),
            new Response(200, [], (string) json_encode(['data' => ['id' => 'ver-2']])),
        ]);

        self::assertSame('ver-1', $provider->send('+971500000001'));
        self::assertSame('ver-2', $provider->send('+971500000002'));
        // 1 auth + 2 sends = 3 transactions, NOT 4.
        self::assertCount(3, $this->transactions);
    }
}
