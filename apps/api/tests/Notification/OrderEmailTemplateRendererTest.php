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

#[CoversClass(OrderEmailTemplateRenderer::class)]
final class OrderEmailTemplateRendererTest extends TestCase
{
    private OrderEmailTemplateRenderer $renderer;

    protected function setUp(): void
    {
        $this->renderer = new OrderEmailTemplateRenderer();
    }

    #[Test]
    public function orderPlacedCustomerIncludesOrderReferenceAndTotal(): void
    {
        $order = $this->makeOrder(reference: 'V3-001', subtotal: '299.00');
        $rendered = $this->renderer->render(EmailTemplate::ORDER_PLACED_CUSTOMER, $order);

        self::assertStringContainsString('V3-001', $rendered->subject);
        self::assertStringContainsString('V3-001', $rendered->textBody);
        self::assertStringContainsString('V3-001', $rendered->htmlBody);
        self::assertStringContainsString('299.00', $rendered->textBody);
        self::assertStringContainsString('299.00', $rendered->htmlBody);
        self::assertStringContainsString('AED', $rendered->textBody);
    }

    #[Test]
    public function orderPlacedCustomerEscapesHtmlInProductName(): void
    {
        // XSS payload as product name — must be escaped in HTML, raw in text
        $order = $this->makeOrder(reference: 'V3-XSS');
        $this->addItem($order, name: '<script>alert(1)</script>');

        $rendered = $this->renderer->render(EmailTemplate::ORDER_PLACED_CUSTOMER, $order);

        // HTML body MUST NOT contain raw <script>
        self::assertStringNotContainsString('<script>', $rendered->htmlBody);
        self::assertStringContainsString('&lt;script&gt;', $rendered->htmlBody);

        // Plain-text body unescaped is fine (it's not rendered as HTML)
        self::assertStringContainsString('<script>alert(1)</script>', $rendered->textBody);
    }

    #[Test]
    public function orderPaidCustomerHasConfirmationMessage(): void
    {
        $order = $this->makeOrder(reference: 'V3-002');
        $rendered = $this->renderer->render(EmailTemplate::ORDER_PAID_CUSTOMER, $order);

        self::assertStringContainsString('confirmed', strtolower($rendered->subject));
        self::assertStringContainsString('V3-002', $rendered->subject);
    }

    #[Test]
    public function orderPaidCustomerItemlessOrderGetsCleanConfirmation(): void
    {
        // A gift-card PURCHASE is a synthetic order with no line items — the
        // paid email must NOT show the empty details block or a "we're
        // preparing… it ships" line, and should mention the gift card.
        $order = $this->makeOrder(reference: 'V3-GC-PURCHASE');
        $rendered = $this->renderer->render(EmailTemplate::ORDER_PAID_CUSTOMER, $order);

        self::assertStringContainsString('V3-GC-PURCHASE', $rendered->textBody);
        self::assertStringContainsStringIgnoringCase('gift card', $rendered->textBody);
        self::assertStringNotContainsString('once it ships', $rendered->textBody);
        self::assertStringNotContainsString('MEASUREMENTS', $rendered->textBody);
    }

    #[Test]
    public function orderPaymentFailedCustomerIncludesTryAgainLanguage(): void
    {
        $order = $this->makeOrder(reference: 'V3-003');
        $rendered = $this->renderer->render(EmailTemplate::ORDER_PAYMENT_FAILED_CUSTOMER, $order);

        self::assertStringContainsString('V3-003', $rendered->subject);
        self::assertStringContainsString('try again', strtolower($rendered->textBody));
    }

    #[Test]
    public function orderShippedCustomerIncludesItemName(): void
    {
        $order = $this->makeOrder(reference: 'V3-004');
        $rendered = $this->renderer->render(
            EmailTemplate::ORDER_SHIPPED_CUSTOMER,
            $order,
            ['item_name' => 'Silk Abaya'],
        );

        self::assertStringContainsString('shipped', strtolower($rendered->subject));
        self::assertStringContainsString('Silk Abaya', $rendered->textBody);
        self::assertStringContainsString('Silk Abaya', $rendered->htmlBody);
    }

    #[Test]
    public function orderDeliveredCustomerIncludesItemName(): void
    {
        $order = $this->makeOrder(reference: 'V3-005');
        $rendered = $this->renderer->render(
            EmailTemplate::ORDER_DELIVERED_CUSTOMER,
            $order,
            ['item_name' => 'Leather Wallet'],
        );

        self::assertStringContainsString('delivered', strtolower($rendered->subject));
        self::assertStringContainsString('Leather Wallet', $rendered->textBody);
    }

    #[Test]
    public function orderCancelledCustomerWithRefund(): void
    {
        $order = $this->makeOrder(reference: 'V3-006', subtotal: '199.00');
        $rendered = $this->renderer->render(
            EmailTemplate::ORDER_CANCELLED_CUSTOMER,
            $order,
            ['refund_issued' => true, 'refund_amount' => '199.00'],
        );

        self::assertStringContainsString('cancelled', strtolower($rendered->subject));
        self::assertStringContainsString('199.00', $rendered->textBody);
        self::assertStringContainsString('refund', strtolower($rendered->textBody));
    }

    #[Test]
    public function orderCancelledCustomerWithoutRefundSaysNoCharge(): void
    {
        $order = $this->makeOrder(reference: 'V3-007');
        $rendered = $this->renderer->render(
            EmailTemplate::ORDER_CANCELLED_CUSTOMER,
            $order,
            ['refund_issued' => false, 'refund_amount' => null],
        );

        self::assertStringContainsString('No payment was charged', $rendered->textBody);
    }

    #[Test]
    public function orderRefundedCustomerFullVsPartial(): void
    {
        $order = $this->makeOrder(reference: 'V3-008', subtotal: '500.00');

        $full = $this->renderer->render(EmailTemplate::ORDER_REFUNDED_CUSTOMER, $order, [
            'refund_amount' => '500.00',
            'is_full_refund' => true,
        ]);
        self::assertStringContainsString('full refund', strtolower($full->textBody));

        $partial = $this->renderer->render(EmailTemplate::ORDER_REFUNDED_CUSTOMER, $order, [
            'refund_amount' => '100.00',
            'is_full_refund' => false,
        ]);
        self::assertStringContainsString('partial refund', strtolower($partial->textBody));
        self::assertStringContainsString('100.00', $partial->textBody);
    }

    #[Test]
    public function orderPlacedVendorIncludesVendorItems(): void
    {
        $order = $this->makeOrder(reference: 'V3-009');
        $rendered = $this->renderer->render(
            EmailTemplate::ORDER_PLACED_VENDOR,
            $order,
            ['vendor_items' => ['Silk Abaya', 'Gold Earrings']],
        );

        self::assertStringContainsString('V3-009', $rendered->subject);
        self::assertStringContainsString('Silk Abaya', $rendered->textBody);
        self::assertStringContainsString('Gold Earrings', $rendered->textBody);
    }

    #[Test]
    public function orderCancelledVendorWarnsDoNotShip(): void
    {
        $order = $this->makeOrder(reference: 'V3-010');
        $rendered = $this->renderer->render(
            EmailTemplate::ORDER_CANCELLED_VENDOR,
            $order,
            ['reason' => 'fraud risk'],
        );

        self::assertStringContainsString('do not ship', strtolower($rendered->subject));
        self::assertStringContainsString('fraud risk', $rendered->textBody);
    }

    #[Test]
    public function disputeOpenedAdminIncludesAlertPrefixAndAmount(): void
    {
        $order = $this->makeOrder(reference: 'V3-011', subtotal: '450.00');
        $rendered = $this->renderer->render(
            EmailTemplate::DISPUTE_OPENED_ADMIN,
            $order,
            [
                'event_type' => 'CHARGEBACK_OPENED',
                'amount' => '450.00',
                'currency' => 'AED',
                'reason' => 'product not as described',
            ],
        );

        self::assertStringContainsString('ALERT', $rendered->subject);
        self::assertStringContainsString('V3-011', $rendered->subject);
        self::assertStringContainsString('CHARGEBACK_OPENED', $rendered->textBody);
        self::assertStringContainsString('product not as described', $rendered->textBody);
    }

    #[Test]
    public function renderedEmailHasBothTextAndHtmlBodies(): void
    {
        // Sanity check: every template should produce non-empty text + html
        $order = $this->makeOrder(reference: 'V3-SANITY');
        foreach (EmailTemplate::cases() as $template) {
            // M3.2.X.11: cart-scoped templates are rendered by
            // CartEmailTemplateRenderer; skip them here.
            if (str_starts_with($template->value, 'cart.')) {
                continue;
            }
            $extra = [
                'item_name' => 'Test',
                'vendor_items' => ['Test'],
                'refund_amount' => '0.00',
            ];
            $rendered = $this->renderer->render($template, $order, $extra);
            self::assertNotEmpty($rendered->subject, "subject empty for {$template->value}");
            self::assertNotEmpty($rendered->textBody, "textBody empty for {$template->value}");
            self::assertNotEmpty($rendered->htmlBody, "htmlBody empty for {$template->value}");
            // M3.2.X.7-B: wrapHtml now emits <html lang="en" dir="ltr">
            // (or rtl for ar locale). Match the opening tag prefix
            // rather than the bare <html> form.
            self::assertStringContainsString('<html lang=', $rendered->htmlBody);
        }
    }

    // ===== Helpers =====

    private function makeOrder(string $reference, string $subtotal = '99.00'): Order
    {
        $user = $this->makeUser();
        $order = new Order(user: $user, orderReference: $reference, subtotal: $subtotal);
        $this->setEntityId($order, 100);
        return $order;
    }

    private function makeUser(): User
    {
        $u = new User('customer@example.com', '+971501234567', password_hash('p', PASSWORD_BCRYPT), 'AE');
        $this->setEntityId($u, 42);
        return $u;
    }

    private function addItem(Order $order, string $name): void
    {
        $vendor = (new \ReflectionClass(Vendor::class))->newInstanceWithoutConstructor();
        $this->setEntityProp($vendor, 'id', 5);
        $this->setEntityProp($vendor, 'name', 'Test Vendor');
        $this->setEntityProp($vendor, 'contactEmail', 'vendor@example.com');

        $product = (new \ReflectionClass(Product::class))->newInstanceWithoutConstructor();
        $this->setEntityProp($product, 'id', 200);
        $this->setEntityProp($product, 'name', $name);
        $this->setEntityProp($product, 'vendor', $vendor);

        $item = new OrderItem(
            product: $product, vendor: $vendor,
            quantity: 1, unitPrice: '99.00',
            productNameSnapshot: $name,
            productImageSnapshot: 'cdn/x.jpg',
        );
        $this->setEntityId($item, 500);
        $order->addItem($item);
    }

    private function setEntityId(object $entity, int $id): void
    {
        $ref = new \ReflectionProperty($entity::class, 'id');
        $ref->setAccessible(true);
        $ref->setValue($entity, $id);
    }

    private function setEntityProp(object $entity, string $prop, mixed $value): void
    {
        $ref = new \ReflectionProperty($entity::class, $prop);
        $ref->setAccessible(true);
        $ref->setValue($entity, $value);
    }
}
