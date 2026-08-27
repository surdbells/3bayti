<?php

declare(strict_types=1);

namespace Bayti\Api\Http\Middleware;

/**
 * Per-request static holder for the correlation id.
 *
 * Why static state, and why it's safe here
 * ------------------------------------------
 * Monolog's processor pattern works by attaching a callable that
 * decorates each log record with extra context. The processor has
 * to be installed when the Logger is built (DI container time);
 * but the request id only exists at request time. Threading the
 * request id through the logger as a constructor parameter would
 * mean rebuilding the logger every request, defeating DI.
 *
 * The static-state pattern resolves this: the processor reads from
 * RequestIdContext::get() each time a log record is emitted. The
 * middleware sets/clears the context per-request.
 *
 * Why this is safe
 * ----------------
 * PHP-FPM is single-threaded per worker. There's no concurrent
 * access to mutate. Each request runs serially in a worker, and
 * the middleware's try/finally ensures clear() runs even on
 * exceptions.
 *
 * Why we don't just put it in $_SERVER or similar
 * -----------------------------------------------
 * We could, but $_SERVER is a god object, too easy for unrelated
 * code to corrupt or read it. A dedicated holder is more honest
 * about what we're doing (per-request global) and lets us add
 * type safety + tests around it.
 */
final class RequestIdContext
{
    /**
     * The current request id, or null when no request is in flight
     * (CLI scripts, tests, or between requests in a worker).
     */
    private static ?string $requestId = null;

    public static function set(string $id): void
    {
        self::$requestId = $id;
    }

    public static function get(): ?string
    {
        return self::$requestId;
    }

    public static function clear(): void
    {
        self::$requestId = null;
    }
}
