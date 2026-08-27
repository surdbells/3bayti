<?php

declare(strict_types=1);

namespace Bayti\Api\Tests\Domain\Promo;

use Bayti\Api\Domain\Promo\PromoCode;
use DateTimeImmutable;
use DateTimeZone;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Entity behaviour tests for PromoCode (M3.2.X.8-A).
 *
 * No database, these exercise pure PHP logic: constructor validation,
 * code normalization, setter guards, time-window predicate.
 *
 * Schema-level constraints (CHECK, UNIQUE on UPPER(code), FK behavior)
 * are exercised via the controller integration tests in sub-phases
 * -C, -D, -E.
 */
#[CoversClass(PromoCode::class)]
final class PromoCodeTest extends TestCase
{
    // -------------------------------------------------------------------
    // Construction
    // -------------------------------------------------------------------

    #[Test]
    public function constructorNormalizesCodeToUpperAndTrims(): void
    {
        $code = new PromoCode('  welcome10  ', PromoCode::DISCOUNT_TYPE_PERCENTAGE, '10.00');

        self::assertSame('WELCOME10', $code->getCode());
    }

    #[Test]
    public function constructorRejectsEmptyCode(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('must not be empty');

        new PromoCode('   ', PromoCode::DISCOUNT_TYPE_PERCENTAGE, '10.00');
    }

    #[Test]
    public function constructorRejectsCodeExceedingMaxLength(): void
    {
        $tooLong = str_repeat('A', PromoCode::CODE_MAX_LENGTH + 1);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('exceeds maximum length');

        new PromoCode($tooLong, PromoCode::DISCOUNT_TYPE_FIXED_AMOUNT, '50.00');
    }

    #[Test]
    public function constructorRejectsUnknownDiscountType(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage("Unknown discount type 'bogus'");

        new PromoCode('WELCOME', 'bogus', '10.00');
    }

    #[Test]
    public function constructorRejectsMalformedDiscountValue(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('non-negative DECIMAL(10,2)');

        new PromoCode('WELCOME', PromoCode::DISCOUNT_TYPE_FIXED_AMOUNT, 'abc');
    }

    #[Test]
    public function constructorRejectsZeroPercentageDiscount(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('must be > 0');

        new PromoCode('WELCOME', PromoCode::DISCOUNT_TYPE_PERCENTAGE, '0.00');
    }

    #[Test]
    public function constructorRejectsOver100PercentageDiscount(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('must be ≤ 100');

        new PromoCode('WELCOME', PromoCode::DISCOUNT_TYPE_PERCENTAGE, '100.01');
    }

    #[Test]
    public function constructorAcceptsFixedAmountWithValueOverHundred(): void
    {
        // Fixed-amount discounts are AED amounts; 500 AED off is a
        // legitimate "big-spend reward" code shape.
        $code = new PromoCode('BIGREWARD', PromoCode::DISCOUNT_TYPE_FIXED_AMOUNT, '500.00');

        self::assertSame('500.00', $code->getDiscountValue());
    }

    #[Test]
    public function constructorAcceptsZeroFixedAmountDiscount(): void
    {
        // Edge: a 0 AED discount is semantically a no-op but technically
        // valid as a placeholder admin sometimes creates pre-launch.
        // The percentage range check explicitly does NOT apply to
        // fixed_amount.
        $code = new PromoCode('PLACEHOLDER', PromoCode::DISCOUNT_TYPE_FIXED_AMOUNT, '0.00');

        self::assertSame('0.00', $code->getDiscountValue());
    }

    #[Test]
    public function constructorDefaultsCurrencyToAed(): void
    {
        $code = new PromoCode('WELCOME', PromoCode::DISCOUNT_TYPE_FIXED_AMOUNT, '50.00');

        self::assertSame('AED', $code->getCurrency());
    }

    #[Test]
    public function constructorDefaultsActiveToTrue(): void
    {
        $code = new PromoCode('WELCOME', PromoCode::DISCOUNT_TYPE_FIXED_AMOUNT, '50.00');

        self::assertTrue($code->isActive());
    }

    #[Test]
    public function constructorDefaultsOptionalFieldsToNull(): void
    {
        $code = new PromoCode('WELCOME', PromoCode::DISCOUNT_TYPE_FIXED_AMOUNT, '50.00');

        self::assertNull($code->getDescription());
        self::assertNull($code->getMinSubtotal());
        self::assertNull($code->getMaxDiscountAmount());
        self::assertNull($code->getUsageLimitGlobal());
        self::assertNull($code->getUsageLimitPerUser());
        self::assertNull($code->getValidFrom());
        self::assertNull($code->getValidUntil());
    }

    // -------------------------------------------------------------------
    // Normalization helper
    // -------------------------------------------------------------------

    #[Test]
    public function normalizeCodeIsCaseInsensitiveAndTrims(): void
    {
        self::assertSame('WELCOME10', PromoCode::normalizeCode('welcome10'));
        self::assertSame('WELCOME10', PromoCode::normalizeCode('  Welcome10  '));
        self::assertSame('WELCOME10', PromoCode::normalizeCode("\tWelcome10\n"));
        self::assertSame('', PromoCode::normalizeCode('   '));
    }

    // -------------------------------------------------------------------
    // Setters with validation
    // -------------------------------------------------------------------

    #[Test]
    public function setCodeRenormalizes(): void
    {
        $code = new PromoCode('WELCOME', PromoCode::DISCOUNT_TYPE_FIXED_AMOUNT, '50.00');
        $code->setCode('  SUMMER25  ');

        self::assertSame('SUMMER25', $code->getCode());
    }

    #[Test]
    public function setDiscountTypeSwitchingToPercentageValidatesExistingValue(): void
    {
        // A fixed_amount code with value 500 cannot become percentage
        // because 500% is out of range.
        $code = new PromoCode('BIGREWARD', PromoCode::DISCOUNT_TYPE_FIXED_AMOUNT, '500.00');

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('must be ≤ 100');

        $code->setDiscountType(PromoCode::DISCOUNT_TYPE_PERCENTAGE);
    }

    #[Test]
    public function setUsageLimitGlobalRejectsNegative(): void
    {
        $code = new PromoCode('WELCOME', PromoCode::DISCOUNT_TYPE_FIXED_AMOUNT, '50.00');

        $this->expectException(\InvalidArgumentException::class);

        $code->setUsageLimitGlobal(-1);
    }

    #[Test]
    public function setMinSubtotalRejectsMalformedMoney(): void
    {
        $code = new PromoCode('WELCOME', PromoCode::DISCOUNT_TYPE_FIXED_AMOUNT, '50.00');

        $this->expectException(\InvalidArgumentException::class);

        $code->setMinSubtotal('not-a-number');
    }

    #[Test]
    public function setMinSubtotalAcceptsNull(): void
    {
        $code = new PromoCode('WELCOME', PromoCode::DISCOUNT_TYPE_FIXED_AMOUNT, '50.00');
        $code->setMinSubtotal('100.00');
        $code->setMinSubtotal(null);

        self::assertNull($code->getMinSubtotal());
    }

    #[Test]
    public function setCurrencyUppercasesAndRejectsBadLength(): void
    {
        $code = new PromoCode('WELCOME', PromoCode::DISCOUNT_TYPE_FIXED_AMOUNT, '50.00');
        $code->setCurrency('usd');

        self::assertSame('USD', $code->getCurrency());

        $this->expectException(\InvalidArgumentException::class);
        $code->setCurrency('US');
    }

    // -------------------------------------------------------------------
    // Time-window predicate
    // -------------------------------------------------------------------

    #[Test]
    public function isCurrentlyTimeValidReturnsTrueWhenBothBoundsNull(): void
    {
        $code = new PromoCode('WELCOME', PromoCode::DISCOUNT_TYPE_FIXED_AMOUNT, '50.00');

        self::assertTrue($code->isCurrentlyTimeValid());
    }

    #[Test]
    public function isCurrentlyTimeValidRespectsLowerBound(): void
    {
        $code = new PromoCode('WELCOME', PromoCode::DISCOUNT_TYPE_FIXED_AMOUNT, '50.00');
        $code->setValidFrom(new DateTimeImmutable('2030-01-01T00:00:00Z', new DateTimeZone('UTC')));

        $now = new DateTimeImmutable('2026-05-18T00:00:00Z', new DateTimeZone('UTC'));
        self::assertFalse($code->isCurrentlyTimeValid($now), 'before valid_from should be invalid');

        $later = new DateTimeImmutable('2031-01-01T00:00:00Z', new DateTimeZone('UTC'));
        self::assertTrue($code->isCurrentlyTimeValid($later), 'after valid_from should be valid');
    }

    #[Test]
    public function isCurrentlyTimeValidRespectsUpperBound(): void
    {
        $code = new PromoCode('WELCOME', PromoCode::DISCOUNT_TYPE_FIXED_AMOUNT, '50.00');
        $code->setValidUntil(new DateTimeImmutable('2020-01-01T00:00:00Z', new DateTimeZone('UTC')));

        $now = new DateTimeImmutable('2026-05-18T00:00:00Z', new DateTimeZone('UTC'));
        self::assertFalse($code->isCurrentlyTimeValid($now), 'after valid_until should be invalid');

        $earlier = new DateTimeImmutable('2019-01-01T00:00:00Z', new DateTimeZone('UTC'));
        self::assertTrue($code->isCurrentlyTimeValid($earlier), 'before valid_until should be valid');
    }

    #[Test]
    public function isCurrentlyTimeValidWithinBracketedWindow(): void
    {
        $code = new PromoCode('WELCOME', PromoCode::DISCOUNT_TYPE_FIXED_AMOUNT, '50.00');
        $code->setValidFrom(new DateTimeImmutable('2026-05-01T00:00:00Z', new DateTimeZone('UTC')));
        $code->setValidUntil(new DateTimeImmutable('2026-05-31T23:59:59Z', new DateTimeZone('UTC')));

        $inside = new DateTimeImmutable('2026-05-15T12:00:00Z', new DateTimeZone('UTC'));
        $before = new DateTimeImmutable('2026-04-30T00:00:00Z', new DateTimeZone('UTC'));
        $after = new DateTimeImmutable('2026-06-01T00:00:00Z', new DateTimeZone('UTC'));

        self::assertTrue($code->isCurrentlyTimeValid($inside));
        self::assertFalse($code->isCurrentlyTimeValid($before));
        self::assertFalse($code->isCurrentlyTimeValid($after));
    }

    // -------------------------------------------------------------------
    // Active flag toggling
    // -------------------------------------------------------------------

    #[Test]
    public function setActiveTogglesFlag(): void
    {
        $code = new PromoCode('WELCOME', PromoCode::DISCOUNT_TYPE_FIXED_AMOUNT, '50.00');
        self::assertTrue($code->isActive());

        $code->setActive(false);
        self::assertFalse($code->isActive());

        $code->setActive(true);
        self::assertTrue($code->isActive());
    }
}
