<?php

declare(strict_types=1);

namespace Bayti\Api\Tests\Http\Middleware;

use Bayti\Api\Http\Middleware\RequestIdContext;
use Bayti\Api\Http\Middleware\RequestIdMiddleware;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Slim\Psr7\Factory\ResponseFactory;
use Slim\Psr7\Factory\ServerRequestFactory;

#[CoversClass(RequestIdMiddleware::class)]
#[CoversClass(RequestIdContext::class)]
final class RequestIdMiddlewareTest extends TestCase
{
    private RequestIdMiddleware $middleware;
    private ResponseFactory $responseFactory;

    protected function setUp(): void
    {
        $this->middleware = new RequestIdMiddleware();
        $this->responseFactory = new ResponseFactory();
        // Defensive: ensure context is clean before each test.
        RequestIdContext::clear();
    }

    #[Test]
    public function generatesIdWhenHeaderAbsent(): void
    {
        $request = (new ServerRequestFactory())->createServerRequest('GET', '/');

        $capturedId = null;
        $handler = $this->makeHandler(function ($req) use (&$capturedId) {
            $capturedId = $req->getAttribute(RequestIdMiddleware::ATTR_REQUEST_ID);
            return $this->responseFactory->createResponse(200);
        });

        $response = $this->middleware->process($request, $handler);

        // Request attribute is a UUID
        self::assertNotNull($capturedId);
        self::assertMatchesRegularExpression(
            '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/',
            $capturedId,
        );

        // Response header echoes the same id
        self::assertSame($capturedId, $response->getHeaderLine('X-Request-Id'));
    }

    #[Test]
    public function honorsClientProvidedUuid(): void
    {
        $clientId = '550e8400-e29b-41d4-a716-446655440000';
        $request = (new ServerRequestFactory())->createServerRequest('GET', '/')
            ->withHeader('X-Request-Id', $clientId);

        $capturedId = null;
        $handler = $this->makeHandler(function ($req) use (&$capturedId) {
            $capturedId = $req->getAttribute(RequestIdMiddleware::ATTR_REQUEST_ID);
            return $this->responseFactory->createResponse(200);
        });

        $response = $this->middleware->process($request, $handler);

        self::assertSame($clientId, $capturedId);
        self::assertSame($clientId, $response->getHeaderLine('X-Request-Id'));
    }

    #[Test]
    public function lowercasesUppercaseClientId(): void
    {
        $clientId = '550E8400-E29B-41D4-A716-446655440000';
        $request = (new ServerRequestFactory())->createServerRequest('GET', '/')
            ->withHeader('X-Request-Id', $clientId);

        $capturedId = null;
        $handler = $this->makeHandler(function ($req) use (&$capturedId) {
            $capturedId = $req->getAttribute(RequestIdMiddleware::ATTR_REQUEST_ID);
            return $this->responseFactory->createResponse(200);
        });

        $this->middleware->process($request, $handler);

        // Lowercased — important for log grepping consistency.
        self::assertSame(strtolower($clientId), $capturedId);
    }

    #[Test]
    public function rejectsNonUuidClientId(): void
    {
        // Things that should NOT be honored: too short, wrong chars,
        // injection attempts, etc.
        $badIds = [
            'hello',
            '12345',
            '../../../etc/passwd',
            "newline\ninjection",
            'ZZZZZZZZ-ZZZZ-ZZZZ-ZZZZ-ZZZZZZZZZZZZ',  // not hex
        ];

        foreach ($badIds as $bad) {
            RequestIdContext::clear();
            $request = (new ServerRequestFactory())->createServerRequest('GET', '/')
                ->withHeader('X-Request-Id', $bad);

            $capturedId = null;
            $handler = $this->makeHandler(function ($req) use (&$capturedId) {
                $capturedId = $req->getAttribute(RequestIdMiddleware::ATTR_REQUEST_ID);
                return $this->responseFactory->createResponse(200);
            });

            $this->middleware->process($request, $handler);

            self::assertNotSame($bad, $capturedId, "Should reject: $bad");
            self::assertMatchesRegularExpression(
                '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/',
                $capturedId,
            );
        }
    }

    #[Test]
    public function setsAndClearsContext(): void
    {
        $request = (new ServerRequestFactory())->createServerRequest('GET', '/');

        // During request handling, context should be set
        $contextDuringRequest = null;
        $handler = $this->makeHandler(function () use (&$contextDuringRequest) {
            $contextDuringRequest = RequestIdContext::get();
            return $this->responseFactory->createResponse(200);
        });

        $this->middleware->process($request, $handler);

        // Context was set during the request
        self::assertNotNull($contextDuringRequest);

        // And cleared afterwards
        self::assertNull(RequestIdContext::get());
    }

    #[Test]
    public function clearsContextEvenOnException(): void
    {
        $request = (new ServerRequestFactory())->createServerRequest('GET', '/');

        $handler = $this->makeHandler(function () {
            throw new \RuntimeException('handler exploded');
        });

        try {
            $this->middleware->process($request, $handler);
        } catch (\RuntimeException) {
            // expected
        }

        // Even on exception, the finally clause cleared the context.
        self::assertNull(RequestIdContext::get());
    }

    /**
     * Helper to build a RequestHandlerInterface from a closure.
     */
    private function makeHandler(callable $fn): RequestHandlerInterface
    {
        return new class ($fn) implements RequestHandlerInterface {
            public function __construct(private $fn)
            {
            }

            public function handle(ServerRequestInterface $request): ResponseInterface
            {
                return ($this->fn)($request);
            }
        };
    }
}
