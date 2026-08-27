<?php

declare(strict_types=1);

namespace Bayti\Api\Http\Middleware;

use Bayti\Api\Domain\Currency\Currency;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * Resolve display currency from request and attach to attributes
 * (M3.2.X.15-D).
 *
 * Q-RequestContext = A locked: explicit `?currency=USD` query
 * parameter only. No IP-based auto-detection (privacy + edge
 * cases), no Accept-Language fallback (the user's browser
 * language doesn't reliably correspond to their preferred
 * currency for shopping). Client UIs persist the customer's
 * chosen currency in localStorage and pass it on every catalog
 * request.
 *
 * Q-FallbackBehavior = B locked: unknown/empty/missing currency
 * silently degrades to AED rather than 422'ing browsing. A stale
 * client passing a removed currency shouldn't break.
 *
 * Downstream code reads the chosen currency via:
 *   \$currency = \$request->getAttribute(
 *       CurrencyContextMiddleware::ATTR_DISPLAY_CURRENCY,
 *       Currency::AED,
 *   );
 *
 * The default-on-getAttribute argument guarantees a Currency
 * instance even if some path skips the middleware (e.g. test
 * setups, internal cron tasks rendering for ops). No null
 * checks needed in downstream code.
 *
 * Placement
 * =========
 * Should be wired BEFORE the catalog routes but doesn't need to
 * run before AuthMiddleware, currency choice is independent of
 * authentication. v1 mounts it at the catalog route group level
 * in routes.php.
 */
final class CurrencyContextMiddleware implements MiddlewareInterface
{
    public const ATTR_DISPLAY_CURRENCY = 'display_currency';

    public function process(
        ServerRequestInterface $request,
        RequestHandlerInterface $handler,
    ): ResponseInterface {
        /** @var array<string, mixed> $query */
        $query = $request->getQueryParams();
        $raw = $query['currency'] ?? null;

        $currency = Currency::fromQueryParamOrAed($raw);

        return $handler->handle(
            $request->withAttribute(self::ATTR_DISPLAY_CURRENCY, $currency),
        );
    }
}
