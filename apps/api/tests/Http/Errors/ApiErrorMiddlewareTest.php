<?php

declare(strict_types=1);

namespace Bayti\Api\Tests\Http\Errors;

use Bayti\Api\Http\Errors\ApiErrorMiddleware;
use Bayti\Api\Http\Errors\ErrorCodes;
use Bayti\Api\Http\Errors\HttpException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Slim\Psr7\Factory\ResponseFactory;
use Slim\Psr7\Factory\ServerRequestFactory;

#[CoversClass(ApiErrorMiddleware::class)]
#[CoversClass(HttpException::class)]
#[CoversClass(ErrorCodes::class)]
final class ApiErrorMiddlewareTest extends TestCase
{
    private ApiErrorMiddleware $middleware;

    protected function setUp(): void
    {
        $this->middleware = new ApiErrorMiddleware(
            responseFactory: new ResponseFactory(),
            debugMode: false,
        );
    }

    // -------------------------------------------------------------------
    // Pass-through (no exception)
    // -------------------------------------------------------------------

    #[Test]
    public function passesThroughWhenHandlerSucceeds(): void
    {
        $handler = $this->successHandler();
        $response = $this->middleware->process($this->makeRequest(), $handler);
        self::assertSame(200, $response->getStatusCode());
    }

    // -------------------------------------------------------------------
    // HttpException → typed envelope
    // -------------------------------------------------------------------

    #[Test]
    public function rendersUnauthorizedExceptionWithEnvelope(): void
    {
        $handler = $this->throwingHandler(HttpException::unauthorized(
            ErrorCodes::AUTH_INVALID_CREDENTIALS,
            'Email or password incorrect.',
        ));

        $response = $this->middleware->process($this->makeRequest(), $handler);

        self::assertSame(401, $response->getStatusCode());
        self::assertSame('application/json', $response->getHeaderLine('Content-Type'));

        $body = json_decode((string) $response->getBody(), true);
        self::assertIsArray($body);
        self::assertSame(ErrorCodes::AUTH_INVALID_CREDENTIALS, $body['error']['code']);
        self::assertSame('Email or password incorrect.', $body['error']['message']);
    }

    #[Test]
    public function rendersValidationExceptionWithFieldDetails(): void
    {
        $handler = $this->throwingHandler(HttpException::validation([
            'email' => ['Not a valid email.'],
            'password' => ['Too short.'],
        ]));

        $response = $this->middleware->process($this->makeRequest(), $handler);

        self::assertSame(422, $response->getStatusCode());
        $body = json_decode((string) $response->getBody(), true);

        self::assertSame(ErrorCodes::VALIDATION_FAILED, $body['error']['code']);
        self::assertArrayHasKey('details', $body['error']);
        self::assertSame(
            ['email' => ['Not a valid email.'], 'password' => ['Too short.']],
            $body['error']['details']['fields'],
        );
    }

    #[Test]
    public function rendersConflictException(): void
    {
        $handler = $this->throwingHandler(HttpException::conflict(
            ErrorCodes::CONFLICT_EMAIL_TAKEN,
            'That email is already registered.',
        ));

        $response = $this->middleware->process($this->makeRequest(), $handler);

        self::assertSame(409, $response->getStatusCode());
        $body = json_decode((string) $response->getBody(), true);
        self::assertSame(ErrorCodes::CONFLICT_EMAIL_TAKEN, $body['error']['code']);
    }

    #[Test]
    public function rendersRateLimitException(): void
    {
        $handler = $this->throwingHandler(HttpException::rateLimited());

        $response = $this->middleware->process($this->makeRequest(), $handler);
        self::assertSame(429, $response->getStatusCode());
    }

    // -------------------------------------------------------------------
    // Uncaught exceptions → 500 with generic envelope
    // -------------------------------------------------------------------

    #[Test]
    public function rendersGeneric500ForUncaughtException(): void
    {
        $handler = $this->throwingHandler(new \RuntimeException('database down'));

        $response = $this->middleware->process($this->makeRequest(), $handler);

        self::assertSame(500, $response->getStatusCode());
        $body = json_decode((string) $response->getBody(), true);

        self::assertSame(ErrorCodes::INTERNAL_ERROR, $body['error']['code']);
        // Crucially: the original exception message MUST NOT leak.
        self::assertStringNotContainsString('database down', (string) $response->getBody());
        self::assertSame('An unexpected error occurred.', $body['error']['message']);
    }

    #[Test]
    public function debugModeIncludesStackInfoFor500(): void
    {
        $debugMiddleware = new ApiErrorMiddleware(
            responseFactory: new ResponseFactory(),
            debugMode: true,
        );

        $handler = $this->throwingHandler(new \RuntimeException('database down'));
        $response = $debugMiddleware->process($this->makeRequest(), $handler);

        $body = json_decode((string) $response->getBody(), true);
        self::assertSame(500, $response->getStatusCode());
        self::assertArrayHasKey('debug', $body['error']);
        self::assertSame('database down', $body['error']['debug']['message']);
        self::assertArrayHasKey('trace', $body['error']['debug']);
    }

    // -------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------

    private function makeRequest(): ServerRequestInterface
    {
        return (new ServerRequestFactory())->createServerRequest('GET', '/v3/whatever');
    }

    private function throwingHandler(\Throwable $toThrow): RequestHandlerInterface
    {
        return new class ($toThrow) implements RequestHandlerInterface {
            public function __construct(private readonly \Throwable $t) {}
            public function handle(ServerRequestInterface $request): ResponseInterface
            {
                throw $this->t;
            }
        };
    }

    private function successHandler(): RequestHandlerInterface
    {
        return new class implements RequestHandlerInterface {
            public function handle(ServerRequestInterface $request): ResponseInterface
            {
                return (new ResponseFactory())->createResponse(200);
            }
        };
    }
}
