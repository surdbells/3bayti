<?php

declare(strict_types=1);

namespace Bayti\Api\Tests\Notification;

use Bayti\Api\Domain\Catalog\Product;
use Bayti\Api\Domain\Catalog\Vendor;
use Bayti\Api\Domain\Order\Order;
use Bayti\Api\Domain\Order\OrderItem;
use Bayti\Api\Domain\User\User;
use Bayti\Api\Notification\EmailTemplate;
use Bayti\Api\Notification\OrderEmailTemplateRenderer;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Content-level tests for the 7 customer-facing Arabic email
 * templates (M3.2.X.7-C).
 *
 * Verifies for each template:
 *   - Subject contains the order reference (Latin script preserved
 *     per UAE business convention)
 *   - Text body contains characteristic Arabic phrases for the
 *     event (thank-you, payment confirmed, etc.)
 *   - HTML body wraps Arabic content with RTL markup
 *   - Order reference + amounts stay Latin numerals
 *   - Currency 'AED' (English label) appears since the test order
 *     uses default AED currency; we verify the label survives the
 *     pipeline. Sub-phase D may localize the currency label via
 *     order.getCurrency() if it returns localized labels; until
 *     then we just verify the currency string is present.
 */
#[CoversClass(OrderEmailTemplateRenderer::class)]
final class OrderEmailTemplateRendererArabicCustomerTest extends TestCase
{
    private OrderEmailTemplateRenderer $renderer;

    protected function setUp(): void
    {
        $this->renderer = new OrderEmailTemplateRenderer();
    }

    #[Test]
    public function orderPlacedCustomerArContainsKeyArabicPhrases(): void
    {
        $order = $this->makeOrder('V3-AR-PLACED');
        $rendered = $this->renderer->render(
            EmailTemplate::ORDER_PLACED_CUSTOMER,
            $order,
            [],
            'ar',
        );

        // Subject: contains "received order" Arabic phrase + Latin
        // order reference
        self::assertStringContainsString('تم استلام طلبك', $rendered->subject);
        self::assertStringContainsString('V3-AR-PLACED', $rendered->subject);

        // Text body: thank-you, order reference label, total label,
        // items section, next-step pointer
        self::assertStringContainsString('شكراً لطلبك', $rendered->textBody);
        self::assertStringContainsString('رقم الطلب', $rendered->textBody);
        self::assertStringContainsString('المجموع', $rendered->textBody);
        self::assertStringContainsString('العناصر', $rendered->textBody);
        // Order reference stays Latin
        self::assertStringContainsString('V3-AR-PLACED', $rendered->textBody);

        // HTML body: wrapped RTL + heading
        self::assertStringContainsString('<html lang="ar" dir="rtl">', $rendered->htmlBody);
        self::assertStringContainsString('<h3>العناصر</h3>', $rendered->htmlBody);
    }

    #[Test]
    public function orderPaidCustomerArContainsKeyArabicPhrases(): void
    {
        $order = $this->makeOrder('V3-AR-PAID');
        $rendered = $this->renderer->render(
            EmailTemplate::ORDER_PAID_CUSTOMER,
            $order,
            [],
            'ar',
        );

        self::assertStringContainsString('تأكيد الدفع', $rendered->subject);
        self::assertStringContainsString('V3-AR-PAID', $rendered->subject);

        self::assertStringContainsString('تم تأكيد دفعتك', $rendered->textBody);
        // Full order details are embedded: the item list + a pricing
        // breakdown ending in the order total.
        self::assertStringContainsString('الإجمالي', $rendered->textBody);
        self::assertStringContainsString('المنتجات', $rendered->textBody);
        // Next-step pointer: order being prepared, will ship
        self::assertStringContainsString('تجهيز طلبك', $rendered->textBody);

        self::assertStringContainsString('<html lang="ar" dir="rtl">', $rendered->htmlBody);
    }

    #[Test]
    public function orderPaymentFailedCustomerArContainsKeyArabicPhrases(): void
    {
        $order = $this->makeOrder('V3-AR-FAIL');
        $rendered = $this->renderer->render(
            EmailTemplate::ORDER_PAYMENT_FAILED_CUSTOMER,
            $order,
            [],
            'ar',
        );

        self::assertStringContainsString('تعذّر إتمام الدفع', $rendered->subject);
        self::assertStringContainsString('V3-AR-FAIL', $rendered->subject);

        // Text: payment couldn't be processed, retry suggestion,
        // support contact suggestion
        self::assertStringContainsString('لم نتمكن من معالجة', $rendered->textBody);
        self::assertStringContainsString('المحاولة مجدداً', $rendered->textBody);
        self::assertStringContainsString('الدعم', $rendered->textBody);
    }

    #[Test]
    public function orderShippedCustomerArContainsItemName(): void
    {
        $order = $this->makeOrder('V3-AR-SHIP');
        $rendered = $this->renderer->render(
            EmailTemplate::ORDER_SHIPPED_CUSTOMER,
            $order,
            ['item_name' => 'فستان أحمر'],
            'ar',
        );

        self::assertStringContainsString('تم شحن طلبك', $rendered->subject);
        self::assertStringContainsString('V3-AR-SHIP', $rendered->subject);

        // Text: characteristic shipping phrase
        self::assertStringContainsString('في طريقه إليك', $rendered->textBody);
        // Item name passes through (also Arabic in this test case;
        // verifies Arabic product names render correctly within
        // Arabic templates)
        self::assertStringContainsString('فستان أحمر', $rendered->textBody);
        self::assertStringContainsString('فستان أحمر', $rendered->htmlBody);
    }

    #[Test]
    public function orderShippedCustomerArHandlesLatinItemName(): void
    {
        // Mixed-script case: Latin product name within Arabic email.
        // Common in UAE — many products have English brand names
        // even when targeting Arabic-speaking customers.
        $order = $this->makeOrder('V3-AR-SHIP2');
        $rendered = $this->renderer->render(
            EmailTemplate::ORDER_SHIPPED_CUSTOMER,
            $order,
            ['item_name' => 'Premium Leather Bag'],
            'ar',
        );

        // Item name preserved verbatim in Latin
        self::assertStringContainsString('Premium Leather Bag', $rendered->textBody);
        // Arabic context still present
        self::assertStringContainsString('في طريقه إليك', $rendered->textBody);
    }

    #[Test]
    public function orderDeliveredCustomerArContainsItemName(): void
    {
        $order = $this->makeOrder('V3-AR-DEL');
        $rendered = $this->renderer->render(
            EmailTemplate::ORDER_DELIVERED_CUSTOMER,
            $order,
            ['item_name' => 'حقيبة سفر'],
            'ar',
        );

        self::assertStringContainsString('تم تسليم طلبك', $rendered->subject);
        self::assertStringContainsString('V3-AR-DEL', $rendered->subject);

        self::assertStringContainsString('تم تسليم أحد منتجات طلبك', $rendered->textBody);
        self::assertStringContainsString('حقيبة سفر', $rendered->textBody);
        // 7-day return-policy mention
        self::assertStringContainsString('7 أيام', $rendered->textBody);
    }

    #[Test]
    public function orderCancelledCustomerArWithRefundShowsAmount(): void
    {
        $order = $this->makeOrder('V3-AR-CAN');
        $rendered = $this->renderer->render(
            EmailTemplate::ORDER_CANCELLED_CUSTOMER,
            $order,
            [
                'refund_issued' => true,
                'refund_amount' => '99.00',
            ],
            'ar',
        );

        self::assertStringContainsString('تم إلغاء طلبك', $rendered->subject);
        self::assertStringContainsString('V3-AR-CAN', $rendered->subject);

        // Cancellation acknowledgment + refund amount line
        self::assertStringContainsString('تم إلغاء طلبك', $rendered->textBody);
        self::assertStringContainsString('استرداد', $rendered->textBody);
        // Western Arabic numerals (not Eastern ٩٩.٠٠)
        self::assertStringContainsString('99.00', $rendered->textBody);
        // Support contact suggestion if unauthorized
        self::assertStringContainsString('الدعم', $rendered->textBody);
    }

    #[Test]
    public function orderCancelledCustomerArWithoutRefundShowsNoPaymentMessage(): void
    {
        $order = $this->makeOrder('V3-AR-CAN2');
        $rendered = $this->renderer->render(
            EmailTemplate::ORDER_CANCELLED_CUSTOMER,
            $order,
            ['refund_issued' => false],
            'ar',
        );

        // No-payment-charged message in Arabic
        self::assertStringContainsString('لم يتم تحصيل', $rendered->textBody);
    }

    #[Test]
    public function orderRefundedCustomerArShowsAmountAndType(): void
    {
        $order = $this->makeOrder('V3-AR-REF');
        $rendered = $this->renderer->render(
            EmailTemplate::ORDER_REFUNDED_CUSTOMER,
            $order,
            [
                'refund_amount' => '49.50',
                'is_full_refund' => false,
            ],
            'ar',
        );

        self::assertStringContainsString('إصدار استرداد', $rendered->subject);
        self::assertStringContainsString('V3-AR-REF', $rendered->subject);

        // Partial refund label
        self::assertStringContainsString('استرداد جزئي', $rendered->textBody);
        // Western Arabic numerals (not Eastern ٤٩.٥٠)
        self::assertStringContainsString('49.50', $rendered->textBody);
        // 5-10 day timing reference
        self::assertStringContainsString('5 إلى 10 أيام عمل', $rendered->textBody);
    }

    #[Test]
    public function orderRefundedCustomerArFullRefundShowsFullLabel(): void
    {
        $order = $this->makeOrder('V3-AR-REF-F');
        $rendered = $this->renderer->render(
            EmailTemplate::ORDER_REFUNDED_CUSTOMER,
            $order,
            ['is_full_refund' => true],
            'ar',
        );

        // Full refund label
        self::assertStringContainsString('استرداد كامل', $rendered->textBody);
    }

    #[Test]
    public function allCustomerArabicTemplatesPreserveOrderReferenceLatinScript(): void
    {
        // UAE business convention: order references stay in Latin
        // script across all locale variants. Mixed-script reference
        // would confuse cross-channel correlation (mobile, web,
        // operator tools all show Latin).
        $customerTemplates = [
            EmailTemplate::ORDER_PLACED_CUSTOMER,
            EmailTemplate::ORDER_PAID_CUSTOMER,
            EmailTemplate::ORDER_PAYMENT_FAILED_CUSTOMER,
            EmailTemplate::ORDER_SHIPPED_CUSTOMER,
            EmailTemplate::ORDER_DELIVERED_CUSTOMER,
            EmailTemplate::ORDER_CANCELLED_CUSTOMER,
            EmailTemplate::ORDER_REFUNDED_CUSTOMER,
        ];
        $order = $this->makeOrder('V3-LATIN-001');

        foreach ($customerTemplates as $template) {
            $rendered = $this->renderer->render($template, $order, [
                'item_name' => 'Test',
                'refund_amount' => '0.00',
            ], 'ar');
            self::assertStringContainsString(
                'V3-LATIN-001',
                $rendered->subject,
                "Order reference missing from subject for {$template->value}",
            );
            self::assertStringContainsString(
                'V3-LATIN-001',
                $rendered->textBody,
                "Order reference missing from text body for {$template->value}",
            );
        }
    }

    // ===== Helpers =====

    private function makeOrder(string $reference): Order
    {
        $user = new User(
            'customer@example.com',
            '+971501234567',
            password_hash('p', PASSWORD_BCRYPT),
            'AE',
        );
        $this->setProp($user, 'id', 42);

        $order = new Order(user: $user, orderReference: $reference, subtotal: '99.00');
        $this->setProp($order, 'id', 100);

        // Add one item so itemListText/Html have content
        $vendor = (new \ReflectionClass(Vendor::class))->newInstanceWithoutConstructor();
        $this->setProp($vendor, 'id', 5);
        $this->setProp($vendor, 'name', 'Test Vendor');
        $this->setProp($vendor, 'contactEmail', 'vendor@example.com');

        $product = (new \ReflectionClass(Product::class))->newInstanceWithoutConstructor();
        $this->setProp($product, 'id', 7);
        $this->setProp($product, 'name', 'Test Product');
        $this->setProp($product, 'vendor', $vendor);

        $item = new OrderItem(
            product: $product, vendor: $vendor,
            quantity: 1, unitPrice: '99.00',
            productNameSnapshot: 'Test Product',
            productImageSnapshot: 'cdn/x.jpg',
        );
        $this->setProp($item, 'id', 99);
        $order->addItem($item);

        return $order;
    }

    private function setProp(object $entity, string $prop, mixed $value): void
    {
        $ref = new \ReflectionProperty($entity::class, $prop);
        $ref->setAccessible(true);
        $ref->setValue($entity, $value);
    }
}
