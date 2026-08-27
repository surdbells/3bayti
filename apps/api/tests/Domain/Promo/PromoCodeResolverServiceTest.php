<?php

declare(strict_types=1);

namespace Bayti\Api\Tests\Domain\Promo;

use Bayti\Api\Domain\Cart\Cart;
use Bayti\Api\Domain\Order\Order;
use Bayti\Api\Domain\Promo\Exception\PromoNotApplicableException;
use Bayti\Api\Domain\Promo\PromoCode;
use Bayti\Api\Domain\Promo\PromoCodeRepository;
use Bayti\Api\Domain\Promo\PromoCodeResolverService;
use Bayti\Api\Domain\Promo\PromoRedemption;
use Bayti\Api\Domain\Promo\PromoRedemptionRepository;
use Bayti\Api\Domain\Promo\PromoResolution;
use Bayti\Api\Domain\User\User;
use Bayti\Api\Http\Errors\ErrorCodes;
use DateTimeImmutable;
use DateTimeZone;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for PromoCodeResolverService (M3.2.X.8-B).
 *
 * Strategy: hand-rolled in-memory repository fakes injected via the
 * direct-injection constructor parameters. No EM, no DB. This lets
 * the test exercise every validation-chain branch + the computation
 * logic without Doctrine plumbing — and matches locked pattern #4
 * (test mocks: anonymous repos with by-reference sink arrays for
 * the redemption persistence path).
 *
 * Coverage per the 10-rule chain:
 *   Rule 1  — empty/whitespace code            → PROMO_NOT_FOUND
 *   Rule 2  — code not in catalog              → PROMO_NOT_FOUND
 *   Rule 3  — is_active = false                → PROMO_INACTIVE
 *   Rule 4  — valid_from in the future         → PROMO_NOT_YET_VALID
 *   Rule 5  — valid_until in the past          → PROMO_EXPIRED
 *   Rule 6  — currency mismatch                → PROMO_CURRENCY_MISMATCH
 *   Rule 7  — cart subtotal < min_subtotal     → PROMO_MIN_SUBTOTAL_NOT_MET
 *   Rule 8  — global redemption cap hit        → PROMO_GLOBAL_LIMIT_REACHED
 *   Rule 9  — per-user redemption cap hit      → PROMO_USER_LIMIT_REACHED
 *   Rule 10 — percentage compute + max cap     → happy path
 *   Rule 10 — fixed compute + subtotal clamp   → happy path
 *
 * Plus the recordRedemption contract and the case-insensitive lookup
 * + whitespace-trimming behavior at the entrance.
 */
#[CoversClass(PromoCodeResolverService::class)]
#[CoversClass(PromoResolution::class)]
#[CoversClass(PromoNotApplicableException::class)]
final class PromoCodeResolverServiceTest extends TestCase
{
    // -------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------

    private function user(int $id = 42): User
    {
        $user = new User('customer@example.com', '+971501234567', 'fake-hash', 'AE');
        $this->setEntityId($user, $id);
        return $user;
    }

    /**
     * Build a Cart stand-in that exposes only the two accessors the
     * resolver calls (getCurrency, computeSubtotal). Avoids needing to
     * construct Products / CartItems for unit-level assertions.
     */
    private function cart(string $subtotal, string $currency = 'AED'): Cart
    {
        return new class ($subtotal, $currency) extends Cart {
            public function __construct(
                private readonly string $subtotalString,
                private readonly string $currencyString,
            ) {
                // Skip parent constructor; this is a test stand-in.
            }

            public function getCurrency(): string
            {
                return $this->currencyString;
            }

            public function computeSubtotal(): string
            {
                return $this->subtotalString;
            }
        };
    }

    /**
     * Cart stand-in for vendor-scope tests: overrides the whole-cart subtotal
     * AND the per-vendor subtotal (keyed by v3 vendor id). Vendor ids absent
     * from the map resolve to '0.00' (no items from that vendor in the cart).
     *
     * @param array<int, string> $vendorSubtotals
     */
    private function cartWithVendor(string $subtotal, array $vendorSubtotals, string $currency = 'AED'): Cart
    {
        return new class ($subtotal, $vendorSubtotals, $currency) extends Cart {
            /** @param array<int, string> $vendorSubtotals */
            public function __construct(
                private readonly string $subtotalString,
                private readonly array $vendorSubtotals,
                private readonly string $currencyString,
            ) {
                // Skip parent constructor; test stand-in.
            }

            public function getCurrency(): string
            {
                return $this->currencyString;
            }

            public function computeSubtotal(): string
            {
                return $this->subtotalString;
            }

            public function computeSubtotalForVendor(int $vendorId): string
            {
                return $this->vendorSubtotals[$vendorId] ?? '0.00';
            }
        };
    }

    /**
     * Build a PromoCode with an id pre-set so the resolver's
     * limit-counting branch can use it as a repository key.
     */
    private function promo(
        string $code = 'WELCOME10',
        string $type = PromoCode::DISCOUNT_TYPE_PERCENTAGE,
        string $value = '10.00',
        int $id = 7,
    ): PromoCode {
        $promo = new PromoCode($code, $type, $value);
        $this->setEntityId($promo, $id);
        return $promo;
    }

    /**
     * In-memory PromoCodeRepository that returns a single fixed promo
     * when its code matches the lookup. Anything else returns null.
     */
    private function repoForPromo(?PromoCode $promo): PromoCodeRepository
    {
        return new class ($promo) extends PromoCodeRepository {
            public function __construct(private readonly ?PromoCode $promo)
            {
                // Skip parent constructor (EntityManager argument).
            }

            public function findByNormalizedCode(string $rawCode): ?PromoCode
            {
                if ($this->promo === null) {
                    return null;
                }
                return $this->promo->getCode() === PromoCode::normalizeCode($rawCode)
                    ? $this->promo
                    : null;
            }
        };
    }

    /**
     * In-memory PromoRedemptionRepository with configurable counts +
     * a sink array capturing what got persisted.
     *
     * @param list<PromoRedemption> $sink Reference to capture persisted rows
     */
    private function redemptionRepo(
        int $globalCount = 0,
        int $userCount = 0,
        ?array &$sink = null,
    ): PromoRedemptionRepository {
        $sinkRef = &$sink;
        return new class ($globalCount, $userCount, $sinkRef) extends PromoRedemptionRepository {
            /** @var list<PromoRedemption> */
            private array $sinkLocal;

            public function __construct(
                private readonly int $globalCount,
                private readonly int $userCount,
                ?array &$sinkRef = null,
            ) {
                // Skip parent constructor.
                if ($sinkRef === null) {
                    $this->sinkLocal = [];
                } else {
                    $this->sinkLocal = &$sinkRef;
                }
            }

            public function countByPromoCodeIdEffective(int $promoCodeId): int
            {
                return $this->globalCount;
            }

            public function countByUserAndPromoCodeEffective(int $userId, int $promoCodeId): int
            {
                return $this->userCount;
            }

            public function persist(PromoRedemption $redemption): void
            {
                $this->sinkLocal[] = $redemption;
            }
        };
    }

    private function setEntityId(object $entity, int $id): void
    {
        $ref = new \ReflectionProperty($entity::class, 'id');
        $ref->setAccessible(true);
        $ref->setValue($entity, $id);
    }

    private function newResolver(
        ?PromoCodeRepository $promoRepo = null,
        ?PromoRedemptionRepository $redemptionRepo = null,
    ): PromoCodeResolverService {
        return new PromoCodeResolverService(
            em: null,
            promoCodeRepository: $promoRepo,
            promoRedemptionRepository: $redemptionRepo ?? $this->redemptionRepo(),
        );
    }

    // -------------------------------------------------------------------
    // Rule 1 — empty / whitespace
    // -------------------------------------------------------------------

    #[Test]
    public function emptyCodeThrowsNotFound(): void
    {
        $resolver = $this->newResolver($this->repoForPromo(null));

        try {
            $resolver->resolveForCart($this->cart('100.00'), $this->user(), '   ');
            self::fail('Expected PromoNotApplicableException');
        } catch (PromoNotApplicableException $e) {
            self::assertSame(ErrorCodes::PROMO_NOT_FOUND, $e->errorCode);
        }
    }

    // -------------------------------------------------------------------
    // Rule 2 — catalog miss
    // -------------------------------------------------------------------

    #[Test]
    public function unknownCodeThrowsNotFound(): void
    {
        $resolver = $this->newResolver($this->repoForPromo(null));

        $this->expectException(PromoNotApplicableException::class);
        $resolver->resolveForCart($this->cart('100.00'), $this->user(), 'NONEXISTENT');
    }

    #[Test]
    public function caseInsensitiveAndWhitespaceTrimsBeforeLookup(): void
    {
        $promo = $this->promo('WELCOME10');
        $resolver = $this->newResolver($this->repoForPromo($promo));

        $resolution = $resolver->resolveForCart($this->cart('100.00'), $this->user(), '  welcome10  ');

        self::assertSame($promo, $resolution->promoCode);
    }

    // -------------------------------------------------------------------
    // Rule 3 — inactive
    // -------------------------------------------------------------------

    #[Test]
    public function inactiveCodeThrowsInactive(): void
    {
        $promo = $this->promo();
        $promo->setActive(false);
        $resolver = $this->newResolver($this->repoForPromo($promo));

        try {
            $resolver->resolveForCart($this->cart('100.00'), $this->user(), 'WELCOME10');
            self::fail('Expected PromoNotApplicableException');
        } catch (PromoNotApplicableException $e) {
            self::assertSame(ErrorCodes::PROMO_INACTIVE, $e->errorCode);
        }
    }

    // -------------------------------------------------------------------
    // Rules 4 + 5 — time-window
    // -------------------------------------------------------------------

    #[Test]
    public function notYetValidCodeThrowsNotYetValidWithValidFromDetail(): void
    {
        $promo = $this->promo();
        $validFrom = new DateTimeImmutable('2030-01-01T00:00:00Z', new DateTimeZone('UTC'));
        $promo->setValidFrom($validFrom);
        $resolver = $this->newResolver($this->repoForPromo($promo));

        try {
            $resolver->resolveForCart($this->cart('100.00'), $this->user(), 'WELCOME10');
            self::fail('Expected PromoNotApplicableException');
        } catch (PromoNotApplicableException $e) {
            self::assertSame(ErrorCodes::PROMO_NOT_YET_VALID, $e->errorCode);
            self::assertArrayHasKey('valid_from', $e->details);
        }
    }

    #[Test]
    public function expiredCodeThrowsExpiredWithValidUntilDetail(): void
    {
        $promo = $this->promo();
        $validUntil = new DateTimeImmutable('2020-01-01T00:00:00Z', new DateTimeZone('UTC'));
        $promo->setValidUntil($validUntil);
        $resolver = $this->newResolver($this->repoForPromo($promo));

        try {
            $resolver->resolveForCart($this->cart('100.00'), $this->user(), 'WELCOME10');
            self::fail('Expected PromoNotApplicableException');
        } catch (PromoNotApplicableException $e) {
            self::assertSame(ErrorCodes::PROMO_EXPIRED, $e->errorCode);
            self::assertArrayHasKey('valid_until', $e->details);
        }
    }

    // -------------------------------------------------------------------
    // Rule 6 — currency mismatch
    // -------------------------------------------------------------------

    #[Test]
    public function currencyMismatchThrowsCurrencyMismatch(): void
    {
        $promo = $this->promo();
        $promo->setCurrency('USD');
        $resolver = $this->newResolver($this->repoForPromo($promo));

        try {
            $resolver->resolveForCart($this->cart('100.00', 'AED'), $this->user(), 'WELCOME10');
            self::fail('Expected PromoNotApplicableException');
        } catch (PromoNotApplicableException $e) {
            self::assertSame(ErrorCodes::PROMO_CURRENCY_MISMATCH, $e->errorCode);
            self::assertSame('USD', $e->details['promo_currency']);
            self::assertSame('AED', $e->details['cart_currency']);
        }
    }

    // -------------------------------------------------------------------
    // Rule 7 — min subtotal
    // -------------------------------------------------------------------

    #[Test]
    public function cartBelowMinSubtotalThrowsMinNotMetWithDetail(): void
    {
        $promo = $this->promo();
        $promo->setMinSubtotal('200.00');
        $resolver = $this->newResolver($this->repoForPromo($promo));

        try {
            $resolver->resolveForCart($this->cart('150.00'), $this->user(), 'WELCOME10');
            self::fail('Expected PromoNotApplicableException');
        } catch (PromoNotApplicableException $e) {
            self::assertSame(ErrorCodes::PROMO_MIN_SUBTOTAL_NOT_MET, $e->errorCode);
            self::assertSame('200.00', $e->details['min_subtotal']);
            self::assertSame('AED', $e->details['currency']);
        }
    }

    #[Test]
    public function cartAtExactlyMinSubtotalIsAccepted(): void
    {
        $promo = $this->promo();
        $promo->setMinSubtotal('100.00');
        $resolver = $this->newResolver($this->repoForPromo($promo));

        $resolution = $resolver->resolveForCart($this->cart('100.00'), $this->user(), 'WELCOME10');

        self::assertSame('10.00', $resolution->discountAmount);
    }

    // -------------------------------------------------------------------
    // Rule 8 — global limit
    // -------------------------------------------------------------------

    #[Test]
    public function globalLimitReachedThrowsGlobalLimit(): void
    {
        $promo = $this->promo();
        $promo->setUsageLimitGlobal(5);
        $resolver = $this->newResolver(
            $this->repoForPromo($promo),
            $this->redemptionRepo(globalCount: 5),
        );

        try {
            $resolver->resolveForCart($this->cart('100.00'), $this->user(), 'WELCOME10');
            self::fail('Expected PromoNotApplicableException');
        } catch (PromoNotApplicableException $e) {
            self::assertSame(ErrorCodes::PROMO_GLOBAL_LIMIT_REACHED, $e->errorCode);
        }
    }

    #[Test]
    public function justUnderGlobalLimitIsAccepted(): void
    {
        $promo = $this->promo();
        $promo->setUsageLimitGlobal(5);
        $resolver = $this->newResolver(
            $this->repoForPromo($promo),
            $this->redemptionRepo(globalCount: 4),
        );

        $resolution = $resolver->resolveForCart($this->cart('100.00'), $this->user(), 'WELCOME10');

        self::assertSame('10.00', $resolution->discountAmount);
    }

    // -------------------------------------------------------------------
    // Rule 9 — per-user limit
    // -------------------------------------------------------------------

    #[Test]
    public function userLimitReachedThrowsUserLimit(): void
    {
        $promo = $this->promo();
        $promo->setUsageLimitPerUser(1);
        $resolver = $this->newResolver(
            $this->repoForPromo($promo),
            $this->redemptionRepo(globalCount: 0, userCount: 1),
        );

        try {
            $resolver->resolveForCart($this->cart('100.00'), $this->user(), 'WELCOME10');
            self::fail('Expected PromoNotApplicableException');
        } catch (PromoNotApplicableException $e) {
            self::assertSame(ErrorCodes::PROMO_USER_LIMIT_REACHED, $e->errorCode);
        }
    }

    // -------------------------------------------------------------------
    // Rule 10 — discount computation
    // -------------------------------------------------------------------

    #[Test]
    public function percentageDiscountComputesAtTwoDecimalPrecision(): void
    {
        $promo = $this->promo('SAVE10', PromoCode::DISCOUNT_TYPE_PERCENTAGE, '10.00');
        $resolver = $this->newResolver($this->repoForPromo($promo));

        $resolution = $resolver->resolveForCart($this->cart('123.45'), $this->user(), 'SAVE10');

        self::assertSame('12.34', $resolution->discountAmount);  // 123.45 * 10 / 100 = 12.345 → 12.34 (bcdiv truncates)
        self::assertSame('AED', $resolution->currency);
    }

    #[Test]
    public function percentageDiscountClampsAtMaxDiscountAmount(): void
    {
        $promo = $this->promo('SAVE10', PromoCode::DISCOUNT_TYPE_PERCENTAGE, '50.00');
        $promo->setMaxDiscountAmount('20.00');
        $resolver = $this->newResolver($this->repoForPromo($promo));

        // 50% of 100 = 50, but capped at 20.
        $resolution = $resolver->resolveForCart($this->cart('100.00'), $this->user(), 'SAVE10');

        self::assertSame('20.00', $resolution->discountAmount);
    }

    #[Test]
    public function percentageDiscountUnderMaxCapIsNotClamped(): void
    {
        $promo = $this->promo('SAVE10', PromoCode::DISCOUNT_TYPE_PERCENTAGE, '10.00');
        $promo->setMaxDiscountAmount('20.00');
        $resolver = $this->newResolver($this->repoForPromo($promo));

        // 10% of 100 = 10; cap is 20, no clamp.
        $resolution = $resolver->resolveForCart($this->cart('100.00'), $this->user(), 'SAVE10');

        self::assertSame('10.00', $resolution->discountAmount);
    }

    #[Test]
    public function fixedAmountDiscountReturnsConfiguredValue(): void
    {
        $promo = $this->promo('SAVE50', PromoCode::DISCOUNT_TYPE_FIXED_AMOUNT, '50.00');
        $resolver = $this->newResolver($this->repoForPromo($promo));

        $resolution = $resolver->resolveForCart($this->cart('100.00'), $this->user(), 'SAVE50');

        self::assertSame('50.00', $resolution->discountAmount);
    }

    #[Test]
    public function fixedAmountDiscountClampsAtCartSubtotal(): void
    {
        $promo = $this->promo('SAVE100', PromoCode::DISCOUNT_TYPE_FIXED_AMOUNT, '100.00');
        $resolver = $this->newResolver($this->repoForPromo($promo));

        // 100 AED off, but cart is only 40 — clamp to 40 so the
        // recorded discount_amount is meaningful (40, not 100).
        $resolution = $resolver->resolveForCart($this->cart('40.00'), $this->user(), 'SAVE100');

        self::assertSame('40.00', $resolution->discountAmount);
    }

    // -------------------------------------------------------------------
    // Vendor scope — vendor-owned coupons discount ONLY that vendor's items
    // -------------------------------------------------------------------

    #[Test]
    public function vendorCouponPercentageDiscountsOnlyThatVendorsSubtotal(): void
    {
        // 10% code owned by vendor 9. The whole cart is 300 but only 100 of
        // it is vendor 9's — the discount is 10% of 100, not of 300.
        $promo = $this->promo('STORE10', PromoCode::DISCOUNT_TYPE_PERCENTAGE, '10.00');
        $promo->setVendorId(9);
        $resolver = $this->newResolver($this->repoForPromo($promo));

        $resolution = $resolver->resolveForCart(
            $this->cartWithVendor('300.00', [9 => '100.00']),
            $this->user(),
            'STORE10',
        );

        self::assertSame('10.00', $resolution->discountAmount);
    }

    #[Test]
    public function vendorCouponFixedAmountClampsAtVendorSubtotalNotWholeCart(): void
    {
        // 80 off owned by vendor 9; vendor 9's items total only 50, so the
        // discount clamps to 50 even though the whole cart is 300.
        $promo = $this->promo('STORE80', PromoCode::DISCOUNT_TYPE_FIXED_AMOUNT, '80.00');
        $promo->setVendorId(9);
        $resolver = $this->newResolver($this->repoForPromo($promo));

        $resolution = $resolver->resolveForCart(
            $this->cartWithVendor('300.00', [9 => '50.00']),
            $this->user(),
            'STORE80',
        );

        self::assertSame('50.00', $resolution->discountAmount);
    }

    #[Test]
    public function vendorCouponWithNoMatchingItemsThrowsNotApplicable(): void
    {
        // 300 of goods in the cart but none from vendor 9 → reject instead of
        // silently applying a 0.00 discount.
        $promo = $this->promo('STORE10', PromoCode::DISCOUNT_TYPE_PERCENTAGE, '10.00');
        $promo->setVendorId(9);
        $resolver = $this->newResolver($this->repoForPromo($promo));

        try {
            $resolver->resolveForCart(
                $this->cartWithVendor('300.00', []),
                $this->user(),
                'STORE10',
            );
            self::fail('Expected PromoNotApplicableException');
        } catch (PromoNotApplicableException $e) {
            self::assertSame(ErrorCodes::PROMO_NOT_APPLICABLE_TO_CART, $e->errorCode);
        }
    }

    #[Test]
    public function vendorCouponMinSubtotalChecksVendorSubtotalNotWholeCart(): void
    {
        // min 200. The whole cart (500) clears it, but vendor 9's slice is
        // only 150 — the gate uses the vendor slice, so it fails.
        $promo = $this->promo('STORE10', PromoCode::DISCOUNT_TYPE_PERCENTAGE, '10.00');
        $promo->setVendorId(9);
        $promo->setMinSubtotal('200.00');
        $resolver = $this->newResolver($this->repoForPromo($promo));

        try {
            $resolver->resolveForCart(
                $this->cartWithVendor('500.00', [9 => '150.00']),
                $this->user(),
                'STORE10',
            );
            self::fail('Expected PromoNotApplicableException');
        } catch (PromoNotApplicableException $e) {
            self::assertSame(ErrorCodes::PROMO_MIN_SUBTOTAL_NOT_MET, $e->errorCode);
        }
    }

    #[Test]
    public function platformWideCouponStillDiscountsWholeCart(): void
    {
        // vendor_id null → whole-cart behaviour unchanged: 10% of 300 = 30.
        $promo = $this->promo('SITE10', PromoCode::DISCOUNT_TYPE_PERCENTAGE, '10.00');
        self::assertNull($promo->getVendorId());
        $resolver = $this->newResolver($this->repoForPromo($promo));

        $resolution = $resolver->resolveForCart(
            $this->cartWithVendor('300.00', [9 => '100.00']),
            $this->user(),
            'SITE10',
        );

        self::assertSame('30.00', $resolution->discountAmount);
    }

    // -------------------------------------------------------------------
    // Defaults — no limits, no time window, no min subtotal
    // -------------------------------------------------------------------

    #[Test]
    public function happyPathWithNoLimitsAndNoTimeWindow(): void
    {
        $promo = $this->promo();
        $resolver = $this->newResolver($this->repoForPromo($promo));

        $resolution = $resolver->resolveForCart($this->cart('200.00'), $this->user(), 'WELCOME10');

        self::assertInstanceOf(PromoResolution::class, $resolution);
        self::assertSame($promo, $resolution->promoCode);
        self::assertSame('20.00', $resolution->discountAmount);
    }

    #[Test]
    public function nullValidFromAndValidUntilDoNotRejectAnyTimestamp(): void
    {
        $promo = $this->promo();
        // Both bounds are null by default; verify the resolver accepts.
        self::assertNull($promo->getValidFrom());
        self::assertNull($promo->getValidUntil());

        $resolver = $this->newResolver($this->repoForPromo($promo));
        $resolution = $resolver->resolveForCart($this->cart('100.00'), $this->user(), 'WELCOME10');

        self::assertSame('10.00', $resolution->discountAmount);
    }

    // -------------------------------------------------------------------
    // recordRedemption
    // -------------------------------------------------------------------

    #[Test]
    public function recordRedemptionPersistsRowViaRepository(): void
    {
        $sink = [];
        $promo = $this->promo();
        $resolver = $this->newResolver(
            $this->repoForPromo($promo),
            $this->redemptionRepo(sink: $sink),
        );

        $user = $this->user();
        $order = new Order(user: $user, orderReference: 'V3-X8-A', subtotal: '100.00');

        $redemption = $resolver->recordRedemption($promo, $user, $order, '10.00');

        self::assertCount(1, $sink, 'redemption should be persisted to repository');
        self::assertSame($redemption, $sink[0]);
        self::assertSame($promo, $redemption->getPromoCode());
        self::assertSame($user, $redemption->getUser());
        self::assertSame($order, $redemption->getOrder());
        self::assertSame('10.00', $redemption->getDiscountAmount());
    }

    #[Test]
    public function recordRedemptionDoesNotThrowWhenNoRepositoryConfigured(): void
    {
        // Constructed with neither EM nor injected repos — used in test
        // contexts where the caller verifies the returned VO only.
        $resolver = new PromoCodeResolverService();

        $promo = $this->promo();
        $user = $this->user();
        $order = new Order(user: $user, orderReference: 'V3-X8-B', subtotal: '100.00');

        $redemption = $resolver->recordRedemption($promo, $user, $order, '10.00');

        self::assertInstanceOf(PromoRedemption::class, $redemption);
    }

    // -------------------------------------------------------------------
    // Rule ordering — first failure wins
    // -------------------------------------------------------------------

    #[Test]
    public function inactiveCheckPrecedesTimeWindowCheck(): void
    {
        // A code that is BOTH inactive AND expired should surface
        // PROMO_INACTIVE (rule 3 fires before rule 5).
        $promo = $this->promo();
        $promo->setActive(false);
        $promo->setValidUntil(new DateTimeImmutable('2020-01-01T00:00:00Z', new DateTimeZone('UTC')));
        $resolver = $this->newResolver($this->repoForPromo($promo));

        try {
            $resolver->resolveForCart($this->cart('100.00'), $this->user(), 'WELCOME10');
            self::fail('Expected PromoNotApplicableException');
        } catch (PromoNotApplicableException $e) {
            self::assertSame(ErrorCodes::PROMO_INACTIVE, $e->errorCode);
        }
    }

    #[Test]
    public function minSubtotalCheckPrecedesLimitCheck(): void
    {
        // A code below min_subtotal AND over global limit should
        // surface PROMO_MIN_SUBTOTAL_NOT_MET (rule 7 fires before
        // rule 8). This keeps error messages aligned with the
        // earliest fix the customer can take.
        $promo = $this->promo();
        $promo->setMinSubtotal('200.00');
        $promo->setUsageLimitGlobal(5);
        $resolver = $this->newResolver(
            $this->repoForPromo($promo),
            $this->redemptionRepo(globalCount: 5),
        );

        try {
            $resolver->resolveForCart($this->cart('100.00'), $this->user(), 'WELCOME10');
            self::fail('Expected PromoNotApplicableException');
        } catch (PromoNotApplicableException $e) {
            self::assertSame(ErrorCodes::PROMO_MIN_SUBTOTAL_NOT_MET, $e->errorCode);
        }
    }

    // -------------------------------------------------------------------
    // Exception → HttpException mapping contract
    // -------------------------------------------------------------------

    #[Test]
    public function exceptionMapsToHttp422WithStructuredErrorCode(): void
    {
        $resolver = $this->newResolver($this->repoForPromo(null));

        try {
            $resolver->resolveForCart($this->cart('100.00'), $this->user(), 'BOGUS');
            self::fail('Expected PromoNotApplicableException');
        } catch (PromoNotApplicableException $e) {
            $http = $e->toHttpException();
            self::assertSame(422, $http->status);
            self::assertSame(ErrorCodes::PROMO_NOT_FOUND, $http->errorCode);
        }
    }
}
