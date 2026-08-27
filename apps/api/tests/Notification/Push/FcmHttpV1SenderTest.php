<?php

declare(strict_types=1);

namespace Bayti\Api\Tests\Notification\Push;

use Bayti\Api\Notification\Push\FcmHttpV1Sender;
use Bayti\Api\Notification\Push\PushException;
use Bayti\Api\Notification\Push\PushMessage;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(FcmHttpV1Sender::class)]
final class FcmHttpV1SenderTest extends TestCase
{
    /** @var array<int, array{method:string, uri:string, headers:array<string,array<int,string>>, body:string}> */
    private array $captured = [];

    private string $privateKey = '';

    protected function setUp(): void
    {
        // Generate an ephemeral RSA keypair so JWT::encode(RS256) works
        // for real (no fixture key checked into the repo).
        $res = openssl_pkey_new([
            'private_key_bits' => 2048,
            'private_key_type' => OPENSSL_KEYTYPE_RSA,
        ]);
        self::assertNotFalse($res, 'openssl keypair generation failed');
        openssl_pkey_export($res, $pem);
        $this->privateKey = $pem;
    }

    private function sender(array $responsesOrExceptions): FcmHttpV1Sender
    {
        $mock = new MockHandler($responsesOrExceptions);
        $stack = HandlerStack::create($mock);
        $stack->push(Middleware::tap(function ($req): void {
            $this->captured[] = [
                'method' => $req->getMethod(),
                'uri' => (string) $req->getUri(),
                'headers' => $req->getHeaders(),
                'body' => (string) $req->getBody(),
            ];
            $req->getBody()->rewind();
        }));
        return new FcmHttpV1Sender(
            projectId: 'demo-project',
            clientEmail: 'svc@demo-project.iam.gserviceaccount.com',
            privateKey: $this->privateKey,
            httpClient: new Client(['handler' => $stack]),
            clock: fn (): int => 1_000_000,
        );
    }

    private function tokenResponse(int $expiresIn = 3600): Response
    {
        return new Response(200, [], json_encode([
            'access_token' => 'ya29.test-access-token',
            'expires_in' => $expiresIn,
            'token_type' => 'Bearer',
        ]));
    }

    #[Test]
    public function constructorRejectsEmptyCredentials(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new FcmHttpV1Sender(projectId: '', clientEmail: 'x', privateKey: 'y');
    }

    #[Test]
    public function sendMintsTokenThenPostsToFcm(): void
    {
        $sender = $this->sender([
            $this->tokenResponse(),
            new Response(200, [], json_encode(['name' => 'projects/demo-project/messages/123'])),
        ]);

        $sender->sendToToken('device-token-xyz', new PushMessage(
            title: 'Order paid',
            body: 'Your order #42 is confirmed',
            data: ['type' => 'order.paid', 'order_id' => '42'],
        ), ['order_id' => 42]);

        self::assertCount(2, $this->captured);

        // 1st request: OAuth2 token exchange.
        $oauth = $this->captured[0];
        self::assertSame('POST', $oauth['method']);
        self::assertSame('https://oauth2.googleapis.com/token', $oauth['uri']);
        self::assertStringContainsString('grant_type=', $oauth['body']);
        self::assertStringContainsString('assertion=', $oauth['body']);

        // 2nd request: FCM send with the minted bearer token.
        $send = $this->captured[1];
        self::assertSame('POST', $send['method']);
        self::assertSame(
            'https://fcm.googleapis.com/v1/projects/demo-project/messages:send',
            $send['uri'],
        );
        self::assertSame('Bearer ya29.test-access-token', $send['headers']['Authorization'][0]);

        $payload = json_decode($send['body'], true);
        self::assertSame('device-token-xyz', $payload['message']['token']);
        self::assertSame('Order paid', $payload['message']['notification']['title']);
        self::assertSame('order.paid', $payload['message']['data']['type']);
        self::assertSame('42', $payload['message']['data']['order_id']);
        // Non-empty data must serialize as a JSON object, never an array.
        self::assertStringContainsString('"data":{', $send['body']);
    }

    #[Test]
    public function sendOmitsEmptyDataSoFcmDoesNotReject(): void
    {
        // Regression: an empty data map serialized as "data":[] (a JSON array),
        // which FCM v1 rejects with INVALID_ARGUMENT, so every admin broadcast
        // (which carries no data) failed on every token, while order pushes
        // (always non-empty data) succeeded.
        $sender = $this->sender([
            $this->tokenResponse(),
            new Response(200, [], json_encode(['name' => 'projects/demo-project/messages/456'])),
        ]);

        $sender->sendToToken('device-token-xyz', new PushMessage(
            title: 'Don\'t forget the outfit',
            body: 'Your next favourite look is waiting.',
            data: [],
        ));

        $send = $this->captured[1];
        // The data key is dropped entirely when empty, never sent as `[]`.
        self::assertStringNotContainsString('"data":[]', $send['body']);
        $payload = json_decode($send['body'], true);
        self::assertArrayNotHasKey('data', $payload['message']);
    }

    #[Test]
    public function cachesAccessTokenAcrossSends(): void
    {
        // Only ONE token response queued; two sends. If the token were
        // re-minted on the 2nd send, the MockHandler would run dry.
        $sender = $this->sender([
            $this->tokenResponse(),
            new Response(200, [], json_encode(['name' => 'm/1'])),
            new Response(200, [], json_encode(['name' => 'm/2'])),
        ]);

        $msg = new PushMessage('t', 'b');
        $sender->sendToToken('tok-1', $msg);
        $sender->sendToToken('tok-2', $msg);

        // 3 requests total: 1 token + 2 sends (NOT 2 token mints).
        self::assertCount(3, $this->captured);
        self::assertSame('https://oauth2.googleapis.com/token', $this->captured[0]['uri']);
        self::assertStringContainsString('messages:send', $this->captured[1]['uri']);
        self::assertStringContainsString('messages:send', $this->captured[2]['uri']);
    }

    #[Test]
    public function rejectsEmptyToken(): void
    {
        $sender = $this->sender([$this->tokenResponse()]);
        try {
            $sender->sendToToken('', new PushMessage('t', 'b'));
            $this->fail('Expected PushException');
        } catch (PushException $e) {
            self::assertSame(PushException::KIND_TRANSPORT, $e->kind);
        }
    }

    #[Test]
    public function maps404ToUnregisteredAndFlagsTokenDead(): void
    {
        $sender = $this->sender([
            $this->tokenResponse(),
            new Response(404, [], json_encode([
                'error' => ['status' => 'NOT_FOUND', 'message' => 'Requested entity was not found.'],
            ])),
        ]);

        try {
            $sender->sendToToken('dead-token', new PushMessage('t', 'b'));
            $this->fail('Expected PushException');
        } catch (PushException $e) {
            self::assertSame(PushException::KIND_UNREGISTERED, $e->kind);
            self::assertTrue($e->isTokenDead());
        }
    }

    #[Test]
    public function mapsUnregisteredStatusBodyToUnregistered(): void
    {
        $sender = $this->sender([
            $this->tokenResponse(),
            new Response(400, [], json_encode([
                'error' => ['status' => 'UNREGISTERED', 'message' => 'token no longer valid'],
            ])),
        ]);

        try {
            $sender->sendToToken('dead-token', new PushMessage('t', 'b'));
            $this->fail('Expected PushException');
        } catch (PushException $e) {
            self::assertSame(PushException::KIND_UNREGISTERED, $e->kind);
        }
    }

    #[Test]
    public function maps401ToAuth(): void
    {
        $sender = $this->sender([
            $this->tokenResponse(),
            new Response(401, [], json_encode(['error' => ['status' => 'UNAUTHENTICATED']])),
        ]);

        try {
            $sender->sendToToken('tok', new PushMessage('t', 'b'));
            $this->fail('Expected PushException');
        } catch (PushException $e) {
            self::assertSame(PushException::KIND_AUTH, $e->kind);
            self::assertFalse($e->isTokenDead());
        }
    }

    #[Test]
    public function maps429ToRateLimit(): void
    {
        $sender = $this->sender([
            $this->tokenResponse(),
            new Response(429, [], json_encode(['error' => ['status' => 'RESOURCE_EXHAUSTED']])),
        ]);

        try {
            $sender->sendToToken('tok', new PushMessage('t', 'b'));
            $this->fail('Expected PushException');
        } catch (PushException $e) {
            self::assertSame(PushException::KIND_RATE_LIMIT, $e->kind);
        }
    }

    #[Test]
    public function mapsNetworkErrorToNetworkKind(): void
    {
        $sender = $this->sender([
            $this->tokenResponse(),
            new ConnectException('Connection timed out', new Request('POST', 'https://fcm.googleapis.com')),
        ]);

        try {
            $sender->sendToToken('tok', new PushMessage('t', 'b'));
            $this->fail('Expected PushException');
        } catch (PushException $e) {
            self::assertSame(PushException::KIND_NETWORK, $e->kind);
        }
    }

    #[Test]
    public function oauthFailureSurfacesAsAuthException(): void
    {
        // Token endpoint returns a body with no access_token.
        $sender = $this->sender([
            new Response(200, [], json_encode(['error' => 'invalid_grant'])),
        ]);

        try {
            $sender->sendToToken('tok', new PushMessage('t', 'b'));
            $this->fail('Expected PushException');
        } catch (PushException $e) {
            self::assertSame(PushException::KIND_AUTH, $e->kind);
        }
    }
}
