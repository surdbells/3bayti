<?php

declare(strict_types=1);

namespace Bayti\Api\Domain\Currency;

use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;

/**
 * Convert AED-denominated amounts to display currencies
 * (M3.2.X.15-C).
 *
 * Read-only service. Loads all FX rates once per request into an
 * in-memory map keyed by target_code; subsequent convert() calls
 * are pure arithmetic with no DB roundtrip.
 *
 * Inputs and outputs are decimal STRINGS, not floats. We use
 * bcmath for the multiplication and a manual HALF_UP rounding
 * step. Floats would introduce drift (0.1 + 0.2 = 0.30000...4)
 * which compounds across many products on a catalog page.
 *
 * Q-Rounding = A locked: 2 decimal places, HALF_UP semantics.
 * All v1 currencies (AED, USD, EUR, SAR, GBP) use 2 fractional
 * digits natively. If a future currency uses 0 fractional digits
 * (JPY) or 3 (BHD, KWD, OMR), this method needs per-currency
 * rounding policy (deferred to operator follow-up #28).
 *
 * Q-RateRefresh = C locked: sticky last-known rates with PSR-3
 * staleness warning. Rates older than STALE_AFTER_HOURS = 48
 * trigger 'fx_rate.stale' warnings without breaking conversion.
 * Operator dashboards surface these for refresh.
 *
 * Identity short-circuit: convert(amount, AED) returns
 * {amount: <unchanged>, currency: 'AED'} without DB lookup or
 * arithmetic.
 *
 * Missing-rate fallback: if no rate exists for the target
 * (shouldn't happen since X.15-A seeds all 5 supported targets,
 * but defensive in case a row is deleted manually), falls back
 * to returning the source AED unchanged. The caller's
 * source_currency in the output makes the fallback visible to
 * clients.
 */
final class CurrencyConversionService
{
    private const STALE_AFTER_HOURS = 48;
    private const SCALE = 8;     // bcmath internal scale for multiplication
    private const DECIMALS = 2;  // output rounding (Q-Rounding = A locked)

    /**
     * In-memory cache of rates loaded once per service instance.
     * The Slim DI container is per-request so this naturally
     * resets between requests — no manual invalidation needed.
     *
     * @var array<string, FxRate>|null map of target_code → FxRate
     */
    private ?array $rates = null;

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly LoggerInterface $logger,
    ) {
    }

    /**
     * Convert an AED amount to the target currency.
     *
     * @param string $aedAmount canonical AED amount as a decimal
     *                          string (e.g. '365.00')
     * @return array{
     *     amount: string,
     *     currency: string,
     *     source_amount: string,
     *     source_currency: string,
     *     converted: bool
     * }
     */
    public function convert(string $aedAmount, Currency $target): array
    {
        // Validate input — bcmath silently coerces bad input to 0,
        // which would zero out a customer's displayed price.
        if (!preg_match('/^-?\d+(\.\d+)?$/', $aedAmount)) {
            throw new \InvalidArgumentException(
                "Amount must be a decimal string; got: {$aedAmount}",
            );
        }

        // Identity short-circuit: AED→AED needs no math + no DB.
        if ($target === Currency::AED) {
            return [
                'amount' => $this->roundHalfUp($aedAmount),
                'currency' => Currency::AED->value,
                'source_amount' => $this->roundHalfUp($aedAmount),
                'source_currency' => Currency::AED->value,
                'converted' => false,
            ];
        }

        $rate = $this->getRate($target);
        if ($rate === null) {
            // Defensive missing-rate fallback. Logged as warning
            // so ops sees the misconfiguration but customer still
            // gets a coherent response.
            $this->logger->warning('fx_rate.missing', [
                'base' => Currency::AED->value,
                'target' => $target->value,
            ]);
            return [
                'amount' => $this->roundHalfUp($aedAmount),
                'currency' => Currency::AED->value,
                'source_amount' => $this->roundHalfUp($aedAmount),
                'source_currency' => Currency::AED->value,
                'converted' => false,
            ];
        }

        $converted = bcmul($aedAmount, $rate->getRate(), self::SCALE);

        return [
            'amount' => $this->roundHalfUp($converted),
            'currency' => $target->value,
            'source_amount' => $this->roundHalfUp($aedAmount),
            'source_currency' => Currency::AED->value,
            'converted' => true,
        ];
    }

    /**
     * Convert in batch — same shape as convert() but takes a list
     * of amounts and returns a parallel list. Lets serializers
     * pre-load the rate once and apply it to many products without
     * per-call DB pressure (the cache is already in-memory but
     * skipping the array lookup per call is still a win).
     *
     * Not strictly necessary in v1 (the in-memory cache makes
     * convert() fast enough) but useful for the X.15-E serializer
     * integration where dozens of products share the same target.
     *
     * @param list<string> $aedAmounts
     * @return list<array{amount: string, currency: string, source_amount: string, source_currency: string, converted: bool}>
     */
    public function convertBatch(array $aedAmounts, Currency $target): array
    {
        return array_map(
            fn (string $a): array => $this->convert($a, $target),
            $aedAmounts,
        );
    }

    private function getRate(Currency $target): ?FxRate
    {
        $rates = $this->loadRates();
        $rate = $rates[$target->value] ?? null;

        // Staleness check — independent of conversion logic.
        // Fires per-call rather than per-load so admin dashboards
        // see the warning every time a stale rate is hit, not
        // just once on load.
        if ($rate !== null && $this->isStale($rate)) {
            $this->logger->warning('fx_rate.stale', [
                'base' => $rate->getBaseCode(),
                'target' => $rate->getTargetCode(),
                'updated_at' => $rate->getUpdatedAt()->format(\DateTimeInterface::ATOM),
                'age_hours' => $this->ageHours($rate),
                'threshold_hours' => self::STALE_AFTER_HOURS,
            ]);
        }

        return $rate;
    }

    /**
     * @return array<string, FxRate>
     */
    private function loadRates(): array
    {
        if ($this->rates !== null) {
            return $this->rates;
        }

        /** @var FxRateRepository $repo */
        $repo = $this->em->getRepository(FxRate::class);
        $rows = $repo->findAllRates();

        $this->rates = [];
        foreach ($rows as $row) {
            $this->rates[$row->getTargetCode()] = $row;
        }
        return $this->rates;
    }

    private function isStale(FxRate $rate): bool
    {
        return $this->ageHours($rate) >= self::STALE_AFTER_HOURS;
    }

    private function ageHours(FxRate $rate): int
    {
        $age = (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))
            ->getTimestamp() - $rate->getUpdatedAt()->getTimestamp();
        return (int) floor($age / 3600);
    }

    /**
     * HALF_UP rounding via bcmath. Standard bcadd-zero-then-truncate
     * trick: shift left by DECIMALS, round, shift right.
     *
     * For 0.1 + 0.2 -> 0.30000004 we'd get an incorrect 0.30
     * (rounded to 2 decimals correctly), but bcmath doesn't
     * introduce that drift in the first place.
     */
    private function roundHalfUp(string $value): string
    {
        // bcmath doesn't have a native round() — implement HALF_UP:
        //   value > 0: add 0.5 * 10^-DECIMALS then truncate
        //   value < 0: subtract 0.5 * 10^-DECIMALS then truncate
        //   value = 0: trivially '0.00'
        $cmp = bccomp($value, '0', self::SCALE);
        if ($cmp === 0) {
            return $this->formatToDecimals('0', self::DECIMALS);
        }

        $bias = bcpow('10', (string) (-self::DECIMALS), self::SCALE);
        $halfBias = bcdiv($bias, '2', self::SCALE);

        if ($cmp > 0) {
            $biased = bcadd($value, $halfBias, self::SCALE);
        } else {
            $biased = bcsub($value, $halfBias, self::SCALE);
        }

        // Truncate to DECIMALS via bcadd(., 0, DECIMALS)
        $truncated = bcadd($biased, '0', self::DECIMALS);

        return $this->formatToDecimals($truncated, self::DECIMALS);
    }

    /**
     * Normalise the bcmath output to the exact DECIMALS scale
     * (bcadd may emit '0' or '78' without trailing zeros if the
     * fractional portion is missing).
     */
    private function formatToDecimals(string $value, int $decimals): string
    {
        // bcadd already truncates to the target scale; just make
        // sure trailing zeros are present.
        if (!str_contains($value, '.')) {
            return $value . '.' . str_repeat('0', $decimals);
        }
        [$whole, $frac] = explode('.', $value, 2);
        $frac = substr($frac, 0, $decimals);
        $frac = str_pad($frac, $decimals, '0');
        return $whole . '.' . $frac;
    }
}
