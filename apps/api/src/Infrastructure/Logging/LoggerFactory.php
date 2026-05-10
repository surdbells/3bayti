<?php

declare(strict_types=1);

namespace Bayti\Api\Infrastructure\Logging;

use Bayti\Api\Http\Middleware\RequestIdContext;
use Monolog\Formatter\JsonFormatter;
use Monolog\Handler\RotatingFileHandler;
use Monolog\Handler\StreamHandler;
use Monolog\Level;
use Monolog\LogRecord;
use Monolog\Logger;
use Psr\Log\LoggerInterface;

/**
 * Factory for the application's PSR-3 logger.
 *
 * Output destination
 * ------------------
 * Per-day rotating file under apps/api/var/logs/, e.g.
 *   var/logs/3bayti-api-2026-05-10.log
 *
 * Each line is a single JSON object with these keys:
 *   - datetime    — ISO-8601 with microseconds + timezone
 *   - channel     — "3bayti-api" (we don't split into multiple channels)
 *   - level_name  — DEBUG / INFO / WARNING / ERROR / CRITICAL
 *   - message     — the human-readable message
 *   - context     — caller-supplied associative array
 *   - extra       — auto-added: request_id, php_pid, hostname
 *
 * Why JSON
 * --------
 * Greppability + structured search. `jq '.level_name=="ERROR"' < log`
 * is friendlier than regex on free text. Future log aggregators
 * (Loki, Elasticsearch, CloudWatch) parse JSON natively.
 *
 * Why per-day file (not single growing file or syslog)
 * ----------------------------------------------------
 *   - Per-day: trivial to find logs for a specific day, trivial to
 *     drop old days (just delete the file)
 *   - Single growing file: pain to manage; logrotate adds another
 *     thing to configure
 *   - Syslog: portable but loses structured JSON (syslog wants free
 *     text); future M5+ work could ship to syslog AND a file
 *
 * Why RotatingFileHandler not logrotate
 * -------------------------------------
 *   - In-app rotation = no system config. Lives with the app.
 *   - logrotate would also work but introduces a "is logrotate
 *     installed and configured?" question across deploys.
 *   - RotatingFileHandler with maxFiles=14 keeps 2 weeks; older
 *     files auto-deleted. Good enough at our scale.
 *
 * Log levels by environment
 * -------------------------
 *   - prod: WARNING and above (DEBUG/INFO are noise in production)
 *   - dev/test: DEBUG and above
 * Configurable via LOG_LEVEL env if both defaults are wrong.
 *
 * Request correlation
 * -------------------
 * RequestIdMiddleware sets RequestIdContext for each request. Our
 * processor reads it and attaches to every log record's `extra` map.
 * Logs from one request all carry the same request_id, making "show
 * me everything from this request" trivial.
 */
final class LoggerFactory
{
    /**
     * Build a configured Monolog Logger.
     *
     * @param string $logDir   Directory for log files (will be created if missing)
     * @param string $env      'prod' | 'dev' | 'test' — picks default level
     * @param ?string $levelOverride Explicit level name from LOG_LEVEL env, or null
     */
    public static function create(
        string $logDir,
        string $env = 'prod',
        ?string $levelOverride = null,
    ): LoggerInterface {
        // Ensure the log directory exists. RotatingFileHandler tries
        // to write inside it; if missing, the first call throws.
        if (!is_dir($logDir)) {
            // Recursive create with sensible perms. 0775 lets group
            // (e.g. www) read/write — important when logs are written
            // by FPM workers but read by an ops human.
            @mkdir($logDir, 0775, true);
        }

        $logger = new Logger('3bayti-api');

        $level = self::resolveLevel($env, $levelOverride);

        // Per-day rotation. Filename pattern: <prefix>-YYYY-MM-DD.log
        // maxFiles=14 keeps 2 weeks; older auto-deleted on rotation.
        $handler = new RotatingFileHandler(
            filename: $logDir . '/3bayti-api.log',
            maxFiles: 14,
            level: $level,
        );

        // JSON output, one record per line.
        // includeStacktraces(true) attaches PHP stack traces to log
        // records that include an exception in context — useful for
        // post-mortem debugging.
        $formatter = new JsonFormatter();
        $formatter->includeStacktraces(true);
        $handler->setFormatter($formatter);

        $logger->pushHandler($handler);

        // Processor that attaches per-request context.
        $logger->pushProcessor(self::buildRequestProcessor());

        return $logger;
    }

    /**
     * Resolve which log level to use.
     *
     * Priority:
     *   1. Explicit override (LOG_LEVEL env var if valid)
     *   2. Environment-specific default (prod=WARNING, others=DEBUG)
     */
    private static function resolveLevel(string $env, ?string $override): Level
    {
        if ($override !== null && $override !== '') {
            // Try to parse — e.g. "INFO", "warning", "Error"
            $upper = strtoupper(trim($override));
            // Map names to Level enum cases.
            // Monolog\Level::fromName throws on invalid; we want graceful fallback.
            try {
                return Level::fromName($upper);
            } catch (\Throwable) {
                // Fall through to env default. We don't log this
                // because the logger isn't built yet.
            }
        }

        return $env === 'prod' ? Level::Warning : Level::Debug;
    }

    /**
     * Build the request-id processor.
     *
     * Returns a callable that Monolog applies to every record before
     * formatting. The callable adds request_id (if available),
     * hostname, and the PHP process id to record.extra.
     */
    private static function buildRequestProcessor(): callable
    {
        // Cache hostname once per worker — it doesn't change.
        $hostname = gethostname() ?: 'unknown';

        return static function (LogRecord $record) use ($hostname): LogRecord {
            $requestId = RequestIdContext::get();

            $extra = $record->extra;
            if ($requestId !== null) {
                $extra['request_id'] = $requestId;
            }
            $extra['hostname'] = $hostname;
            $extra['pid'] = getmypid();

            // LogRecord is immutable; build a new one with updated extra.
            return $record->with(extra: $extra);
        };
    }
}
