<?php

declare(strict_types=1);

namespace Bayti\Api\Domain\Currency;

/**
 * ISO 4217 currencies supported for display in v1 (M3.2.X.15-B).
 *
 * AED is the canonical settlement currency for every Cart, Order,
 * payment, payout, and refund in the system. The other cases
 * here are DISPLAY ONLY — they affect ProductSerializer output
 * when a customer queries with ?currency=XXX, nothing else.
 *
 * Q-Currencies = A locked: AED + 4 most-likely tourist origins
 *   AED — UAE Dirham (base currency)
 *   USD — US Dollar (global default)
 *   EUR — Euro (European tourist origin)
 *   SAR — Saudi Riyal (largest GCC neighbour traffic)
 *   GBP — British Pound (UK tourist + UAE expat traffic)
 *
 * Future GCC currencies (BHD, KWD, OMR, QAR) and additional
 * tourist origins (CAD, AUD, CHF) are operator follow-up #28
 * — add cases here + INSERT seed rows + the conversion service
 * automatically picks them up.
 *
 * Q-FallbackBehavior = B locked: tryFrom() returning null at
 * the middleware boundary triggers a silent fallback to AED.
 * Stale clients sending removed currencies degrade gracefully
 * rather than 422'ing browsing.
 */
enum Currency: string
{
    case AED = 'AED';
    case USD = 'USD';
    case EUR = 'EUR';
    case SAR = 'SAR';
    case GBP = 'GBP';

    /**
     * @return list<string>
     */
    public static function supportedCodes(): array
    {
        return array_map(fn (self $c): string => $c->value, self::cases());
    }

    /**
     * Best-effort parse. Returns AED for null / empty / unknown
     * input — the locked Q-FallbackBehavior behaviour.
     */
    public static function fromQueryParamOrAed(mixed $raw): self
    {
        if (!is_string($raw) || $raw === '') {
            return self::AED;
        }
        $upper = strtoupper($raw);
        return self::tryFrom($upper) ?? self::AED;
    }

    /**
     * The base settlement currency for the marketplace. Catalog
     * code paths that don't have a display-currency context should
     * default to this.
     */
    public static function base(): self
    {
        return self::AED;
    }
}
