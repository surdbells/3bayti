<?php

declare(strict_types=1);

namespace Bayti\Api\Tests;

use Bayti\Api\Bootstrap;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Slim\Psr7\Factory\ServerRequestFactory;

use Bayti\Api\Http\Controllers\HealthController;

/**
 * Smoke test for /v3/health.
 *
 * Verifies:
 *   - The Slim app boots from Bootstrap::createApp() without errors
 *   - GET /v3/health returns 200
 *   - The response body is JSON with a "status":"ok" field
 *
 * If this test fails, the API can't even boot — every other test
 * is moot. That's why it's the first one written.
 */
#[CoversClass(HealthController::class)]
#[CoversClass(Bootstrap::class)]
final class HealthTest extends TestCase
{
    #[Test]
    public function healthEndpointReturnsOk(): void
    {
        $app = Bootstrap::createApp();

        $request = (new ServerRequestFactory())->createServerRequest('GET', '/v3/health');
        $response = $app->handle($request);

        self::assertSame(200, $response->getStatusCode());
        self::assertSame('application/json', $response->getHeaderLine('Content-Type'));

        $body = (string) $response->getBody();
        $payload = json_decode($body, true, flags: JSON_THROW_ON_ERROR);

        self::assertIsArray($payload);
        self::assertSame('ok', $payload['status']);
        self::assertSame('3bayti-api', $payload['service']);
        self::assertArrayHasKey('timestamp', $payload);
        self::assertArrayHasKey('version', $payload);
    }
}
