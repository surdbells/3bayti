<?php

declare(strict_types=1);

namespace Bayti\Api\Tests\Messaging\WhatsApp;

use Bayti\Api\Messaging\MessagingException;
use Bayti\Api\Messaging\NullMessagingChannel;
use Bayti\Api\Messaging\WhatsApp\WhatsAppCloudClient;
use Bayti\Api\Messaging\WhatsApp\WhatsAppMessagingChannel;
use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(WhatsAppMessagingChannel::class)]
#[CoversClass(WhatsAppCloudClient::class)]
#[CoversClass(NullMessagingChannel::class)]
final class WhatsAppMessagingChannelTest extends TestCase
{
    /** @var list<array<string, mixed>> */
    private array $history = [];

    /** @param list<Response> $responses */
    private function channel(array $responses): WhatsAppMessagingChannel
    {
        $this->history = [];
        $stack = HandlerStack::create(new MockHandler($responses));
        $stack->push(Middleware::history($this->history));
        $client = new WhatsAppCloudClient(
            new Client(['handler' => $stack]),
            'https://graph.test/v21.0',
            '123PHONE',
            'TOKEN',
        );
        return new WhatsAppMessagingChannel($client);
    }

    #[Test]
    public function nullChannelIsDisabledAndNoOps(): void
    {
        $null = new NullMessagingChannel();

        self::assertFalse($null->isEnabled());
        $result = $null->sendText('+971501234567', 'hello');
        self::assertFalse($result->sent);
        self::assertNull($result->messageId);
    }

    #[Test]
    public function sendsTextAndReturnsTheMessageId(): void
    {
        $channel = $this->channel([
            new Response(200, [], (string) json_encode(['messages' => [['id' => 'wamid.ABC']]])),
        ]);

        $result = $channel->sendText('+971501234567', 'Here are a few picks');

        self::assertTrue($result->sent);
        self::assertSame('wamid.ABC', $result->messageId);

        $req = $this->history[0]['request'];
        self::assertSame('/v21.0/123PHONE/messages', $req->getUri()->getPath());
        self::assertSame('Bearer TOKEN', $req->getHeaderLine('Authorization'));
        $sent = json_decode((string) $req->getBody(), true);
        self::assertSame('whatsapp', $sent['messaging_product']);
        self::assertSame('971501234567', $sent['to']); // leading '+' stripped
        self::assertSame('text', $sent['type']);
        self::assertSame('Here are a few picks', $sent['text']['body']);
    }

    #[Test]
    public function mapsAuthRateLimitAndTransportErrors(): void
    {
        foreach (
            [
                [401, MessagingException::KIND_AUTH],
                [429, MessagingException::KIND_RATE_LIMITED],
                [500, MessagingException::KIND_TRANSPORT],
            ] as [$status, $kind]
        ) {
            $channel = $this->channel([new Response($status, [], 'error')]);
            try {
                $channel->sendText('+971501234567', 'x');
                self::fail("Expected MessagingException for HTTP {$status}");
            } catch (MessagingException $e) {
                self::assertSame($kind, $e->kind, "HTTP {$status} should map to {$kind}");
            }
        }
    }
}
