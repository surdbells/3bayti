<?php

declare(strict_types=1);

namespace Bayti\Api\Tests\Ai;

use Bayti\Api\Ai\AiException;
use Bayti\Api\Ai\NullAiProvider;
use Bayti\Api\Ai\OpenAi\OpenAiClient;
use Bayti\Api\Ai\OpenAi\OpenAiProvider;
use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(OpenAiProvider::class)]
#[CoversClass(OpenAiClient::class)]
#[CoversClass(NullAiProvider::class)]
#[CoversClass(AiException::class)]
final class OpenAiProviderTest extends TestCase
{
    /** @var list<array<string, mixed>> */
    private array $history = [];

    /** @param list<Response> $responses */
    private function provider(array $responses): OpenAiProvider
    {
        $this->history = [];
        $stack = HandlerStack::create(new MockHandler($responses));
        $stack->push(Middleware::history($this->history));
        $client = new OpenAiClient(
            new Client(['handler' => $stack]),
            'https://api.openai.test',
            'sk-test',
            'gpt-4o-mini',
            'text-embedding-3-small',
        );
        return new OpenAiProvider($client);
    }

    /** @param array<string, mixed> $payload */
    private function jsonResponse(array $payload): Response
    {
        return new Response(200, [], (string) json_encode($payload));
    }

    // ===== NullAiProvider (degrade path) =====

    #[Test]
    public function nullProviderIsDisabledAndDegradesSafely(): void
    {
        $null = new NullAiProvider();

        self::assertFalse($null->isEnabled());
        self::assertSame([], $null->completeJson('sys', 'user', ['type' => 'object']));
        // One empty vector per input, order preserved, never an exception.
        self::assertSame([[], []], $null->embed(['a', 'b']));
        self::assertSame([], $null->embed([]));
    }

    // ===== completeJson =====

    #[Test]
    public function completeJsonReturnsTheParsedStructuredObject(): void
    {
        $provider = $this->provider([
            $this->jsonResponse([
                'choices' => [[
                    'message' => ['content' => '{"product_type":"abaya","colours":["black"],"budget_max":800}'],
                ]],
            ]),
        ]);

        $out = $provider->completeJson(
            'You extract shopping intent.',
            'elegant black abaya for a wedding under AED 800',
            ['type' => 'object', 'properties' => ['product_type' => ['type' => 'string']]],
            'concierge_intent',
        );

        self::assertSame('abaya', $out['product_type']);
        self::assertSame(['black'], $out['colours']);
        self::assertSame(800, $out['budget_max']);

        // The request is a json_schema-constrained chat completion.
        $req = $this->history[0]['request'];
        self::assertSame('/v1/chat/completions', $req->getUri()->getPath());
        self::assertSame('Bearer sk-test', $req->getHeaderLine('Authorization'));
        $sent = json_decode((string) $req->getBody(), true);
        self::assertSame('gpt-4o-mini', $sent['model']);
        self::assertSame('json_schema', $sent['response_format']['type']);
        self::assertSame('concierge_intent', $sent['response_format']['json_schema']['name']);
        self::assertSame('system', $sent['messages'][0]['role']);
    }

    #[Test]
    public function completeJsonThrowsMalformedOnNonJsonContent(): void
    {
        $provider = $this->provider([
            $this->jsonResponse(['choices' => [['message' => ['content' => 'not json']]]]),
        ]);

        $this->expectException(AiException::class);
        $this->expectExceptionMessageMatches('/JSON object/');
        $provider->completeJson('sys', 'user', ['type' => 'object']);
    }

    // ===== embed =====

    #[Test]
    public function embedReturnsVectorsInInputOrder(): void
    {
        // API returns rows out of order; provider must re-order by `index`.
        $provider = $this->provider([
            $this->jsonResponse([
                'data' => [
                    ['index' => 1, 'embedding' => [0.1, 0.2]],
                    ['index' => 0, 'embedding' => [0.3, 0.4]],
                ],
            ]),
        ]);

        $vectors = $provider->embed(['first', 'second']);

        self::assertSame([[0.3, 0.4], [0.1, 0.2]], $vectors);

        $sent = json_decode((string) $this->history[0]['request']->getBody(), true);
        self::assertSame('/v1/embeddings', $this->history[0]['request']->getUri()->getPath());
        self::assertSame('text-embedding-3-small', $sent['model']);
        self::assertSame(['first', 'second'], $sent['input']);
    }

    #[Test]
    public function embedShortCircuitsOnEmptyInput(): void
    {
        $provider = $this->provider([]); // no HTTP call should be made
        self::assertSame([], $provider->embed([]));
        self::assertCount(0, $this->history);
    }

    // ===== error mapping =====

    #[Test]
    public function mapsAuthAndRateLimitAndTransportErrors(): void
    {
        foreach ([[401, AiException::KIND_AUTH], [429, AiException::KIND_RATE_LIMITED], [500, AiException::KIND_TRANSPORT]] as [$status, $kind]) {
            $provider = $this->provider([new Response($status, [], 'error')]);
            try {
                $provider->completeJson('sys', 'user', ['type' => 'object']);
                self::fail("Expected AiException for HTTP {$status}");
            } catch (AiException $e) {
                self::assertSame($kind, $e->kind, "HTTP {$status} should map to {$kind}");
            }
        }
    }
}
