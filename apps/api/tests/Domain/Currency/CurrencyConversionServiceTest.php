<?php

declare(strict_types=1);

namespace Bayti\Api\Tests\Domain\Currency;

use Bayti\Api\Domain\Currency\Currency;
use Bayti\Api\Domain\Currency\CurrencyConversionService;
use Bayti\Api\Domain\Currency\FxRate;
use Bayti\Api\Domain\Currency\FxRateRepository;
use Bayti\Api\Tests\Support\InMemoryLogger;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for CurrencyConversionService (M3.2.X.15-C).
 *
 * Strategy: mock the EntityManager to return a fake repository
 * with a controlled list of FxRate entities. Verify:
 *   - bcmath multiplication is exact (no float drift)
 *   - HALF_UP rounding semantics on positive amounts
 *   - AED→AED identity short-circuit
 *   - Missing rate falls back to AED with warning log
 *   - Stale rate (> 48h) emits warning log but still converts
 *   - In-memory cache: single DB load per service instance
 *   - Output shape carries source + display amounts together
 */
#[CoversClass(CurrencyConversionService::class)]
final class CurrencyConversionServiceTest extends TestCase
{
    private InMemoryLogger $logger;

    protected function setUp(): void
    {
        $this->logger = new InMemoryLogger();
    }

    // =================================================================
    // Identity short-circuit
    // =================================================================

    #[Test]
    public function aedToAedIsIdentity(): void
    {
        $service = $this->makeService(rates: []);
        $result = $service->convert('365.00', Currency::AED);

        self::assertSame('365.00', $result['amount']);
        self::assertSame('AED', $result['currency']);
        self::assertSame('365.00', $result['source_amount']);
        self::assertSame('AED', $result['source_currency']);
        self::assertFalse($result['converted']);
    }

    #[Test]
    public function aedToAedSkipsDbLoad(): void
    {
        // Defensive: even with no rates seeded, AED→AED should work
        // without ever calling the repository.
        $em = $this->createMock(EntityManagerInterface::class);
        $em->expects(self::never())->method('getRepository');
        $service = new CurrencyConversionService($em, $this->logger);

        $result = $service->convert('100.00', Currency::AED);
        self::assertSame('100.00', $result['amount']);
    }

    // =================================================================
    // Conversion math
    // =================================================================

    #[Test]
    public function convertsAedToUsdViaBcmath(): void
    {
        // 365.00 AED * 0.27225 = 99.371250 → rounded HALF_UP → 99.37
        $service = $this->makeService(rates: [
            $this->makeRate('AED', 'USD', '0.27225000'),
        ]);

        $result = $service->convert('365.00', Currency::USD);

        self::assertSame('99.37', $result['amount']);
        self::assertSame('USD', $result['currency']);
        self::assertSame('365.00', $result['source_amount']);
        self::assertSame('AED', $result['source_currency']);
        self::assertTrue($result['converted']);
    }

    #[Test]
    public function halfUpRoundingAt5(): void
    {
        // 100.00 AED * 0.25180 = 25.18000, clean
        // 100.50 AED * 0.25180 = 25.30590 → 25.31 (HALF_UP boundary)
        $service = $this->makeService(rates: [
            $this->makeRate('AED', 'EUR', '0.25180000'),
        ]);

        $r1 = $service->convert('100.00', Currency::EUR);
        self::assertSame('25.18', $r1['amount']);

        $r2 = $service->convert('100.50', Currency::EUR);
        self::assertSame('25.31', $r2['amount']);
    }

    #[Test]
    public function smallAmountsRoundCorrectly(): void
    {
        // 0.10 AED * 0.27225 = 0.027225 → 0.03 (HALF_UP)
        $service = $this->makeService(rates: [
            $this->makeRate('AED', 'USD', '0.27225000'),
        ]);

        $result = $service->convert('0.10', Currency::USD);
        self::assertSame('0.03', $result['amount']);
    }

    #[Test]
    public function zeroAmountStaysZero(): void
    {
        $service = $this->makeService(rates: [
            $this->makeRate('AED', 'USD', '0.27225000'),
        ]);

        $result = $service->convert('0', Currency::USD);
        self::assertSame('0.00', $result['amount']);
    }

    #[Test]
    public function largeAmountConvertsWithoutOverflow(): void
    {
        // 1,000,000.00 AED * 0.21450 = 214500.00 GBP
        $service = $this->makeService(rates: [
            $this->makeRate('AED', 'GBP', '0.21450000'),
        ]);

        $result = $service->convert('1000000.00', Currency::GBP);
        self::assertSame('214500.00', $result['amount']);
    }

    #[Test]
    public function pegSarRateConverts(): void
    {
        // SAR is roughly pegged to AED, so the rate is close to 1
        $service = $this->makeService(rates: [
            $this->makeRate('AED', 'SAR', '1.02100000'),
        ]);

        $result = $service->convert('100.00', Currency::SAR);
        self::assertSame('102.10', $result['amount']);
    }

    // =================================================================
    // Input validation
    // =================================================================

    #[Test]
    public function rejectsNonNumericAmount(): void
    {
        $service = $this->makeService(rates: []);
        $this->expectException(\InvalidArgumentException::class);
        $service->convert('abc', Currency::USD);
    }

    #[Test]
    public function acceptsAmountWithoutDecimals(): void
    {
        $service = $this->makeService(rates: [
            $this->makeRate('AED', 'USD', '0.27225000'),
        ]);

        $result = $service->convert('100', Currency::USD);
        self::assertSame('27.23', $result['amount']);  // 100 * 0.27225 = 27.225 → 27.23 HALF_UP
    }

    // =================================================================
    // Missing rate / fallback
    // =================================================================

    #[Test]
    public function missingRateFallsBackToAedWithWarning(): void
    {
        // No rates loaded
        $service = $this->makeService(rates: []);

        $result = $service->convert('365.00', Currency::USD);

        // Falls back to AED amount + AED currency
        self::assertSame('365.00', $result['amount']);
        self::assertSame('AED', $result['currency']);
        self::assertSame('AED', $result['source_currency']);
        self::assertFalse($result['converted']);

        // Warning logged
        $warnings = $this->logger->findByMessage('fx_rate.missing');
        self::assertCount(1, $warnings);
        self::assertSame('warning', $warnings[0]['level']);
        self::assertSame('USD', $warnings[0]['context']['target']);
    }

    // =================================================================
    // Staleness
    // =================================================================

    #[Test]
    public function staleRateEmitsWarningButStillConverts(): void
    {
        $service = $this->makeService(rates: [
            $this->makeRate('AED', 'USD', '0.27225000', updatedAgoHours: 72),
        ]);

        $result = $service->convert('100.00', Currency::USD);

        // Conversion still works, sticky last-known rate
        self::assertSame('27.23', $result['amount']);
        self::assertSame('USD', $result['currency']);
        self::assertTrue($result['converted']);

        // Staleness warning fired
        $warnings = $this->logger->findByMessage('fx_rate.stale');
        self::assertCount(1, $warnings);
        self::assertSame('warning', $warnings[0]['level']);
        self::assertGreaterThanOrEqual(48, $warnings[0]['context']['age_hours']);
        self::assertSame(48, $warnings[0]['context']['threshold_hours']);
    }

    #[Test]
    public function freshRateNoStalenessWarning(): void
    {
        $service = $this->makeService(rates: [
            $this->makeRate('AED', 'USD', '0.27225000', updatedAgoHours: 2),
        ]);

        $service->convert('100.00', Currency::USD);

        self::assertCount(0, $this->logger->findByMessage('fx_rate.stale'));
    }

    // =================================================================
    // Caching
    // =================================================================

    #[Test]
    public function ratesLoadedOnceAcrossMultipleConvertCalls(): void
    {
        // Repository called only ONCE despite 3 conversions
        $repo = $this->createMock(FxRateRepository::class);
        $repo->expects(self::once())
            ->method('findAllRates')
            ->willReturn([
                $this->makeRate('AED', 'USD', '0.27225000'),
                $this->makeRate('AED', 'EUR', '0.25180000'),
            ]);

        $em = $this->createMock(EntityManagerInterface::class);
        $em->method('getRepository')->with(FxRate::class)->willReturn($repo);

        $service = new CurrencyConversionService($em, $this->logger);

        $service->convert('100.00', Currency::USD);
        $service->convert('200.00', Currency::EUR);
        $service->convert('300.00', Currency::USD);
    }

    // =================================================================
    // Batch
    // =================================================================

    #[Test]
    public function convertBatchAppliesSameTargetToEachItem(): void
    {
        $service = $this->makeService(rates: [
            $this->makeRate('AED', 'USD', '0.27225000'),
        ]);

        $results = $service->convertBatch(['100.00', '200.00', '350.00'], Currency::USD);

        self::assertCount(3, $results);
        self::assertSame('27.23', $results[0]['amount']);
        self::assertSame('54.45', $results[1]['amount']);
        self::assertSame('95.29', $results[2]['amount']);  // 350 * 0.27225 = 95.2875 → 95.29
    }

    // =================================================================
    // Helpers
    // =================================================================

    /**
     * @param list<FxRate> $rates
     */
    private function makeService(array $rates): CurrencyConversionService
    {
        $repo = $this->createMock(FxRateRepository::class);
        $repo->method('findAllRates')->willReturn($rates);

        $em = $this->createMock(EntityManagerInterface::class);
        $em->method('getRepository')->with(FxRate::class)->willReturn($repo);

        return new CurrencyConversionService($em, $this->logger);
    }

    private function makeRate(string $base, string $target, string $rate, int $updatedAgoHours = 1): FxRate
    {
        $r = new FxRate($base, $target, $rate);
        // Backdate updated_at to simulate staleness scenarios
        $when = (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))
            ->modify("-{$updatedAgoHours} hours");
        $ref = new \ReflectionProperty(FxRate::class, 'updatedAt');
        $ref->setAccessible(true);
        $ref->setValue($r, $when);
        return $r;
    }
}
