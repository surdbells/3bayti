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
        // XSS payload as product name, must be escaped in HTML, raw in text
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
    public function customerEmailsCarryTheSupportFooterAndNoChatPolicyLine(): void
    {
        $order = $this->makeOrder(reference: 'V3-SUPPORT');
        $rendered = $this->renderer->render(EmailTemplate::ORDER_PAID_CUSTOMER, $order);

        // Support contact is appended to customer emails (text + HTML).
        self::assertStringContainsString('support@3bayti.com', $rendered->textBody);
        self::assertStringContainsString('3baytii.ae', $rendered->textBody);
        self::assertStringContainsString('For any issue do not hesitate', $rendered->textBody);
        self::assertStringContainsString('mailto:support@3bayti.com', $rendered->htmlBody);
        self::assertStringContainsString('instagram.com/3baytii.ae', $rendered->htmlBody);

        // The in-app chat policy line must NEVER appear in an email.
        self::assertStringNotContainsString('keep all communication here', $rendered->textBody);
        self::assertStringNotContainsString('keep all communication here', $rendered->htmlBody);

        // No unfilled slot leaks into the markup.
        self::assertStringNotContainsString('3BAYTI_SUPPORT_SLOT', $rendered->htmlBody);
    }

    #[Test]
    public function vendorAndAdminEmailsDoNotCarryTheSupportFooter(): void
    {
        $order = $this->makeOrder(reference: 'V3-NO-SUPPORT');

        $vendor = $this->renderer->render(EmailTemplate::ORDER_PLACED_VENDOR, $order, ['vendor_items' => ['A']]);
        self::assertStringNotContainsString('support@3bayti.com', $vendor->textBody);
        self::assertStringNotContainsString('support@3bayti.com', $vendor->htmlBody);
        self::assertStringNotContainsString('3BAYTI_SUPPORT_SLOT', $vendor->htmlBody);

        $admin = $this->renderer->render(EmailTemplate::DISPUTE_OPENED_ADMIN, $order);
        self::assertStringNotContainsString('support@3bayti.com', $admin->textBody);
        self::assertStringNotContainsString('keep all communication here', $admin->htmlBody);
        self::assertStringNotContainsString('3BAYTI_SUPPORT_SLOT', $admin->htmlBody);
    }

    #[Test]
    public function htmlEmailsUseTheRichBrandedShell(): void
    {
        // A PRODUCT order, the tracker only belongs on orders that actually
        // ship (a gift-card purchase is digital; see the itemless test below).
        $order = $this->makeOrder(reference: 'V3-RICH');
        $this->addItem($order, name: 'Abaya');
        $rendered = $this->renderer->render(EmailTemplate::ORDER_PAID_CUSTOMER, $order);

        // Table-based, inline-styled shell (email-client safe) with the brand
        // wordmark, a preheader, and the progress tracker.
        self::assertStringContainsString('role="presentation"', $rendered->htmlBody);
        self::assertStringContainsString('3bayti', $rendered->htmlBody);
        self::assertStringContainsString('V3-RICH', $rendered->htmlBody);
        self::assertStringContainsString('Delivered', $rendered->htmlBody); // tracker step
        self::assertStringNotContainsString('white-space: pre-wrap', $rendered->htmlBody);
    }

    #[Test]
    public function giftCardPurchaseEmailHasNoShippingTracker(): void
    {
        // A gift card is digital: an Ordered→Paid→Shipped→Delivered tracker
        // would never advance past Paid, so it must not be rendered.
        $order = $this->makeOrder(reference: 'V3-GC-NOTRACK');
        $rendered = $this->renderer->render(EmailTemplate::ORDER_PAID_CUSTOMER, $order, [
            'gift_card' => [
                'code' => 'AAAA-BBBB-CCCC-DDDD',
                'denomination' => '200.00',
                'currency' => 'AED',
                'auto_delivered' => false,
            ],
        ]);

        self::assertStringNotContainsString('Delivered', $rendered->htmlBody);
        self::assertStringNotContainsString('Shipped', $rendered->htmlBody);
        // The card details are still there.
        self::assertStringContainsString('AAAA-BBBB-CCCC-DDDD', $rendered->htmlBody);
    }

    #[Test]
    public function orderPaidCustomerItemlessOrderGetsCleanConfirmation(): void
    {
        // A gift-card PURCHASE is a synthetic order with no line items, the
        // paid email must NOT show an empty details block or a "we're
        // preparing… it ships" line.
        $order = $this->makeOrder(reference: 'V3-GC-PURCHASE');
        $rendered = $this->renderer->render(EmailTemplate::ORDER_PAID_CUSTOMER, $order);

        self::assertStringContainsString('V3-GC-PURCHASE', $rendered->textBody);
        self::assertStringNotContainsString('once it ships', $rendered->textBody);
        self::assertStringNotContainsString('MEASUREMENTS', $rendered->textBody);
    }

    #[Test]
    public function orderPaidCustomerShowsThePurchasedGiftCardDetails(): void
    {
        // When the order funded a gift card, OrderNotificationService resolves
        // the card and passes it in $extra, the buyer's confirmation must show
        // what they actually bought (value, code, recipient, expiry).
        $order = $this->makeOrder(reference: 'V3-GC-DETAIL');
        $rendered = $this->renderer->render(EmailTemplate::ORDER_PAID_CUSTOMER, $order, [
            'gift_card' => [
                'code'           => 'A1B2-C3D4-E5F6-7890',
                'denomination'   => '500.00',
                'currency'       => 'AED',
                'theme'          => 'birthday',
                'recipient_name' => 'Sara',
                'expires_at'     => '31 Jul 2027',
                'auto_delivered' => true,
            ],
        ]);

        foreach ([$rendered->textBody, $rendered->htmlBody] as $body) {
            self::assertStringContainsString('A1B2-C3D4-E5F6-7890', $body);
            self::assertStringContainsString('500.00', $body);
            self::assertStringContainsString('Sara', $body);
            self::assertStringContainsString('31 Jul 2027', $body);
        }
        // auto_delivered=true → tells the buyer we deliver it for them.
        self::assertStringContainsString("We'll deliver the card", $rendered->textBody);
    }

    #[Test]
    public function giftCardBlockTellsBuyerToShareTheCodeWhenNoRecipientContact(): void
    {
        $order = $this->makeOrder(reference: 'V3-GC-SHARE');
        $rendered = $this->renderer->render(EmailTemplate::ORDER_PAID_CUSTOMER, $order, [
            'gift_card' => [
                'code'           => 'AAAA-BBBB-CCCC-DDDD',
                'denomination'   => '100.00',
                'currency'       => 'AED',
                'auto_delivered' => false,
            ],
        ]);

        self::assertStringContainsString('Share this code', $rendered->textBody);
        self::assertStringNotContainsString("We'll deliver the card", $rendered->textBody);
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
    public function orderPaymentReminderPendingNudgesToCompletePayment(): void
    {
        $order = $this->makeOrder(reference: 'V3-REM-1', subtotal: '150.00');
        $rendered = $this->renderer->render(
            EmailTemplate::ORDER_PAYMENT_REMINDER_CUSTOMER,
            $order,
            ['reason' => 'pending'],
        );

        self::assertStringContainsString('V3-REM-1', $rendered->subject);
        self::assertStringContainsString('complete payment', strtolower($rendered->subject));
        // Shows the amount due and points the customer back to the app.
        self::assertStringContainsString('150.00', $rendered->textBody);
        self::assertStringContainsString('AED', $rendered->textBody);
        self::assertStringContainsString('my orders', strtolower($rendered->textBody));
        self::assertStringContainsString('150.00', $rendered->htmlBody);
    }

    #[Test]
    public function orderPaymentReminderFailedUsesRetryFraming(): void
    {
        $order = $this->makeOrder(reference: 'V3-REM-2');
        $rendered = $this->renderer->render(
            EmailTemplate::ORDER_PAYMENT_REMINDER_CUSTOMER,
            $order,
            ['reason' => 'failed'],
        );

        self::assertStringContainsString('V3-REM-2', $rendered->subject);
        self::assertStringContainsString('needs payment', strtolower($rendered->subject));
        self::assertStringContainsString("couldn't process", strtolower($rendered->textBody));
    }

    #[Test]
    public function orderPaymentReminderIsDetailedWithItemsAndPricing(): void
    {
        // The reminder must show what the customer is about to pay for -
        // the itemised list + a pricing breakdown, like the confirmation email.
        $order = $this->makeOrder(reference: 'V3-REM-DETAIL', subtotal: '300.00');
        $this->addItem($order, name: 'Silk Abaya');

        $rendered = $this->renderer->render(
            EmailTemplate::ORDER_PAYMENT_REMINDER_CUSTOMER,
            $order,
            ['reason' => 'pending'],
        );

        // Item shows up in both bodies.
        self::assertStringContainsString('Silk Abaya', $rendered->textBody);
        self::assertStringContainsString('Silk Abaya', $rendered->htmlBody);
        // Pricing breakdown, not just a bare total.
        self::assertStringContainsString('Subtotal', $rendered->htmlBody);
        self::assertStringContainsString('Amount due', $rendered->htmlBody);
        self::assertStringContainsString('Total', $rendered->htmlBody);
    }

    #[Test]
    public function orderPaymentReminderFollowupUsesFinalReminderCopy(): void
    {
        $order = $this->makeOrder(reference: 'V3-REM-F1');
        $rendered = $this->renderer->render(
            EmailTemplate::ORDER_PAYMENT_REMINDER_2_CUSTOMER,
            $order,
            ['reason' => 'pending'],
        );

        // Stage-2 subject signals urgency and is distinct from stage 1.
        self::assertStringContainsString('V3-REM-F1', $rendered->subject);
        self::assertStringContainsString('last reminder', strtolower($rendered->subject));
        self::assertStringContainsString('final reminder', strtolower($rendered->htmlBody));

        // Failed follow-up keeps the retry framing.
        $failed = $this->renderer->render(
            EmailTemplate::ORDER_PAYMENT_REMINDER_2_CUSTOMER,
            $order,
            ['reason' => 'failed'],
        );
        self::assertStringContainsString('last reminder', strtolower($failed->subject));
        self::assertStringContainsString('needs payment', strtolower($failed->subject));
    }

    #[Test]
    public function itemLifecycleEmailRendersRichItemCard(): void
    {
        // Tier-2: per-item lifecycle emails render a rich single-item card
        // (image + qty) plus the order-ref badge when the OrderItem is passed,
        // not just a bare "Item: name" line.
        $order = $this->makeOrder(reference: 'V3-SHIP-1');
        $this->addItem($order, name: 'Linen Kaftan');
        $item = $order->getItems()->last();

        $rendered = $this->renderer->render(
            EmailTemplate::ORDER_SHIPPED_CUSTOMER,
            $order,
            ['order_item' => $item],
        );

        self::assertStringContainsString('Linen Kaftan', $rendered->htmlBody);
        self::assertStringContainsString('Qty', $rendered->htmlBody);
        self::assertStringContainsString('cdn/x.jpg', $rendered->htmlBody);
        self::assertStringContainsString('V3-SHIP-1', $rendered->htmlBody);
        self::assertStringContainsString('Linen Kaftan', $rendered->textBody);
    }

    #[Test]
    public function itemLifecycleEmailFallsBackToItemNameWhenNoOrderItem(): void
    {
        // Backward compatibility: callers that only pass item_name still work.
        $order = $this->makeOrder(reference: 'V3-SHIP-2');
        $rendered = $this->renderer->render(
            EmailTemplate::ORDER_DELIVERED_CUSTOMER,
            $order,
            ['item_name' => 'Suede Loafers'],
        );

        self::assertStringContainsString('Suede Loafers', $rendered->htmlBody);
        self::assertStringContainsString('V3-SHIP-2', $rendered->htmlBody);
    }

    #[Test]
    public function orderPaymentReminderArabicCustomerGetsArabicCopy(): void
    {
        $order = $this->makeOrder(reference: 'V3-REM-AR');
        $rendered = $this->renderer->render(
            EmailTemplate::ORDER_PAYMENT_REMINDER_CUSTOMER,
            $order,
            ['reason' => 'pending'],
            User::LOCALE_AR,
        );

        self::assertStringContainsString('V3-REM-AR', $rendered->subject);
        // Arabic lead copy + RTL shell.
        self::assertStringContainsString('أكمل الدفع', $rendered->subject);
        self::assertStringContainsString('dir="rtl"', $rendered->htmlBody);
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

    #[Test]
    public function vendorOrderEmailShowsOnlyThatVendorsItemsTotalNotTheWholeOrder(): void
    {
        // Multi-vendor order. Store A sells 150 + 55 = 205; Store B sells 90.
        // Whole order: subtotal 295, delivery 33, total 328.
        $order = new Order(
            user: $this->makeUser(),
            orderReference: 'V3-MULTI',
            subtotal: '295.00',
            deliveryFee: '33.00',
        );
        $this->setEntityId($order, 100);
        $a1 = $this->addVendorItem($order, 5, 'Store A', 'Silk Abaya', '150.00', 501);
        $a2 = $this->addVendorItem($order, 5, 'Store A', 'Gold Earrings', '55.00', 502);
        $this->addVendorItem($order, 6, 'Store B', 'Leather Bag', '90.00', 503);

        $rendered = $this->renderer->render(
            EmailTemplate::ORDER_PLACED_VENDOR,
            $order,
            ['vendor_order_items' => [$a1, $a2], 'vendor_name' => 'Store A'],
        );

        foreach (['text' => $rendered->textBody, 'html' => $rendered->htmlBody] as $where => $body) {
            // Shows ONLY this vendor's items total (150 + 55 = 205).
            self::assertStringContainsString('205.00', $body, "vendor items total missing in {$where}");
            self::assertStringContainsString('Silk Abaya', $body);
            self::assertStringContainsString('Gold Earrings', $body);
            // NOT the delivery fee, whole-order subtotal/total, or other store's item.
            self::assertStringNotContainsString('33.00', $body, "delivery fee leaked into {$where}");
            self::assertStringNotContainsString('328.00', $body, "whole-order total leaked into {$where}");
            self::assertStringNotContainsString('295.00', $body, "whole-order subtotal leaked into {$where}");
            self::assertStringNotContainsString('Leather Bag', $body, "other store's item leaked into {$where}");
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

    /** Add one line item for a specific vendor; returns it for vendor_order_items. */
    private function addVendorItem(
        Order $order,
        int $vendorId,
        string $vendorName,
        string $name,
        string $unitPrice,
        int $itemId,
    ): OrderItem {
        $vendor = (new \ReflectionClass(Vendor::class))->newInstanceWithoutConstructor();
        $this->setEntityProp($vendor, 'id', $vendorId);
        $this->setEntityProp($vendor, 'name', $vendorName);
        $this->setEntityProp($vendor, 'contactEmail', 'v' . $vendorId . '@example.com');

        $product = (new \ReflectionClass(Product::class))->newInstanceWithoutConstructor();
        $this->setEntityProp($product, 'id', 200 + $vendorId);
        $this->setEntityProp($product, 'name', $name);
        $this->setEntityProp($product, 'vendor', $vendor);

        $item = new OrderItem(
            product: $product, vendor: $vendor,
            quantity: 1, unitPrice: $unitPrice,
            productNameSnapshot: $name,
            productImageSnapshot: '',
        );
        $this->setEntityId($item, $itemId);
        $order->addItem($item);
        return $item;
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
