<?php

declare(strict_types=1);

namespace Bayti\Api\Tests\Ai\Vision;

use Bayti\Api\Ai\OpenAi\OpenAiClient;
use Bayti\Api\Ai\Vision\OpenAiVisionEmbedder;
use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(OpenAiVisionEmbedder::class)]
final class OpenAiVisionEmbedderTest extends TestCase
{
    /** @param list<Response> $responses */
    private function embedder(array $responses): OpenAiVisionEmbedder
    {
        $stack = HandlerStack::create(new MockHandler($responses));
        $client = new OpenAiClient(
            new Client(['handler' => $stack]),
            'https://api.openai.test',
            'sk-test',
            'gpt-4o-mini',
            'text-embedding-3-small',
        );
        return new OpenAiVisionEmbedder($client, 'gpt-4o-mini');
    }

    /** @param array<string, mixed> $payload */
    private function json(array $payload): Response
    {
        return new Response(200, [], (string) json_encode($payload));
    }

    #[Test]
    public function describesThenEmbedsTheImage(): void
    {
        $embedder = $this->embedder([
            // 1) vision chat → description
            $this->json(['choices' => [['message' => ['content' => 'black embellished abaya, elegant, eid']]]]),
            // 2) embeddings → vector
            $this->json(['data' => [['index' => 0, 'embedding' => [0.1, 0.2, 0.3]]]]),
        ]);

        $result = $embedder->embedImage('rawbytes', 'image/jpeg');

        self::assertTrue($embedder->isEnabled());
        self::assertSame('black embellished abaya, elegant, eid', $result->description);
        self::assertSame([0.1, 0.2, 0.3], $result->vector);
        self::assertFalse($result->isEmpty());
    }

    #[Test]
    public function returnsEmptyWhenTheDescriptionIsBlank(): void
    {
        $embedder = $this->embedder([
            $this->json(['choices' => [['message' => ['content' => '   ']]]]),
        ]);

        $result = $embedder->embedImage('rawbytes', 'image/png');
        self::assertTrue($result->isEmpty());
        self::assertNull($result->description);
    }

    #[Test]
    public function degradesToEmptyOnAnApiError(): void
    {
        $embedder = $this->embedder([
            new Response(500, [], 'upstream boom'),
        ]);

        $result = $embedder->embedImage('rawbytes', 'image/jpeg');
        self::assertTrue($result->isEmpty());
    }

    #[Test]
    public function returnsEmptyForEmptyBytesWithoutCallingTheApi(): void
    {
        // No queued responses: if the embedder called the API this would error.
        $embedder = $this->embedder([]);
        self::assertTrue($embedder->embedImage('', 'image/jpeg')->isEmpty());
    }
}
