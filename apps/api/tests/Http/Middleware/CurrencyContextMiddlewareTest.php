<?php

declare(strict_types=1);

namespace Bayti\Api\Tests\Http\Middleware;

use Bayti\Api\Domain\Currency\Currency;
use Bayti\Api\Http\Middleware\CurrencyContextMiddleware;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Slim\Psr7\Factory\ResponseFactory;
use Slim\Psr7\Factory\ServerRequestFactory;

/**
 * Unit tests for CurrencyContextMiddleware (M3.2.X.15-D).
 *
 * Verify the request attribute is set correctly for each input
 * shape — known currencies, case insensitive, fallback to AED
 * on unknown / missing / non-string.
 *
 * Strategy: build a real ServerRequest via Slim's factory; pass
 * through the middleware via a capturing handler; assert the
 * attribute on the request that reaches downstream.
 */
#[CoversClass(CurrencyContextMiddleware::class)]
final class CurrencyContextMiddlewareTest extends TestCase
{
    private CurrencyContextMiddleware $middleware;
    private CapturingHandler $handler;

    protected function setUp(): void
    {
        $this->middleware = new CurrencyContextMiddleware();
        $this->handler = new CapturingHandler();
    }

    #[Test]
    public function knownCurrencyAttachedToRequest(): void
    {
        $request = $this->makeRequest('/v3/products?currency=USD');

        $this->middleware->process($request, $this->handler);

        $captured = $this->handler->lastRequest;
        self::assertNotNull($captured);
        $currency = $captured->getAttribute(CurrencyContextMiddleware::ATTR_DISPLAY_CURRENCY);
        self::assertInstanceOf(Currency::class, $currency);
        self::assertSame(Currency::USD, $currency);
    }

    #[Test]
    public function caseInsensitiveCurrencyParsing(): void
    {
        $request = $this->makeRequest('/v3/products?currency=eur');

        $this->middleware->process($request, $this->handler);

        $currency = $this->handler->lastRequest?->getAttribute(
            CurrencyContextMiddleware::ATTR_DISPLAY_CURRENCY,
        );
        self::assertSame(Currency::EUR, $currency);
    }

    #[Test]
    public function missingCurrencyDefaultsToAed(): void
    {
        $request = $this->makeRequest('/v3/products');

        $this->middleware->process($request, $this->handler);

        $currency = $this->handler->lastRequest?->getAttribute(
            CurrencyContextMiddleware::ATTR_DISPLAY_CURRENCY,
        );
        self::assertSame(Currency::AED, $currency);
    }

    #[Test]
    public function unknownCurrencyDefaultsToAed(): void
    {
        // Q-FallbackBehavior = B locked: graceful degradation
        $request = $this->makeRequest('/v3/products?currency=BHD');

        $this->middleware->process($request, $this->handler);

        $currency = $this->handler->lastRequest?->getAttribute(
            CurrencyContextMiddleware::ATTR_DISPLAY_CURRENCY,
        );
        self::assertSame(Currency::AED, $currency);
    }

    #[Test]
    public function emptyCurrencyDefaultsToAed(): void
    {
        $request = $this->makeRequest('/v3/products?currency=');

        $this->middleware->process($request, $this->handler);

        $currency = $this->handler->lastRequest?->getAttribute(
            CurrencyContextMiddleware::ATTR_DISPLAY_CURRENCY,
        );
        self::assertSame(Currency::AED, $currency);
    }

    #[Test]
    public function downstreamHandlerReceivesAttributeBoundRequest(): void
    {
        // Sanity: the middleware delegates to handler->handle with
        // the modified request, not the original.
        $request = $this->makeRequest('/v3/products?currency=GBP');

        $this->middleware->process($request, $this->handler);

        $captured = $this->handler->lastRequest;
        self::assertNotNull($captured);
        self::assertNotSame($request, $captured);  // immutable PSR-7: different instance
    }

    // =================================================================
    // Helpers
    // =================================================================

    private function makeRequest(string $uri): ServerRequestInterface
    {
        return (new ServerRequestFactory())->createServerRequest('GET', $uri);
    }
}

final class CapturingHandler implements RequestHandlerInterface
{
    public ?ServerRequestInterface $lastRequest = null;

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $this->lastRequest = $request;
        return (new ResponseFactory())->createResponse(200);
    }
}
