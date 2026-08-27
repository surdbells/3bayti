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
 * Smoke test for /v3/health and /v3/health/ready.
 *
 * Verifies:
 *   - The Slim app boots from Bootstrap::createApp() without errors
 *   - GET /v3/health (liveness) returns 200 with no DB dependency
 *   - GET /v3/health/ready (readiness) returns either 200 (DB up) or
 *     503 (DB down), both are acceptable in tests where there's no
 *     real Postgres connection. We just verify the response shape.
 *
 * If liveness fails, the API can't even boot, every other test is
 * moot. That's why it's the first one written.
 */
#[CoversClass(HealthController::class)]
#[CoversClass(Bootstrap::class)]
final class HealthTest extends TestCase
{
    #[Test]
    public function livenessEndpointReturnsOk(): void
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

        // Liveness must NOT include 'checks', that's readiness's job.
        self::assertArrayNotHasKey('checks', $payload);
    }

    #[Test]
    public function readinessEndpointReturnsCanonicalShape(): void
    {
        // Note: in CI there's no real Postgres, so the DB ping in
        // /ready will either fail (with 503) or possibly succeed if
        // Doctrine's lazy connection masks it. Either is fine, we
        // only verify the response shape is what we expect.
        $app = Bootstrap::createApp();

        $request = (new ServerRequestFactory())->createServerRequest('GET', '/v3/health/ready');
        $response = $app->handle($request);

        // Status MUST be 200 or 503, nothing else.
        self::assertContains(
            $response->getStatusCode(),
            [200, 503],
            'Readiness must return 200 (ok) or 503 (degraded).',
        );
        self::assertSame('application/json', $response->getHeaderLine('Content-Type'));

        $payload = json_decode((string) $response->getBody(), true, flags: JSON_THROW_ON_ERROR);

        self::assertIsArray($payload);
        self::assertSame('3bayti-api', $payload['service']);
        self::assertArrayHasKey('checks', $payload);
        self::assertArrayHasKey('database', $payload['checks']);

        // status field must match HTTP status: 200 → 'ok', 503 → 'degraded'
        if ($response->getStatusCode() === 200) {
            self::assertSame('ok', $payload['status']);
            self::assertSame('ok', $payload['checks']['database']);
        } else {
            self::assertSame('degraded', $payload['status']);
            self::assertStringStartsWith('error:', $payload['checks']['database']);
        }
    }
}
