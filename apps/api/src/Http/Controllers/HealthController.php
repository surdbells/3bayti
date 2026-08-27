<?php

declare(strict_types=1);

namespace Bayti\Api\Http\Controllers;

use Bayti\Api\Http\Responder;
use Bayti\Api\Infrastructure\Cache\KeyValueStore;
use Doctrine\DBAL\Connection;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * Health-check endpoints, TWO of them, deliberately split.
 *
 * Why two endpoints (liveness vs readiness)
 * -----------------------------------------
 *
 * `/v3/health` (liveness):
 *   "Is this PHP process running and able to serve HTTP?"
 *   Always returns 200 with no external dependencies. Used by
 *   container orchestrators (App Platform's container health check)
 *   to decide whether to KILL AND RESTART the container.
 *
 *   Critically, this endpoint does NOT touch the database. A DB
 *   outage shouldn't trigger container restarts, restarting would
 *   just produce more containers that all fail their checks against
 *   the same broken DB. Better to stay up and let users see a 503
 *   with a helpful error.
 *
 * `/v3/health/ready` (readiness):
 *   "Can this instance serve real requests right now?"
 *   Pings the database. Returns 503 if DB is unreachable. Used by:
 *     - Deploy gates: don't route traffic to a new deploy until
 *       /ready returns 200
 *     - Human monitoring dashboards: "is everything working?"
 *     - CI smoke tests: post-deploy verification
 *
 * Response shape
 * --------------
 *   200 OK
 *   {
 *     "status": "ok",
 *     "service": "3bayti-api",
 *     "version": "dev|<git-sha>",
 *     "timestamp": "2026-05-06T22:35:00+00:00",
 *     "checks": {              // /ready only
 *       "database": "ok"
 *     }
 *   }
 *
 *   503 Service Unavailable (only from /ready when DB is down)
 *   {
 *     "status": "degraded",
 *     "service": "3bayti-api",
 *     "version": "dev",
 *     "timestamp": "...",
 *     "checks": {
 *       "database": "error: connection refused"
 *     }
 *   }
 */
final class HealthController
{
    use Responder;

    /**
     * @param ?Connection $connection Optional, only injected for the
     *                                /ready route. Liveness can run
     *                                without a working container -
     *                                actually it MUST run without one,
     *                                or we couldn't health-check a
     *                                broken DB instance.
     * @param ?KeyValueStore $cache   Optional Redis-backed cache. If
     *                                provided, /ready pings it. If
     *                                null (e.g. tests), Redis check
     *                                is skipped from the response.
     */
    public function __construct(
        protected readonly ResponseFactoryInterface $responseFactory,
        private readonly ?Connection $connection = null,
        private readonly ?KeyValueStore $cache = null,
    ) {
    }

    protected function getResponseFactory(): ResponseFactoryInterface
    {
        return $this->responseFactory;
    }

    /**
     * GET /v3/health, liveness. No external dependencies.
     */
    public function liveness(ServerRequestInterface $request): ResponseInterface
    {
        $payload = $this->basePayload();
        return $this->ok($payload)
            ->withHeader('Cache-Control', 'no-store');
    }

    /**
     * GET /v3/health/ready, readiness. Includes DB ping.
     */
    public function readiness(ServerRequestInterface $request): ResponseInterface
    {
        $checks = [];
        $allOk = true;

        // Database ping. Doctrine's Connection::executeQuery against
        // 'SELECT 1' is the standard portable health-ping. We catch
        // every exception type because we don't know what the driver
        // might throw (DBAL Exception, PDO Exception, even nested
        // ConnectionException). Any failure → degraded.
        if ($this->connection !== null) {
            try {
                $this->connection->executeQuery('SELECT 1');
                $checks['database'] = 'ok';
            } catch (\Throwable $e) {
                $checks['database'] = 'error: ' . $this->safeMessage($e);
                $allOk = false;
            }
        } else {
            // No connection bound → can't check, treat as degraded.
            // Still report a 'database' key so callers always see
            // a consistent response shape.
            $checks['database'] = 'error: connection not configured';
            $allOk = false;
        }

        // Redis (KeyValueStore) ping. Only included if a cache is
        // bound, tests that don't wire one don't see the key in
        // the response. ping() is the explicit "does not throw"
        // method on KeyValueStore, returns false on any failure.
        //
        // Failed Redis ping marks the WHOLE readiness as degraded
        // (allOk=false → 503). Trade-off: this is correct behaviour
        // for "should we route traffic here?" but it does mean a
        // Redis outage takes the API out of rotation. Worth flagging
        //, if Redis is down and the API is fail-open at the OTP
        // layer (per M1.6.1.A design), should /ready also be more
        // tolerant? Probably yes, but defaulting to strict is the
        // safer choice; revisit if Redis flakiness becomes an issue.
        if ($this->cache !== null) {
            if ($this->cache->ping()) {
                $checks['redis'] = 'ok';
            } else {
                $checks['redis'] = 'error: ping failed';
                $allOk = false;
            }
        }

        $payload = $this->basePayload();
        $payload['status'] = $allOk ? 'ok' : 'degraded';
        $payload['checks'] = $checks;

        $response = $this->getResponseFactory()->createResponse($allOk ? 200 : 503);
        $json = json_encode($payload, JSON_UNESCAPED_SLASHES);
        $response->getBody()->write($json !== false ? $json : '{}');
        return $response
            ->withHeader('Content-Type', 'application/json')
            ->withHeader('Cache-Control', 'no-store');
    }

    /**
     * @return array<string, mixed>
     */
    private function basePayload(): array
    {
        return [
            'status' => 'ok',
            'service' => '3bayti-api',
            'version' => $_ENV['APP_VERSION'] ?? 'dev',
            'timestamp' => gmdate('c'),
        ];
    }

    /**
     * Sanitise an exception message for inclusion in a public health
     * response. Strips file paths, line numbers, anything that could
     * leak internal structure to a probe.
     */
    private function safeMessage(\Throwable $e): string
    {
        $msg = $e->getMessage();
        // Remove any path-like strings (anything starting with / and
        // having more than one /).
        $msg = preg_replace('/(\/[^\s:]+){2,}/', '<path>', $msg) ?? $msg;
        // Truncate.
        return substr($msg, 0, 200);
    }
}
