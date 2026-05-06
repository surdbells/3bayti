<?php

declare(strict_types=1);

namespace Bayti\Api\Http\Controllers;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * Health-check endpoint.
 *
 * Used by:
 *   - Load balancers / hosting platform health probes
 *   - Local dev to confirm the API is up
 *   - CI smoke tests after deploy
 *
 * Deliberately does NOT touch the database or any external service —
 * a health check that depends on Postgres being up is a "Postgres check",
 * not a "is the API itself responsive" check. We add deeper checks
 * (/v3/health/db, /v3/health/redis) later when we have those services.
 */
final class HealthController
{
    public function __invoke(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $payload = [
            'status' => 'ok',
            'service' => '3bayti-api',
            'version' => $_ENV['APP_VERSION'] ?? 'dev',
            'timestamp' => gmdate('c'),
        ];

        $response->getBody()->write(json_encode($payload, JSON_UNESCAPED_SLASHES));

        return $response
            ->withHeader('Content-Type', 'application/json')
            ->withHeader('Cache-Control', 'no-store')
            ->withStatus(200);
    }
}
