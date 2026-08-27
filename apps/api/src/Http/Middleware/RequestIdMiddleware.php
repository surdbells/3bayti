<?php

declare(strict_types=1);

namespace Bayti\Api\Http\Middleware;

use Bayti\Api\Domain\Audit\AuditContext;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * Request correlation id middleware.
 *
 * Every request gets a unique id that propagates through:
 *   - Request attribute (downstream handlers can read it)
 *   - Response header X-Request-Id (clients can correlate)
 *   - Monolog log context (logs from this request all share the id)
 *   - Audit log rows (M1.6.1.C)
 *
 * Why
 * ---
 * When debugging, "show me everything that happened during this one
 * request" is the most common question. With a stable correlation
 * id, that's a single grep across log files. Without it, you cross-
 * reference timestamps and IPs and pray nothing else fired in the
 * same second.
 *
 * Client-supplied vs generated
 * ----------------------------
 * If the client sends a UUID-format X-Request-Id, we honor it. This
 * means a frontend can attach its own correlation id and trace
 * request flows end-to-end (browser → API → backend → DB → response).
 *
 * If the client sends nothing, or sends something that isn't a UUID,
 * we generate a fresh UUID v4. We DO NOT trust arbitrary strings:
 *   - Limits log injection (someone setting X-Request-Id to a
 *     newline + fake log entries)
 *   - Keeps the log format predictable (always UUID, always 36 chars)
 *   - Prevents an attacker from setting all their requests to a
 *     single id to flood-grep our logs
 *
 * Position in middleware chain
 * ----------------------------
 * Should run BEFORE AuthMiddleware so 401 responses still get a
 * correlation id (otherwise a flood of bad-auth attempts is hard to
 * diagnose). Should run AFTER ApiErrorMiddleware (which is the
 * outermost) so unhandled exceptions still produce a response with
 * the X-Request-Id header.
 *
 * Order in Bootstrap.php is LIFO at execution time, so adding this
 * AFTER body-parsing and BEFORE auth = added between them.
 */
final class RequestIdMiddleware implements MiddlewareInterface
{
    public const ATTR_REQUEST_ID = 'requestId';
    public const HEADER_NAME = 'X-Request-Id';

    /**
     * Strict UUID v4 pattern. We accept any UUID variant the client
     * sends (v1/v4/v7), what matters is the format, not the
     * generation algorithm.
     */
    private const UUID_PATTERN = '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i';

    public function process(
        ServerRequestInterface $request,
        RequestHandlerInterface $handler,
    ): ResponseInterface {
        $requestId = $this->resolveRequestId($request);

        // Attach to the request so downstream handlers (controllers,
        // audit emitter, etc.) can read it via:
        //     $request->getAttribute(RequestIdMiddleware::ATTR_REQUEST_ID)
        $request = $request->withAttribute(self::ATTR_REQUEST_ID, $requestId);

        // Make it available to Monolog. We use a globally-accessible
        // store rather than threading it through every logger call -
        // Monolog's processor pattern picks it up automatically.
        // See LoggerFactory::buildRequestProcessor().
        RequestIdContext::set($requestId);

        // Reset per-request audit state and stamp client meta (IP + UA). The
        // actor id is filled in later by the auth middleware once the token is
        // validated; unauthenticated requests stay actor-less (System).
        AuditContext::reset();
        AuditContext::setRequestMeta($this->clientIp($request), $request->getHeaderLine('User-Agent') ?: null);

        try {
            $response = $handler->handle($request);
        } finally {
            // Clear the static after the request to avoid bleeding
            // between requests in the same FPM worker. Worth doing
            // even though the next request will overwrite, defense
            // in depth against weird re-entrancy bugs.
            RequestIdContext::clear();
            AuditContext::reset();
        }

        // Echo it back to the client so they can correlate too.
        return $response->withHeader(self::HEADER_NAME, $requestId);
    }

    /**
     * Best-effort client IP from REMOTE_ADDR, validated so a weird server param
     * (e.g. a unix socket path) doesn't land in the audit log. Returns null when
     * unavailable/invalid.
     */
    private function clientIp(ServerRequestInterface $request): ?string
    {
        $ip = $request->getServerParams()['REMOTE_ADDR'] ?? null;
        if (!is_string($ip) || $ip === '' || filter_var($ip, FILTER_VALIDATE_IP) === false) {
            return null;
        }
        return $ip;
    }

    /**
     * Decide the request id: client-provided if valid, else generate.
     */
    private function resolveRequestId(ServerRequestInterface $request): string
    {
        $clientProvided = $request->getHeaderLine(self::HEADER_NAME);

        if ($clientProvided !== '' && preg_match(self::UUID_PATTERN, $clientProvided) === 1) {
            // Lowercase to keep our logs consistent, UUIDs are
            // case-insensitive but text-grep isn't.
            return strtolower($clientProvided);
        }

        return self::generateUuidV4();
    }

    /**
     * Generate a UUID v4. We do this manually rather than pulling
     * ramsey/uuid for ONE function, a 6-line implementation is
     * worth the saved dependency.
     */
    private static function generateUuidV4(): string
    {
        $bytes = random_bytes(16);
        // Version 4: high nibble of byte 6 = 0x4
        $bytes[6] = chr((ord($bytes[6]) & 0x0f) | 0x40);
        // Variant 10xx: high two bits of byte 8 = 10
        $bytes[8] = chr((ord($bytes[8]) & 0x3f) | 0x80);

        return vsprintf(
            '%s%s-%s-%s-%s-%s%s%s',
            str_split(bin2hex($bytes), 4),
        );
    }
}
