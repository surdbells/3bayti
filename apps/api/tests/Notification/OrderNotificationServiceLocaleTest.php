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
 * End-to-end locale routing for OrderNotificationService (M3.2.X.7-B).
 *
 * Verifies that when a customer/vendor has preferredLocale=ar, the
 * full notification pipeline routes through LocaleResolver and the
 * Arabic templates, with the InMemoryMailer capturing the final
 * rendered output.
 *
 * This is the integration-level test that proves the wiring works
 * end-to-end. Renderer-level dispatch is covered by
 * OrderEmailTemplateRendererLocaleTest; resolver branching by
 * LocaleResolverTest. This file covers the seam between them
 * inside OrderNotificationService::safeSend.
 */
#[CoversClass(OrderNotificationService::class)]
#[CoversClass(LocaleResolver::class)]
final class OrderNotificationServiceLocaleTest extends TestCase
{
    private InMemoryMailer $mailer;
    private OrderNotificationService $service;

    protected function setUp(): void
    {
        $this->mailer = new InMemoryMailer();
        $this->service = new OrderNotificationService(
            mailer: $this->mailer,
            renderer: new OrderEmailTemplateRenderer(),
            adminRecipients: ['ops@3bayti.ae'],
            logger: new NullLogger(),
            em: null,
            localeResolver: new LocaleResolver(),
        );
    }

    #[Test]
    public function customerWithArabicPreferenceGetsArabicEmail(): void
    {
        $order = $this->makeOrder('V3-AR-1', customerLocale: 'ar');
        $vendor = $this->makeVendor(id: 5, email: 'v1@shops.com');
        $this->addItem($order, $vendor, 'Item A');

        $this->service->orderPlaced($order);

        $customerEmail = $this->findSentTo('customer@example.com');
        self::assertNotNull($customerEmail);

        // Subject contains the Arabic phrase for ORDER_PLACED_CUSTOMER
        self::assertStringContainsString('تم استلام طلبك', $customerEmail['subject']);
        // HTML body has Arabic wrapper
        self::assertStringContainsString('<html lang="ar" dir="rtl">', $customerEmail['html_body']);
    }

    #[Test]
    public function customerWithNullPreferenceGetsEnglishEmail(): void
    {
        $order = $this->makeOrder('V3-EN-1', customerLocale: null);
        $vendor = $this->makeVendor(id: 5, email: 'v1@shops.com');
        $this->addItem($order, $vendor, 'Item A');

        $this->service->orderPlaced($order);

        $customerEmail = $this->findSentTo('customer@example.com');
        self::assertNotNull($customerEmail);

        // English subject (existing template)
        self::assertStringContainsString('Order V3-EN-1 received', $customerEmail['subject']);
        self::assertStringContainsString('<html lang="en" dir="ltr">', $customerEmail['html_body']);
    }

    #[Test]
    public function vendorWithArabicPreferenceGetsArabicEmail(): void
    {
        // Customer is English; vendor is Arabic. Each recipient gets
        // their OWN locale — the resolver picks per-recipient.
        $order = $this->makeOrder('V3-MIX-1', customerLocale: 'en');
        $vendor = $this->makeVendor(id: 5, email: 'v1@shops.com', vendorLocale: 'ar');
        $this->addItem($order, $vendor, 'Item A');

        $this->service->orderPlaced($order);

        // Customer email → English
        $customerEmail = $this->findSentTo('customer@example.com');
        self::assertNotNull($customerEmail);
        self::assertStringContainsString('Order V3-MIX-1 received', $customerEmail['subject']);

        // Vendor email → Arabic
        $vendorEmail = $this->findSentTo('v1@shops.com');
        self::assertNotNull($vendorEmail);
        self::assertStringContainsString('طلب جديد', $vendorEmail['subject']);
        self::assertStringContainsString('<html lang="ar" dir="rtl">', $vendorEmail['html_body']);
    }

    #[Test]
    public function adminRecipientAlwaysGetsEnglishEvenWhenCustomerArabic(): void
    {
        // Q-VendorAdminLocale = A locked: even if customer prefers
        // Arabic, dispute alerts sent to ops@3bayti.ae stay English.
        $order = $this->makeOrder('V3-ADM-1', customerLocale: 'ar');
        $vendor = $this->makeVendor(id: 5, email: 'v1@shops.com', vendorLocale: 'ar');
        $this->addItem($order, $vendor, 'Item A');

        // Trigger a dispute notification (admin recipient)
        $this->service->disputeOpened(
            $order,
            details: [
                'event_type' => 'CHARGEBACK_OPENED',
                'reason' => 'test',
                'amount' => '99.00',
            ],
        );

        $adminEmail = $this->findSentTo('ops@3bayti.ae');
        self::assertNotNull($adminEmail);
        self::assertStringContainsString(
            'ALERT',
            $adminEmail['subject'],
            'Admin recipient must get English (admin emails locked to English regardless of locale)',
        );
        self::assertStringContainsString('<html lang="en" dir="ltr">', $adminEmail['html_body']);
    }

    #[Test]
    public function localeContextRecordedInMailerSendContext(): void
    {
        // The mailer's context dict receives the resolved locale, so
        // notification_logs (or any downstream consumer) can correlate
        // sends with their locale for observability.
        $order = $this->makeOrder('V3-CTX-1', customerLocale: 'ar');
        $vendor = $this->makeVendor(id: 5, email: 'v1@shops.com');
        $this->addItem($order, $vendor, 'Item A');

        $this->service->orderPlaced($order);

        $customerSend = $this->findSentTo('customer@example.com');
        self::assertNotNull($customerSend);
        self::assertSame('ar', $customerSend['context']['locale'] ?? null);
    }

    #[Test]
    public function serviceWithoutResolverFallsBackToEnglishOnlyBehavior(): void
    {
        // Backwards compat: a service constructed WITHOUT a
        // LocaleResolver (legacy DI / test setup) preserves the
        // pre-M3.2.X.7 English-only behavior — even for customers
        // who would otherwise prefer Arabic.
        $serviceNoResolver = new OrderNotificationService(
            mailer: $this->mailer,
            renderer: new OrderEmailTemplateRenderer(),
            adminRecipients: ['ops@3bayti.ae'],
            logger: new NullLogger(),
            em: null,
            // localeResolver intentionally omitted (null default)
        );

        $order = $this->makeOrder('V3-NO-RES', customerLocale: 'ar');
        $vendor = $this->makeVendor(id: 5, email: 'v1@shops.com');
        $this->addItem($order, $vendor, 'Item A');

        $serviceNoResolver->orderPlaced($order);

        $customerEmail = $this->findSentTo('customer@example.com');
        self::assertNotNull($customerEmail);
        // English subject — resolver absent means default 'en' path
        self::assertStringContainsString('Order V3-NO-RES received', $customerEmail['subject']);
        self::assertStringContainsString('<html lang="en" dir="ltr">', $customerEmail['html_body']);
    }

    /**
     * Find the most recent sent record addressed to a specific
     * recipient. Returns null if no such record exists.
     *
     * @return array<string, mixed>|null
     */
    private function findSentTo(string $email): ?array
    {
        $matches = array_filter(
            $this->mailer->sent(),
            static fn (array $r): bool => ($r['to'] ?? null) === $email,
        );
        if (count($matches) === 0) {
            return null;
        }
        return end($matches);
    }

    // ===== Helpers =====

    private function makeOrder(string $reference, ?string $customerLocale = null): Order
    {
        $user = new User(
            'customer@example.com',
            '+971501234567',
            password_hash('p', PASSWORD_BCRYPT),
            'AE',
        );
        $this->setProp($user, 'id', 42);
        if ($customerLocale !== null) {
            // M3.2.X.7-D Q-Unification: customer locale lives on
            // User.locale (the existing M1.7.0 field), not a separate
            // preferred_locale column. The resolver normalizes to a
            // short tag.
            $user->setLocale($customerLocale);
        }

        $order = new Order(user: $user, orderReference: $reference, subtotal: '99.00');
        $this->setProp($order, 'id', 100);
        return $order;
    }

    private function makeVendor(int $id, string $email, ?string $vendorLocale = null): Vendor
    {
        $v = (new \ReflectionClass(Vendor::class))->newInstanceWithoutConstructor();
        $this->setProp($v, 'id', $id);
        $this->setProp($v, 'name', "Vendor {$id}");
        $this->setProp($v, 'contactEmail', $email);
        if ($vendorLocale !== null) {
            $v->setPreferredLocale($vendorLocale);
        }
        return $v;
    }

    private function addItem(Order $order, Vendor $vendor, string $name): void
    {
        $product = (new \ReflectionClass(Product::class))->newInstanceWithoutConstructor();
        $this->setProp($product, 'id', random_int(200, 999));
        $this->setProp($product, 'name', $name);
        $this->setProp($product, 'vendor', $vendor);

        $item = new OrderItem(
            product: $product, vendor: $vendor,
            quantity: 1, unitPrice: '99.00',
            productNameSnapshot: $name,
            productImageSnapshot: 'cdn/x.jpg',
        );
        $this->setProp($item, 'id', random_int(500, 999));
        $order->addItem($item);
    }

    private function setProp(object $entity, string $prop, mixed $value): void
    {
        $ref = new \ReflectionProperty($entity::class, $prop);
        $ref->setAccessible(true);
        $ref->setValue($entity, $value);
    }
}
