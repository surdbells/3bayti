<?php

declare(strict_types=1);

namespace Bayti\Api\Tests\Domain\Currency;

use Bayti\Api\Domain\Currency\FxRate;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for FxRate entity (M3.2.X.15-B).
 *
 * The rate validation is critical: bcmath silently coerces bad
 * input to 0, which would zero out a customer's displayed price.
 * The constructor + setRate validation prevents zero/negative
 * rates from ever being persisted.
 */
#[CoversClass(FxRate::class)]
final class FxRateTest extends TestCase
{
    #[Test]
    public function constructorNormalisesCodesToUppercase(): void
    {
        $rate = new FxRate('aed', 'usd', '0.27225000');
        self::assertSame('AED', $rate->getBaseCode());
        self::assertSame('USD', $rate->getTargetCode());
    }

    #[Test]
    public function constructorAcceptsValidDecimalString(): void
    {
        $rate = new FxRate('AED', 'USD', '0.27225000');
        self::assertSame('0.27225000', $rate->getRate());
    }

    #[Test]
    public function constructorAcceptsIntegerLikeString(): void
    {
        $rate = new FxRate('AED', 'AED', '1');
        self::assertSame('1', $rate->getRate());
    }

    #[Test]
    public function constructorRejectsNonNumericString(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new FxRate('AED', 'USD', 'abc');
    }

    #[Test]
    public function constructorRejectsNegativeRate(): void
    {
        // The regex already excludes minus signs, so the message
        // comes from the regex check, not the bccomp check.
        $this->expectException(\InvalidArgumentException::class);
        new FxRate('AED', 'USD', '-1.5');
    }

    #[Test]
    public function constructorRejectsZeroRate(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('must be positive');
        new FxRate('AED', 'USD', '0');
    }

    #[Test]
    public function constructorRejectsRateOverThousand(): void
    {
        // Guards against base/target inversion typos (e.g. typing
        // 367.0 for AED→USD instead of 0.272).
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('less than 1000');
        new FxRate('AED', 'USD', '5000');
    }

    #[Test]
    public function constructorRejectsExactlyThousand(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new FxRate('AED', 'USD', '1000');
    }

    #[Test]
    public function setRateUpdatesValue(): void
    {
        $rate = new FxRate('AED', 'USD', '0.27225000');
        $rate->setRate('0.28000000');
        self::assertSame('0.28000000', $rate->getRate());
    }

    #[Test]
    public function setRateAppliesSameValidation(): void
    {
        $rate = new FxRate('AED', 'USD', '0.27225000');
        $this->expectException(\InvalidArgumentException::class);
        $rate->setRate('0');
    }

    #[Test]
    public function touchUpdatedAtAdvancesTimestamp(): void
    {
        $rate = new FxRate('AED', 'USD', '0.27225000');
        $first = $rate->getUpdatedAt();
        // Sleep 1µs to guarantee a different timestamp
        usleep(1000);
        $rate->touchUpdatedAt();
        self::assertGreaterThanOrEqual($first, $rate->getUpdatedAt());
    }
}
