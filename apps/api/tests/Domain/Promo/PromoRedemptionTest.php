<?php

declare(strict_types=1);

namespace Bayti\Api\Tests\Domain\Promo;

use Bayti\Api\Domain\Order\Order;
use Bayti\Api\Domain\Promo\PromoCode;
use Bayti\Api\Domain\Promo\PromoRedemption;
use Bayti\Api\Domain\User\User;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Entity behaviour tests for PromoRedemption (M3.2.X.8-A).
 *
 * No database, these exercise the constructor's snapshot capture +
 * immutability contract. Snapshot fields must reflect the catalog
 * state AT REDEMPTION TIME, never live-rebound to the underlying
 * PromoCode (which admin may later edit).
 */
#[CoversClass(PromoRedemption::class)]
final class PromoRedemptionTest extends TestCase
{
    private function newUser(string $email = 'customer@example.com'): User
    {
        return new User($email, '+971501234567', 'fake-bcrypt-hash', 'AE');
    }

    private function newOrder(?User $user = null): Order
    {
        $user = $user ?? $this->newUser();
        return new Order(
            user: $user,
            orderReference: 'V3-TEST-PROMO',
            subtotal: '100.00',
        );
    }

    private function newPromoCode(
        string $code = 'WELCOME10',
        string $type = PromoCode::DISCOUNT_TYPE_PERCENTAGE,
        string $value = '10.00',
    ): PromoCode {
        return new PromoCode($code, $type, $value);
    }

    // -------------------------------------------------------------------
    // Construction + snapshot capture
    // -------------------------------------------------------------------

    #[Test]
    public function constructorSnapshotsCatalogStateAtRedemptionTime(): void
    {
        $user = $this->newUser();
        $order = $this->newOrder($user);
        $promo = $this->newPromoCode();

        $redemption = new PromoRedemption($promo, $user, $order, '10.00');

        self::assertSame($promo, $redemption->getPromoCode());
        self::assertSame($user, $redemption->getUser());
        self::assertSame($order, $redemption->getOrder());
        self::assertSame('10.00', $redemption->getDiscountAmount());
        self::assertSame('WELCOME10', $redemption->getCodeSnapshot());
        self::assertSame(PromoCode::DISCOUNT_TYPE_PERCENTAGE, $redemption->getDiscountTypeSnapshot());
        self::assertSame('10.00', $redemption->getDiscountValueSnapshot());
        self::assertNotNull($redemption->getRedeemedAt());
    }

    #[Test]
    public function snapshotIsImmuneToLaterCatalogEdits(): void
    {
        $user = $this->newUser();
        $order = $this->newOrder($user);
        $promo = $this->newPromoCode('WELCOME10', PromoCode::DISCOUNT_TYPE_PERCENTAGE, '10.00');

        $redemption = new PromoRedemption($promo, $user, $order, '10.00');

        // Admin renames the code and bumps the value 3 months later.
        $promo->setCode('LEGACY_WELCOME10');
        $promo->setDiscountValue('15.00');

        // The redemption row's snapshot fields must be unchanged.
        self::assertSame('WELCOME10', $redemption->getCodeSnapshot());
        self::assertSame('10.00', $redemption->getDiscountValueSnapshot());

        // The live PromoCode reference HAS the new values (this is the
        // normal Doctrine behavior; we're verifying it explicitly so
        // the contract is clear).
        self::assertSame('LEGACY_WELCOME10', $redemption->getPromoCode()->getCode());
        self::assertSame('15.00', $redemption->getPromoCode()->getDiscountValue());
    }

    #[Test]
    public function constructorRejectsMalformedDiscountAmount(): void
    {
        $user = $this->newUser();
        $order = $this->newOrder($user);
        $promo = $this->newPromoCode();

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('non-negative DECIMAL(10,2)');

        new PromoRedemption($promo, $user, $order, 'bogus');
    }

    #[Test]
    public function constructorAcceptsZeroDiscountAmount(): void
    {
        // Edge: a 0.00 discount is semantically a no-op but technically
        // valid (a clamped fixed_amount that hit the cart-subtotal floor
        // when subtotal was zero, degenerate cart-only path).
        $user = $this->newUser();
        $order = $this->newOrder($user);
        $promo = $this->newPromoCode();

        $redemption = new PromoRedemption($promo, $user, $order, '0.00');

        self::assertSame('0.00', $redemption->getDiscountAmount());
    }

    #[Test]
    public function constructorCapturesFixedAmountTypeInSnapshot(): void
    {
        $user = $this->newUser();
        $order = $this->newOrder($user);
        $promo = $this->newPromoCode(
            'SUMMER',
            PromoCode::DISCOUNT_TYPE_FIXED_AMOUNT,
            '50.00',
        );

        $redemption = new PromoRedemption($promo, $user, $order, '50.00');

        self::assertSame(PromoCode::DISCOUNT_TYPE_FIXED_AMOUNT, $redemption->getDiscountTypeSnapshot());
        self::assertSame('50.00', $redemption->getDiscountValueSnapshot());
    }

    #[Test]
    public function redeemedAtIsSetAtConstruction(): void
    {
        $user = $this->newUser();
        $order = $this->newOrder($user);
        $promo = $this->newPromoCode();

        $before = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
        $redemption = new PromoRedemption($promo, $user, $order, '10.00');
        $after = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));

        self::assertGreaterThanOrEqual($before, $redemption->getRedeemedAt());
        self::assertLessThanOrEqual($after, $redemption->getRedeemedAt());
    }
}
