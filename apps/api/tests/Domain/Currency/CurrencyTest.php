<?php

declare(strict_types=1);

namespace Bayti\Api\Tests\Domain\Currency;

use Bayti\Api\Domain\Currency\Currency;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for Currency enum (M3.2.X.15-B).
 *
 * Tests the Q-FallbackBehavior = B semantics: unknown/empty/null
 * input returns AED rather than throwing. Critical because the
 * middleware boundary (X.15-D) passes raw query-param input
 * straight through this method.
 */
#[CoversClass(Currency::class)]
final class CurrencyTest extends TestCase
{
    #[Test]
    public function fromQueryParamRecognisesAllFiveCodes(): void
    {
        self::assertSame(Currency::AED, Currency::fromQueryParamOrAed('AED'));
        self::assertSame(Currency::USD, Currency::fromQueryParamOrAed('USD'));
        self::assertSame(Currency::EUR, Currency::fromQueryParamOrAed('EUR'));
        self::assertSame(Currency::SAR, Currency::fromQueryParamOrAed('SAR'));
        self::assertSame(Currency::GBP, Currency::fromQueryParamOrAed('GBP'));
    }

    #[Test]
    public function fromQueryParamIsCaseInsensitive(): void
    {
        // Stale clients sometimes lowercase; web crawlers sometimes
        // weirdly title-case. Normalize to uppercase before lookup.
        self::assertSame(Currency::USD, Currency::fromQueryParamOrAed('usd'));
        self::assertSame(Currency::EUR, Currency::fromQueryParamOrAed('Eur'));
        self::assertSame(Currency::GBP, Currency::fromQueryParamOrAed('gbp'));
    }

    #[Test]
    public function fromQueryParamFallsBackToAedOnUnknown(): void
    {
        // Q-FallbackBehavior = B locked: unknown currencies degrade
        // to AED, not 422.
        self::assertSame(Currency::AED, Currency::fromQueryParamOrAed('BHD'));
        self::assertSame(Currency::AED, Currency::fromQueryParamOrAed('JPY'));
        self::assertSame(Currency::AED, Currency::fromQueryParamOrAed('XXX'));
    }

    #[Test]
    public function fromQueryParamFallsBackToAedOnNullOrEmpty(): void
    {
        self::assertSame(Currency::AED, Currency::fromQueryParamOrAed(null));
        self::assertSame(Currency::AED, Currency::fromQueryParamOrAed(''));
    }

    #[Test]
    public function fromQueryParamFallsBackToAedOnNonString(): void
    {
        self::assertSame(Currency::AED, Currency::fromQueryParamOrAed(42));
        self::assertSame(Currency::AED, Currency::fromQueryParamOrAed(['USD']));
        self::assertSame(Currency::AED, Currency::fromQueryParamOrAed(true));
    }

    #[Test]
    public function baseReturnsAed(): void
    {
        self::assertSame(Currency::AED, Currency::base());
    }

    #[Test]
    public function supportedCodesListsFiveCurrencies(): void
    {
        $codes = Currency::supportedCodes();
        self::assertCount(5, $codes);
        self::assertContains('AED', $codes);
        self::assertContains('USD', $codes);
        self::assertContains('EUR', $codes);
        self::assertContains('SAR', $codes);
        self::assertContains('GBP', $codes);
    }
}
