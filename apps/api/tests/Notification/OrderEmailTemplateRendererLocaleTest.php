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
 * Coverage for OrderEmailTemplateRenderer locale routing (M3.2.X.7-B).
 *
 * Locks the dispatch semantics:
 *   - render() with locale='ar' dispatches to *Ar() methods
 *   - render() with locale='en' or omitted dispatches to *En() methods
 *   - wrapHtml() emits <html lang="..." dir="ltr|rtl">
 *   - DISPUTE_OPENED_ADMIN is LOCKED to English regardless of locale
 *     (Q-VendorAdminLocale = A)
 *
 * Translation CONTENT for Arabic stubs is sub-phase C/D scope.
 * Sub-phase B verifies only the DISPATCH + WRAPPER MARKUP.
 */
#[CoversClass(OrderEmailTemplateRenderer::class)]
final class OrderEmailTemplateRendererLocaleTest extends TestCase
{
    private OrderEmailTemplateRenderer $renderer;

    protected function setUp(): void
    {
        $this->renderer = new OrderEmailTemplateRenderer();
    }

    // -----------------------------------------------------------------
    // Wrapper markup: lang= + dir= attributes
    // -----------------------------------------------------------------

    #[Test]
    public function englishLocaleProducesLtrWrapper(): void
    {
        $order = $this->makeOrder('V3-LOC-EN');
        $rendered = $this->renderer->render(
            EmailTemplate::ORDER_PLACED_CUSTOMER,
            $order,
            [],
            'en',
        );

        self::assertStringContainsString('<html lang="en" dir="ltr">', $rendered->htmlBody);
        self::assertStringContainsString('premium UAE marketplace', $rendered->htmlBody);
    }

    #[Test]
    public function arabicLocaleProducesRtlWrapper(): void
    {
        $order = $this->makeOrder('V3-LOC-AR');
        $rendered = $this->renderer->render(
            EmailTemplate::ORDER_PLACED_CUSTOMER,
            $order,
            [],
            'ar',
        );

        self::assertStringContainsString(
            '<html lang="ar" dir="rtl">',
            $rendered->htmlBody,
            'Arabic locale must emit dir="rtl" so email clients render RTL',
        );
        // Arabic tagline present in footer
        self::assertStringContainsString('السوق', $rendered->htmlBody);
    }

    #[Test]
    public function defaultLocaleParameterPreservesEnglishBehavior(): void
    {
        // Backwards compat: omitting the locale parameter must preserve
        // the pre-M3.2.X.7 English-only behavior. This is what keeps
        // all existing renderer tests passing without modification.
        $order = $this->makeOrder('V3-LOC-DEF');
        $rendered = $this->renderer->render(EmailTemplate::ORDER_PLACED_CUSTOMER, $order);

        self::assertStringContainsString('<html lang="en" dir="ltr">', $rendered->htmlBody);
        self::assertStringContainsString('Order V3-LOC-DEF received', $rendered->subject);
    }

    // -----------------------------------------------------------------
    // Dispatch: locale=ar routes to Arabic methods for all customer +
    // vendor templates
    // -----------------------------------------------------------------

    #[Test]
    public function arabicLocaleRoutesAllCustomerTemplatesToArabicMethods(): void
    {
        // The Arabic templates each produce a subject containing a
        // characteristic Arabic phrase; verify each customer template
        // routes correctly by checking for that phrase in the subject.
        $order = $this->makeOrder('V3-AR-CUST');
        $extra = [
            'item_name' => 'Test',
            'vendor_items' => ['Test'],
            'refund_amount' => '0.00',
        ];

        $expectedSubjectFragments = [
            [EmailTemplate::ORDER_PLACED_CUSTOMER, 'تم استلام طلبك'],
            [EmailTemplate::ORDER_PAID_CUSTOMER, 'تأكيد الدفع'],
            [EmailTemplate::ORDER_PAYMENT_FAILED_CUSTOMER, 'تعذّر إتمام الدفع'],
            [EmailTemplate::ORDER_SHIPPED_CUSTOMER, 'تم شحن طلبك'],
            [EmailTemplate::ORDER_DELIVERED_CUSTOMER, 'تم تسليم طلبك'],
            [EmailTemplate::ORDER_CANCELLED_CUSTOMER, 'تم إلغاء طلبك'],
            [EmailTemplate::ORDER_REFUNDED_CUSTOMER, 'إصدار استرداد'],
        ];

        foreach ($expectedSubjectFragments as [$template, $expectedFragment]) {
            $rendered = $this->renderer->render($template, $order, $extra, 'ar');
            self::assertStringContainsString(
                $expectedFragment,
                $rendered->subject,
                "Arabic dispatch broken for {$template->value}",
            );
        }
    }

    #[Test]
    public function arabicLocaleRoutesVendorTemplatesToArabicMethods(): void
    {
        $order = $this->makeOrder('V3-AR-VEN');
        $extra = ['vendor_items' => ['Test']];

        $placed = $this->renderer->render(EmailTemplate::ORDER_PLACED_VENDOR, $order, $extra, 'ar');
        self::assertStringContainsString('طلب جديد', $placed->subject);

        $cancelled = $this->renderer->render(EmailTemplate::ORDER_CANCELLED_VENDOR, $order, $extra, 'ar');
        self::assertStringContainsString('تم إلغاء الطلب', $cancelled->subject);
    }

    #[Test]
    public function arabicStubsContainOrderReference(): void
    {
        // Every stub embeds the order reference so even minimal
        // Arabic emails are operationally useful (recipient knows
        // which order it's about).
        $order = $this->makeOrder('V3-AR-REF-XYZ');
        $rendered = $this->renderer->render(
            EmailTemplate::ORDER_PLACED_CUSTOMER,
            $order,
            [],
            'ar',
        );

        self::assertStringContainsString('V3-AR-REF-XYZ', $rendered->textBody);
        self::assertStringContainsString('V3-AR-REF-XYZ', $rendered->htmlBody);
        // Arabic label for "order number" present
        self::assertStringContainsString('رقم الطلب', $rendered->textBody);
    }

    // -----------------------------------------------------------------
    // Critical lock: admin emails ALWAYS English (Q-VendorAdminLocale=A)
    // -----------------------------------------------------------------

    #[Test]
    public function disputeOpenedAdminAlwaysEnglishEvenWhenLocaleIsArabic(): void
    {
        // Q-VendorAdminLocale = A locked: admin emails are English
        // regardless of the locale parameter. The match expression
        // for DISPUTE_OPENED_ADMIN intentionally ignores $isArabic.
        $order = $this->makeOrder('V3-ADM-LOCK');
        $extra = [
            'event_type' => 'CHARGEBACK_OPENED',
            'reason' => 'test',
        ];

        $rendered = $this->renderer->render(
            EmailTemplate::DISPUTE_OPENED_ADMIN,
            $order,
            $extra,
            'ar',  // Caller asks for Arabic, admin endpoint MUST ignore
        );

        // Subject is English (the English template's known prefix)
        self::assertStringContainsString(
            'ALERT',
            $rendered->subject,
            'Admin subject stays English even when locale=ar',
        );
        // wrapHtml emits dir=ltr for admin emails (English content)
        self::assertStringContainsString('<html lang="en" dir="ltr">', $rendered->htmlBody);
    }

    // -----------------------------------------------------------------
    // Output integrity
    // -----------------------------------------------------------------

    #[Test]
    public function arabicOutputIsValidUtf8(): void
    {
        // PHP heredoc preserves UTF-8 cleanly, but verify the byte
        // sequence survives the full render pipeline (escape +
        // concatenation + heredoc + return).
        $order = $this->makeOrder('V3-UTF8');
        $rendered = $this->renderer->render(
            EmailTemplate::ORDER_PLACED_CUSTOMER,
            $order,
            [],
            'ar',
        );

        self::assertTrue(
            mb_check_encoding($rendered->subject, 'UTF-8'),
            'subject is valid UTF-8',
        );
        self::assertTrue(
            mb_check_encoding($rendered->textBody, 'UTF-8'),
            'textBody is valid UTF-8',
        );
        self::assertTrue(
            mb_check_encoding($rendered->htmlBody, 'UTF-8'),
            'htmlBody is valid UTF-8',
        );
    }

    #[Test]
    public function allArabicTemplatesProduceNonEmptyOutput(): void
    {
        // Sanity check: every template (except admin which is
        // English-locked) produces non-empty output in Arabic.
        $order = $this->makeOrder('V3-AR-NE');
        $extra = [
            'item_name' => 'Test',
            'vendor_items' => ['Test'],
            'refund_amount' => '0.00',
        ];

        foreach (EmailTemplate::cases() as $template) {
            // M3.2.X.11: cart-scoped templates are rendered by
            // CartEmailTemplateRenderer; skip them here.
            if (str_starts_with($template->value, 'cart.')) {
                continue;
            }
            $rendered = $this->renderer->render($template, $order, $extra, 'ar');
            self::assertNotEmpty($rendered->subject, "ar subject empty for {$template->value}");
            self::assertNotEmpty($rendered->textBody, "ar textBody empty for {$template->value}");
            self::assertNotEmpty($rendered->htmlBody, "ar htmlBody empty for {$template->value}");
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
