<?php

declare(strict_types=1);

namespace Bayti\Api\Tests\Ai\TryOn;

use Bayti\Api\Ai\OpenAi\OpenAiClient;
use Bayti\Api\Ai\TryOn\NullVirtualTryOnProvider;
use Bayti\Api\Ai\TryOn\OpenAiVirtualTryOnProvider;
use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(OpenAiVirtualTryOnProvider::class)]
#[CoversClass(NullVirtualTryOnProvider::class)]
#[CoversClass(OpenAiClient::class)]
final class OpenAiVirtualTryOnProviderTest extends TestCase
{
    /** @var list<array<string, mixed>> */
    private array $history = [];

    /** @param list<Response> $responses */
    private function provider(array $responses): OpenAiVirtualTryOnProvider
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
        return new OpenAiVirtualTryOnProvider($client, 'gpt-image-1', '1024x1024');
    }

    // ===== NullVirtualTryOnProvider (degrade path) =====

    #[Test]
    public function nullProviderIsDisabledAndReturnsEmpty(): void
    {
        $null = new NullVirtualTryOnProvider();

        self::assertFalse($null->isEnabled());
        $result = $null->generate('person', 'image/jpeg', 'garment', 'image/jpeg', 'abaya');
        self::assertTrue($result->isEmpty());
        self::assertSame('', $result->imageBytes);
    }

    // ===== generate =====

    #[Test]
    public function generateReturnsTheDecodedImageBytes(): void
    {
        $png = "\x89PNG\r\n\x1a\nGENERATED";
        $provider = $this->provider([
            new Response(200, [], (string) json_encode([
                'data' => [['b64_json' => base64_encode($png)]],
            ])),
        ]);

        $result = $provider->generate('PERSONJPEG', 'image/jpeg', 'GARMENTPNG', 'image/png', 'black abaya');

        self::assertFalse($result->isEmpty());
        self::assertSame($png, $result->imageBytes);
        self::assertSame('image/png', $result->mimeType);

        // Multipart image-edit request with the person + garment + prompt.
        $req = $this->history[0]['request'];
        self::assertSame('/v1/images/edits', $req->getUri()->getPath());
        self::assertSame('Bearer sk-test', $req->getHeaderLine('Authorization'));
        self::assertStringContainsString('multipart/form-data', $req->getHeaderLine('Content-Type'));
        $body = (string) $req->getBody();
        self::assertStringContainsString('name="model"', $body);
        self::assertStringContainsString('gpt-image-1', $body);
        self::assertStringContainsString('name="image[]"', $body);
        self::assertStringContainsString('name="prompt"', $body);
        self::assertStringContainsString('black abaya', $body);
    }

    #[Test]
    public function generateShortCircuitsWhenAnInputIsEmpty(): void
    {
        $provider = $this->provider([]); // no HTTP call should be made

        self::assertTrue($provider->generate('', 'image/jpeg', 'garment', 'image/png', 'x')->isEmpty());
        self::assertTrue($provider->generate('person', 'image/jpeg', '', 'image/png', 'x')->isEmpty());
        self::assertCount(0, $this->history);
    }

    #[Test]
    public function generateDegradesToEmptyOnMalformedResponse(): void
    {
        $provider = $this->provider([
            new Response(200, [], (string) json_encode(['data' => [['no_image' => true]]])),
        ]);

        self::assertTrue($provider->generate('p', 'image/jpeg', 'g', 'image/png', 'x')->isEmpty());
    }

    #[Test]
    public function generateDegradesToEmptyOnApiError(): void
    {
        // 401 auth, 429 rate-limit, 500 transport all degrade to empty (never throw).
        foreach ([401, 429, 500] as $status) {
            $provider = $this->provider([new Response($status, [], 'error')]);
            $result = $provider->generate('p', 'image/jpeg', 'g', 'image/png', 'x');
            self::assertTrue($result->isEmpty(), "HTTP {$status} should degrade to empty");
        }
    }
}
