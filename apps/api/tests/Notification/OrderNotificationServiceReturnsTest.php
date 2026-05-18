<?php

declare(strict_types=1);

namespace Bayti\Api\Tests\Notification;

use Bayti\Api\Domain\Catalog\Product;
use Bayti\Api\Domain\Catalog\Vendor;
use Bayti\Api\Domain\Order\Order;
use Bayti\Api\Domain\Order\OrderItem;
use Bayti\Api\Domain\User\User;
use Bayti\Api\Notification\EmailTemplate;
use Bayti\Api\Notification\InMemoryMailer;
use Bayti\Api\Notification\LocaleResolver;
use Bayti\Api\Notification\OrderEmailTemplateRenderer;
use Bayti\Api\Notification\OrderNotificationService;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * Coverage for the 6 M3.2.X.18-G return-flow notification methods +
 * the 14 new template variants in OrderEmailTemplateRenderer.
 *
 * Fan-out matrix:
 *   - returnSubmitted          → customer + per-vendor + admin
 *   - returnApproved           → customer only
 *   - returnDenied             → customer only (with required notes)
 *   - returnPickedUp           → customer only
 *   - returnReceivedByVendor   → customer only
 *   - returnRefunded           → customer only
 *
 * Locale routing:
 *   - Customer templates dispatch on User.locale (EN default, AR opt-in)
 *   - Vendor template uses Vendor.preferredLocale (Q-VendorAdminLocale)
 *   - Admin template ALWAYS English regardless of locale
 *
 * Templates render synthetic content (no external file fetch), so we
 * verify the right template fired by EmailTemplate value AND by
 * matching key tokens in the rendered subject/body.
 */
#[CoversClass(OrderNotificationService::class)]
#[CoversClass(OrderEmailTemplateRenderer::class)]
#[CoversClass(InMemoryMailer::class)]
final class OrderNotificationServiceReturnsTest extends TestCase
{
    private InMemoryMailer $mailer;
    private OrderNotificationService $service;
    private OrderNotificationService $serviceNoAdmin;

    protected function setUp(): void
    {
        $this->mailer = new InMemoryMailer();
        $this->service = new OrderNotificationService(
            mailer: $this->mailer,
            renderer: new OrderEmailTemplateRenderer(),
            adminRecipients: ['ops@3bayti.ae'],
            logger: new NullLogger(),
            localeResolver: new LocaleResolver(),
        );
        $this->serviceNoAdmin = new OrderNotificationService(
            mailer: $this->mailer,
            renderer: new OrderEmailTemplateRenderer(),
            adminRecipients: [],
            logger: new NullLogger(),
            localeResolver: new LocaleResolver(),
        );
    }

    // =================================================================
    // returnSubmitted — fan-out (customer + vendor + admin)
    // =================================================================

    #[Test]
    public function returnSubmittedFansOutToCustomerVendorAndAdmin(): void
    {
        $order = $this->makeOrder('V3-RET-001');
        $vendor = $this->makeVendor(id: 101, email: 'vendor101@shops.com');
        $this->addItem($order, $vendor, 'Defective gadget');

        $this->service->returnSubmitted($order, [
            'return_reference' => 'RET-7',
            'reason' => 'defective',
            'customer_notes' => 'broke on day two',
            'returned_items' => ['Defective gadget'],
            'vendor_ids' => [101],
        ]);

        $sent = $this->mailer->sent();
        self::assertCount(3, $sent, '1 customer + 1 vendor + 1 admin = 3 emails');

        // Customer
        self::assertSame(1, $this->mailer->countFor('customer@example.com'));
        self::assertSame(1, $this->mailer->countByTemplate(EmailTemplate::RETURN_SUBMITTED_CUSTOMER->value));

        // Vendor (because their item is in the return)
        self::assertSame(1, $this->mailer->countFor('vendor101@shops.com'));
        self::assertSame(1, $this->mailer->countByTemplate(EmailTemplate::RETURN_SUBMITTED_VENDOR->value));

        // Admin
        self::assertSame(1, $this->mailer->countFor('ops@3bayti.ae'));
        self::assertSame(1, $this->mailer->countByTemplate(EmailTemplate::RETURN_SUBMITTED_ADMIN->value));
    }

    #[Test]
    public function returnSubmittedNarrowsToVendorsListedInVendorIds(): void
    {
        // Order has items from two vendors; the return only involves
        // one. Only THAT vendor should be emailed.
        $order = $this->makeOrder('V3-RET-002');
        $vendorA = $this->makeVendor(id: 101, email: 'vendorA@shops.com');
        $vendorB = $this->makeVendor(id: 202, email: 'vendorB@shops.com');
        $this->addItem($order, $vendorA, 'A item');
        $this->addItem($order, $vendorB, 'B item');

        $this->service->returnSubmitted($order, [
            'return_reference' => 'RET-8',
            'reason' => 'wrong_item',
            'returned_items' => ['A item'],
            'vendor_ids' => [101],  // only vendor A
        ]);

        self::assertSame(1, $this->mailer->countFor('vendorA@shops.com'));
        self::assertSame(0, $this->mailer->countFor('vendorB@shops.com'));
    }

    #[Test]
    public function returnSubmittedWithNoAdminRecipientsSkipsAdminSend(): void
    {
        $order = $this->makeOrder('V3-RET-003');
        $vendor = $this->makeVendor(id: 101, email: 'vendor101@shops.com');
        $this->addItem($order, $vendor, 'item');

        $this->serviceNoAdmin->returnSubmitted($order, [
            'return_reference' => 'RET-9',
            'reason' => 'defective',
            'returned_items' => ['item'],
            'vendor_ids' => [101],
        ]);

        // Customer + vendor sent; admin skipped.
        self::assertSame(2, count($this->mailer->sent()));
        self::assertSame(0, $this->mailer->countByTemplate(EmailTemplate::RETURN_SUBMITTED_ADMIN->value));
    }

    #[Test]
    public function returnSubmittedFallsBackToAllVendorsWhenVendorIdsOmitted(): void
    {
        // Defensive: a caller who forgets vendor_ids still gets the
        // vendor copy fired to every vendor on the order (matches
        // sendToVendors default behavior).
        $order = $this->makeOrder('V3-RET-004');
        $vendorA = $this->makeVendor(id: 101, email: 'vendorA@shops.com');
        $vendorB = $this->makeVendor(id: 202, email: 'vendorB@shops.com');
        $this->addItem($order, $vendorA, 'A');
        $this->addItem($order, $vendorB, 'B');

        $this->service->returnSubmitted($order, [
            'return_reference' => 'RET-10',
            'reason' => 'defective',
            // vendor_ids intentionally omitted
        ]);

        self::assertSame(1, $this->mailer->countFor('vendorA@shops.com'));
        self::assertSame(1, $this->mailer->countFor('vendorB@shops.com'));
    }

    // =================================================================
    // returnApproved / returnDenied / returnPickedUp / etc — customer only
    // =================================================================

    #[Test]
    public function returnApprovedSendsCustomerOnly(): void
    {
        $order = $this->makeOrder('V3-RET-100');
        $vendor = $this->makeVendor(id: 101, email: 'v@shops.com');
        $this->addItem($order, $vendor, 'item');

        $this->service->returnApproved($order, [
            'return_reference' => 'RET-7',
            'admin_notes' => 'photos check out',
        ]);

        self::assertCount(1, $this->mailer->sent());
        self::assertSame(1, $this->mailer->countByTemplate(EmailTemplate::RETURN_APPROVED_CUSTOMER->value));
        self::assertSame(1, $this->mailer->countFor('customer@example.com'));
    }

    #[Test]
    public function returnApprovedRendersAdminNotesInBody(): void
    {
        $order = $this->makeOrder('V3-RET-101');
        $this->addItem($order, $this->makeVendor(101, 'v@s.com'), 'thing');

        $this->service->returnApproved($order, [
            'return_reference' => 'RET-7',
            'admin_notes' => 'Photo evidence is clear',
        ]);

        $sent = $this->mailer->sent();
        self::assertStringContainsString('Photo evidence is clear', $sent[0]['text_body']);
        self::assertStringContainsString('RET-7', $sent[0]['text_body']);
    }

    #[Test]
    public function returnDeniedSendsCustomerOnlyWithNotes(): void
    {
        $order = $this->makeOrder('V3-RET-200');
        $this->addItem($order, $this->makeVendor(101, 'v@s.com'), 'thing');

        $this->service->returnDenied($order, [
            'return_reference' => 'RET-7',
            'admin_notes' => 'Items appear used; outside policy',
        ]);

        self::assertCount(1, $this->mailer->sent());
        self::assertSame(1, $this->mailer->countByTemplate(EmailTemplate::RETURN_DENIED_CUSTOMER->value));
        $sent = $this->mailer->sent();
        self::assertStringContainsString('Items appear used; outside policy', $sent[0]['text_body']);
    }

    #[Test]
    public function returnPickedUpSendsCustomerOnly(): void
    {
        $order = $this->makeOrder('V3-RET-300');
        $this->addItem($order, $this->makeVendor(101, 'v@s.com'), 'thing');

        $this->service->returnPickedUp($order, [
            'return_reference' => 'RET-7',
        ]);

        self::assertCount(1, $this->mailer->sent());
        self::assertSame(1, $this->mailer->countByTemplate(EmailTemplate::RETURN_PICKED_UP_CUSTOMER->value));
    }

    #[Test]
    public function returnReceivedByVendorSendsCustomerOnly(): void
    {
        $order = $this->makeOrder('V3-RET-400');
        $this->addItem($order, $this->makeVendor(101, 'v@s.com'), 'thing');

        $this->service->returnReceivedByVendor($order, [
            'return_reference' => 'RET-7',
        ]);

        self::assertCount(1, $this->mailer->sent());
        self::assertSame(
            1,
            $this->mailer->countByTemplate(EmailTemplate::RETURN_RECEIVED_BY_VENDOR_CUSTOMER->value),
        );
    }

    #[Test]
    public function returnRefundedSendsCustomerOnlyWithRefundDetails(): void
    {
        $order = $this->makeOrder('V3-RET-500');
        $this->addItem($order, $this->makeVendor(101, 'v@s.com'), 'thing');

        $this->service->returnRefunded($order, [
            'return_reference' => 'RET-7',
            'refund_amount' => '90.00',
            'refund_currency' => 'AED',
            'refund_method' => 'bank_transfer',
            'refund_reference' => 'BANK-12345',
        ]);

        self::assertCount(1, $this->mailer->sent());
        $sent = $this->mailer->sent()[0];
        self::assertStringContainsString('90.00', $sent['text_body']);
        self::assertStringContainsString('AED', $sent['text_body']);
        self::assertStringContainsString('bank_transfer', $sent['text_body']);
        self::assertStringContainsString('BANK-12345', $sent['text_body']);
    }

    // =================================================================
    // Locale routing — customer Arabic
    // =================================================================

    #[Test]
    public function arabicCustomerGetsArabicReturnSubmittedTemplate(): void
    {
        $order = $this->makeArabicOrder('V3-RET-AR-001');
        $vendor = $this->makeVendor(id: 101, email: 'v@s.com');
        $this->addItem($order, $vendor, 'بطارية');

        $this->service->returnSubmitted($order, [
            'return_reference' => 'RET-7',
            'reason' => 'defective',
            'returned_items' => ['بطارية'],
            'vendor_ids' => [101],
        ]);

        $customerEmail = $this->findSentTo('customer@example.com');
        self::assertNotNull($customerEmail);
        // Arabic subject token "تم استلام طلب إرجاع"
        self::assertStringContainsString('تم استلام طلب إرجاع', $customerEmail['subject']);
    }

    #[Test]
    public function englishCustomerGetsEnglishReturnApprovedTemplate(): void
    {
        $order = $this->makeOrder('V3-RET-EN-001');
        $this->addItem($order, $this->makeVendor(101, 'v@s.com'), 'item');

        $this->service->returnApproved($order, [
            'return_reference' => 'RET-7',
            'admin_notes' => 'ok',
        ]);

        $sent = $this->mailer->sent()[0];
        self::assertStringContainsString('Return approved', $sent['subject']);
    }

    #[Test]
    public function adminTemplateAlwaysEnglishEvenWhenCustomerArabic(): void
    {
        // Q-VendorAdminLocale = A locked. Admin recipient gets English
        // regardless of customer locale.
        $order = $this->makeArabicOrder('V3-RET-AR-002');
        $vendor = $this->makeVendor(101, 'v@s.com');
        $this->addItem($order, $vendor, 'item');

        $this->service->returnSubmitted($order, [
            'return_reference' => 'RET-7',
            'reason' => 'defective',
            'returned_items' => ['item'],
            'vendor_ids' => [101],
        ]);

        $adminEmail = $this->findSentTo('ops@3bayti.ae');
        self::assertNotNull($adminEmail);
        self::assertStringContainsString('[ACTION] New return request', $adminEmail['subject']);
    }

    // =================================================================
    // Renderer — direct template coverage
    // =================================================================

    #[Test]
    public function rendererProducesAllReturnTemplateVariants(): void
    {
        // Exercise every match arm to lock in coverage of the
        // 14 new render methods (6 customer × 2 langs + vendor × 2 + admin).
        $renderer = new OrderEmailTemplateRenderer();
        $order = $this->makeOrder('V3-COV-001');
        $this->addItem($order, $this->makeVendor(101, 'v@s.com'), 'thing');

        $extra = [
            'return_reference' => 'RET-7',
            'reason' => 'defective',
            'customer_notes' => 'broken',
            'admin_notes' => 'reviewed',
            'returned_items' => ['thing'],
            'refund_amount' => '99.00',
            'refund_currency' => 'AED',
            'refund_method' => 'cash',
            'refund_reference' => 'CASH-1',
        ];

        // English variants — 6 customer + 1 vendor + 1 admin
        $en = User::LOCALE_EN;
        $renderer->render(EmailTemplate::RETURN_SUBMITTED_CUSTOMER, $order, $extra, $en);
        $renderer->render(EmailTemplate::RETURN_APPROVED_CUSTOMER, $order, $extra, $en);
        $renderer->render(EmailTemplate::RETURN_DENIED_CUSTOMER, $order, $extra, $en);
        $renderer->render(EmailTemplate::RETURN_PICKED_UP_CUSTOMER, $order, $extra, $en);
        $renderer->render(EmailTemplate::RETURN_RECEIVED_BY_VENDOR_CUSTOMER, $order, $extra, $en);
        $renderer->render(EmailTemplate::RETURN_REFUNDED_CUSTOMER, $order, $extra, $en);
        $renderer->render(EmailTemplate::RETURN_SUBMITTED_VENDOR, $order, $extra, $en);
        $renderer->render(EmailTemplate::RETURN_SUBMITTED_ADMIN, $order, $extra, $en);

        // Arabic variants — 6 customer + 1 vendor (admin ignores locale)
        $ar = User::LOCALE_AR;
        $renderer->render(EmailTemplate::RETURN_SUBMITTED_CUSTOMER, $order, $extra, $ar);
        $renderer->render(EmailTemplate::RETURN_APPROVED_CUSTOMER, $order, $extra, $ar);
        $renderer->render(EmailTemplate::RETURN_DENIED_CUSTOMER, $order, $extra, $ar);
        $renderer->render(EmailTemplate::RETURN_PICKED_UP_CUSTOMER, $order, $extra, $ar);
        $renderer->render(EmailTemplate::RETURN_RECEIVED_BY_VENDOR_CUSTOMER, $order, $extra, $ar);
        $renderer->render(EmailTemplate::RETURN_REFUNDED_CUSTOMER, $order, $extra, $ar);
        $renderer->render(EmailTemplate::RETURN_SUBMITTED_VENDOR, $order, $extra, $ar);

        // Admin template forced English even when locale='ar'
        $adminRendered = $renderer->render(EmailTemplate::RETURN_SUBMITTED_ADMIN, $order, $extra, $ar);
        self::assertStringContainsString('[ACTION]', $adminRendered->subject);

        // If we got here without exceptions, all 15 invocations rendered cleanly.
        self::assertTrue(true);
    }

    #[Test]
    public function rendererHandlesMissingReturnedItemsGracefully(): void
    {
        // Defensive: if a caller forgets to pass returned_items the
        // template falls back to a placeholder rather than crashing.
        $renderer = new OrderEmailTemplateRenderer();
        $order = $this->makeOrder('V3-COV-002');
        $this->addItem($order, $this->makeVendor(101, 'v@s.com'), 'x');

        $rendered = $renderer->render(
            EmailTemplate::RETURN_SUBMITTED_CUSTOMER,
            $order,
            ['return_reference' => 'RET-7', 'reason' => 'defective'],
        );

        self::assertStringContainsString('(item list unavailable)', $rendered->textBody);
    }

    #[Test]
    public function notificationFailureIsLoggedNotPropagated(): void
    {
        // The service swallows mailer exceptions. Use a mailer that
        // always throws and verify the call still returns.
        $throwingMailer = new class implements \Bayti\Api\Notification\MailerInterface {
            public function send(
                string $to,
                string $subject,
                string $textBody,
                string $htmlBody,
                array $context = [],
            ): void {
                throw new \Bayti\Api\Notification\MailerException(
                    kind: 'transport_error',
                    message: 'simulated',
                );
            }
        };

        $service = new OrderNotificationService(
            mailer: $throwingMailer,
            renderer: new OrderEmailTemplateRenderer(),
            adminRecipients: ['ops@3bayti.ae'],
            logger: new NullLogger(),
        );

        $order = $this->makeOrder('V3-RET-FAIL');
        $this->addItem($order, $this->makeVendor(101, 'v@s.com'), 'item');

        // Should NOT throw.
        $service->returnApproved($order, ['return_reference' => 'RET-7']);
        self::assertTrue(true, 'returnApproved swallowed mailer exception');
    }

    // =================================================================
    // Helpers
    // =================================================================

    private function makeOrder(string $reference): Order
    {
        $user = $this->makeUser('customer@example.com');
        $order = new Order(user: $user, orderReference: $reference, subtotal: '99.00');
        $this->setEntityId($order, random_int(100, 999));
        return $order;
    }

    private function makeArabicOrder(string $reference): Order
    {
        $user = $this->makeUser('customer@example.com');
        $user->setLocale('ar');
        $order = new Order(user: $user, orderReference: $reference, subtotal: '99.00');
        $this->setEntityId($order, random_int(100, 999));
        return $order;
    }

    private function makeUser(string $email): User
    {
        $u = new User($email, '+971501234567', password_hash('p', PASSWORD_BCRYPT), 'AE');
        $this->setEntityId($u, 42);
        return $u;
    }

    private function makeVendor(int $id, string $email): Vendor
    {
        $v = (new \ReflectionClass(Vendor::class))->newInstanceWithoutConstructor();
        $this->setEntityProp($v, 'id', $id);
        $this->setEntityProp($v, 'name', "Vendor {$id}");
        $this->setEntityProp($v, 'contactEmail', $email);
        return $v;
    }

    private function addItem(Order $order, Vendor $vendor, string $name): void
    {
        $product = (new \ReflectionClass(Product::class))->newInstanceWithoutConstructor();
        $this->setEntityProp($product, 'id', random_int(200, 999));
        $this->setEntityProp($product, 'name', $name);
        $this->setEntityProp($product, 'vendor', $vendor);

        $item = new OrderItem(
            product: $product, vendor: $vendor,
            quantity: 1, unitPrice: '99.00',
            productNameSnapshot: $name,
            productImageSnapshot: 'cdn/x.jpg',
        );
        $this->setEntityId($item, random_int(500, 999));
        $order->addItem($item);
    }

    /**
     * @return array<string, mixed>|null
     */
    private function findSentTo(string $recipient): ?array
    {
        foreach ($this->mailer->sent() as $email) {
            if ($email['to'] === $recipient) {
                return $email;
            }
        }
        return null;
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
