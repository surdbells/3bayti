<?php

declare(strict_types=1);

namespace Bayti\Api\Tests\Http\Serializers;

use Bayti\Api\Domain\Order\Order;
use Bayti\Api\Domain\Promo\PromoCode;
use Bayti\Api\Domain\Promo\PromoRedemption;
use Bayti\Api\Domain\User\User;
use Bayti\Api\Http\Serializers\OrderSerializer;
use DateTimeImmutable;
use DateTimeZone;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Coverage for the M3.2.X.8-F applied_promo block in OrderSerializer.
 *
 * Verifies:
 *   - Order without a PromoRedemption serializes applied_promo: null
 *   - Order with a PromoRedemption surfaces the snapshot fields
 *     (code, discount_type, discount_value, discount_amount,
 *     redeemed_at), NOT the live PromoCode fields, so a later
 *     admin rename / re-price leaves historical orders untouched.
 *   - Both listShape and detailShape include applied_promo (since
 *     detailShape extends listShape).
 *
 * Direct-construction tests, no HTTP layer, no DI. Builds Order +
 * PromoCode + PromoRedemption via the entities' real constructors,
 * then injects ids via reflection where needed.
 */
#[CoversClass(OrderSerializer::class)]
final class OrderSerializerPromoTest extends TestCase
{
    #[Test]
    public function listShapeIncludesAppliedPromoNullWhenNoPromoApplied(): void
    {
        $order = $this->makeOrder('100.00', '0.00');
        $serializer = new OrderSerializer();
        $shape = $serializer->listShape($order);

        self::assertArrayHasKey('applied_promo', $shape);
        self::assertNull($shape['applied_promo']);
    }

    #[Test]
    public function listShapeSurfacesPromoRedemptionSnapshotFields(): void
    {
        $order = $this->makeOrder('100.00', '10.00');
        $promo = $this->makePromoCode('WELCOME10', PromoCode::DISCOUNT_TYPE_PERCENTAGE, '10.00');
        $user = $this->makeUser();
        $redemption = new PromoRedemption(
            promoCode: $promo,
            user: $user,
            order: $order,
            discountAmount: '10.00',
        );
        $this->setProp($redemption, 'redeemedAt', new DateTimeImmutable(
            '2026-05-18T10:00:00Z',
            new DateTimeZone('UTC'),
        ));
        $order->setPromoRedemption($redemption);

        $serializer = new OrderSerializer();
        $shape = $serializer->listShape($order);

        self::assertNotNull($shape['applied_promo']);
        self::assertSame('WELCOME10', $shape['applied_promo']['code']);
        self::assertSame('percentage', $shape['applied_promo']['discount_type']);
        self::assertSame('10.00', $shape['applied_promo']['discount_value']);
        self::assertSame('10.00', $shape['applied_promo']['discount_amount']);
        self::assertSame(
            '2026-05-18T10:00:00+00:00',
            $shape['applied_promo']['redeemed_at'],
        );
    }

    #[Test]
    public function detailShapeIncludesAppliedPromoFromListShapeInheritance(): void
    {
        $order = $this->makeOrder('200.00', '50.00');
        $promo = $this->makePromoCode('BIG50', PromoCode::DISCOUNT_TYPE_FIXED_AMOUNT, '50.00');
        $user = $this->makeUser();
        $redemption = new PromoRedemption(
            promoCode: $promo,
            user: $user,
            order: $order,
            discountAmount: '50.00',
        );
        $order->setPromoRedemption($redemption);

        $serializer = new OrderSerializer();
        $shape = $serializer->detailShape($order);

        self::assertArrayHasKey('applied_promo', $shape);
        self::assertSame('BIG50', $shape['applied_promo']['code']);
        self::assertSame('fixed_amount', $shape['applied_promo']['discount_type']);
        self::assertSame('50.00', $shape['applied_promo']['discount_amount']);
        // detailShape also still has the address blocks.
        self::assertArrayHasKey('billing_address', $shape);
        self::assertArrayHasKey('shipping_address', $shape);
    }

    #[Test]
    public function snapshotFieldsAreReadFromRedemptionNotLivePromoCode(): void
    {
        // This is the historical-correctness guarantee: even if the
        // PromoCode entity is renamed AFTER redemption, the order's
        // applied_promo block continues to show what the customer
        // actually used at checkout.
        $order = $this->makeOrder('100.00', '15.00');
        $promo = $this->makePromoCode('ORIGINAL', PromoCode::DISCOUNT_TYPE_PERCENTAGE, '15.00');
        $user = $this->makeUser();
        $redemption = new PromoRedemption(
            promoCode: $promo,
            user: $user,
            order: $order,
            discountAmount: '15.00',
        );
        $order->setPromoRedemption($redemption);

        // Admin renames the underlying PromoCode + changes its value
        // AFTER the order was placed. Reflects real-world ops flow.
        $promo->setCode('RENAMED');
        $promo->setDiscountValue('99.00');

        $serializer = new OrderSerializer();
        $shape = $serializer->listShape($order);

        self::assertNotNull($shape['applied_promo']);
        self::assertSame(
            'ORIGINAL',
            $shape['applied_promo']['code'],
            'serializer must read codeSnapshot, not live PromoCode->getCode()',
        );
        self::assertSame(
            '15.00',
            $shape['applied_promo']['discount_value'],
            'serializer must read discountValueSnapshot, not live PromoCode->getDiscountValue()',
        );
    }

    #[Test]
    public function shapeStaysBackwardCompatibleForOrderWithoutPromo(): void
    {
        // No PromoRedemption attached → applied_promo is null AND all
        // pre-existing shape fields are still present. This locks the
        // backwards-compat for the live mobile build until it ships
        // support for reading the new field.
        $order = $this->makeOrder('75.00', '0.00');

        $serializer = new OrderSerializer();
        $shape = $serializer->listShape($order);

        $expectedKeys = [
            'id', 'order_reference', 'status', 'date',
            'subtotal', 'delivery_fee', 'discount', 'total',
            'currency', 'paid_at', 'items', 'applied_promo',
        ];
        foreach ($expectedKeys as $key) {
            self::assertArrayHasKey($key, $shape, "Missing key: $key");
        }
        self::assertNull($shape['applied_promo']);
    }

    // -------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------

    private function makeOrder(string $subtotal, string $discount): Order
    {
        $user = $this->makeUser();
        $order = new Order(
            user: $user,
            orderReference: 'TEST-001',
            subtotal: $subtotal,
            deliveryFee: '0.00',
            discount: $discount,
        );
        $this->setProp($order, 'id', 42);
        return $order;
    }

    private function makePromoCode(string $code, string $type, string $value): PromoCode
    {
        $promo = new PromoCode($code, $type, $value);
        $this->setProp($promo, 'id', 7);
        return $promo;
    }

    private function makeUser(): User
    {
        $user = (new \ReflectionClass(User::class))->newInstanceWithoutConstructor();
        $this->setProp($user, 'id', 99);
        return $user;
    }

    private function setProp(object $entity, string $prop, mixed $value): void
    {
        $ref = new \ReflectionProperty($entity::class, $prop);
        $ref->setAccessible(true);
        $ref->setValue($entity, $value);
    }
}
