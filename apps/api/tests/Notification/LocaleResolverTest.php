<?php

declare(strict_types=1);

namespace Bayti\Api\Tests\Notification;

use Bayti\Api\Domain\Catalog\Product;
use Bayti\Api\Domain\Catalog\Vendor;
use Bayti\Api\Domain\Order\Order;
use Bayti\Api\Domain\Order\OrderItem;
use Bayti\Api\Domain\User\User;
use Bayti\Api\Notification\LocaleResolver;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Coverage for LocaleResolver (M3.2.X.7-A).
 *
 * Locks the 4-branch decision tree (§2 of M3.2.X.7 plan):
 *   1. Customer email match → User.preferredLocale
 *   2. Vendor email match → Vendor.preferredLocale
 *   3. Admin recipient match → always en
 *   4. Default → en
 *
 * Each branch tested in isolation + cross-cutting fallback behavior.
 */
#[CoversClass(LocaleResolver::class)]
final class LocaleResolverTest extends TestCase
{
    private LocaleResolver $resolver;

    protected function setUp(): void
    {
        $this->resolver = new LocaleResolver();
    }

    #[Test]
    public function defaultLocaleIsEnglish(): void
    {
        self::assertSame('en', LocaleResolver::DEFAULT_LOCALE);
    }

    // -----------------------------------------------------------------
    // Branch 1: Customer email match
    // -----------------------------------------------------------------

    #[Test]
    public function customerWithExplicitArabicPreferenceGetsArabic(): void
    {
        $order = $this->makeOrder(customerEmail: 'alice@example.com');
        $order->getUser()->setPreferredLocale('ar');

        $locale = $this->resolver->resolveForRecipient(
            recipientEmail: 'alice@example.com',
            order: $order,
        );

        self::assertSame('ar', $locale);
    }

    #[Test]
    public function customerWithExplicitEnglishPreferenceGetsEnglish(): void
    {
        $order = $this->makeOrder(customerEmail: 'alice@example.com');
        $order->getUser()->setPreferredLocale('en');

        $locale = $this->resolver->resolveForRecipient(
            recipientEmail: 'alice@example.com',
            order: $order,
        );

        self::assertSame('en', $locale);
    }

    #[Test]
    public function customerWithNullPreferenceFallsBackToEnglish(): void
    {
        // Q-FallbackBehavior = A locked: null preference → English
        $order = $this->makeOrder(customerEmail: 'alice@example.com');
        // No setPreferredLocale call; stays null

        $locale = $this->resolver->resolveForRecipient(
            recipientEmail: 'alice@example.com',
            order: $order,
        );

        self::assertSame('en', $locale);
    }

    // -----------------------------------------------------------------
    // Branch 2: Vendor email match
    // -----------------------------------------------------------------

    #[Test]
    public function vendorWithArabicPreferenceGetsArabic(): void
    {
        $order = $this->makeOrder();
        $vendor = $this->makeVendor(id: 5, email: 'vendor@shops.com');
        $vendor->setPreferredLocale('ar');
        $this->addItem($order, $vendor, 'Item A');

        $locale = $this->resolver->resolveForRecipient(
            recipientEmail: 'vendor@shops.com',
            order: $order,
        );

        self::assertSame('ar', $locale);
    }

    #[Test]
    public function vendorWithNullPreferenceFallsBackToEnglish(): void
    {
        $order = $this->makeOrder();
        $vendor = $this->makeVendor(id: 5, email: 'vendor@shops.com');
        // No preferredLocale set; defaults to English
        $this->addItem($order, $vendor, 'Item A');

        $locale = $this->resolver->resolveForRecipient(
            recipientEmail: 'vendor@shops.com',
            order: $order,
        );

        self::assertSame('en', $locale);
    }

    #[Test]
    public function vendorMatchAmongMultipleVendorsReturnsThatVendorsLocale(): void
    {
        // Multi-vendor order: each vendor has distinct preferences.
        // The resolver should match the SPECIFIC recipient's vendor.
        $order = $this->makeOrder();
        $vendorAr = $this->makeVendor(id: 5, email: 'vendor-ar@shops.com');
        $vendorAr->setPreferredLocale('ar');
        $vendorEn = $this->makeVendor(id: 6, email: 'vendor-en@shops.com');
        $vendorEn->setPreferredLocale('en');
        $this->addItem($order, $vendorAr, 'Item A');
        $this->addItem($order, $vendorEn, 'Item B');

        // Send to the Arabic-preferring vendor → Arabic
        self::assertSame(
            'ar',
            $this->resolver->resolveForRecipient('vendor-ar@shops.com', $order),
        );
        // Send to the English-preferring vendor → English
        self::assertSame(
            'en',
            $this->resolver->resolveForRecipient('vendor-en@shops.com', $order),
        );
    }

    // -----------------------------------------------------------------
    // Branch 3: Admin recipient match — locked to English
    // -----------------------------------------------------------------

    #[Test]
    public function adminRecipientAlwaysGetsEnglish(): void
    {
        // Q-VendorAdminLocale = A locked: admin emails always English
        // regardless of any other preference state.
        $order = $this->makeOrder(customerEmail: 'alice@example.com');
        // Customer prefers Arabic — but admin is the recipient, not customer
        $order->getUser()->setPreferredLocale('ar');

        $locale = $this->resolver->resolveForRecipient(
            recipientEmail: 'ops@3bayti.ae',
            order: $order,
            adminRecipients: ['ops@3bayti.ae'],
        );

        self::assertSame('en', $locale);
    }

    #[Test]
    public function multipleAdminRecipientsAllGetEnglish(): void
    {
        $order = $this->makeOrder();

        $adminRecipients = ['ops@3bayti.ae', 'finance@3bayti.ae'];

        foreach ($adminRecipients as $admin) {
            self::assertSame(
                'en',
                $this->resolver->resolveForRecipient($admin, $order, $adminRecipients),
            );
        }
    }

    // -----------------------------------------------------------------
    // Branch 4: Unknown recipient — fail safe to English
    // -----------------------------------------------------------------

    #[Test]
    public function unknownRecipientGetsEnglish(): void
    {
        $order = $this->makeOrder(customerEmail: 'alice@example.com');
        $order->getUser()->setPreferredLocale('ar');

        // Recipient not in customer, vendor, or admin lists
        $locale = $this->resolver->resolveForRecipient(
            recipientEmail: 'random@unknown.com',
            order: $order,
            adminRecipients: ['ops@3bayti.ae'],
        );

        self::assertSame(
            'en',
            $locale,
            'Unknown recipient fails safe to English (Q-FallbackBehavior = A)',
        );
    }

    #[Test]
    public function customerWithEmptyEmailDoesNotMatchAnyRecipient(): void
    {
        // Edge case: customer email is empty string. Recipient that
        // happens to be empty string should NOT match the customer
        // branch (would be a false positive).
        $order = $this->makeOrderWithEmptyEmail();

        $locale = $this->resolver->resolveForRecipient(
            recipientEmail: '',
            order: $order,
        );

        self::assertSame('en', $locale, 'Empty recipient does not match empty customer email');
    }

    #[Test]
    public function customerBranchPreemptsVendorBranch(): void
    {
        // Edge case: customer and vendor have the same email address.
        // The customer branch fires first by §2 decision tree order.
        $order = $this->makeOrder(customerEmail: 'shared@example.com');
        $order->getUser()->setPreferredLocale('en');

        $vendor = $this->makeVendor(id: 5, email: 'shared@example.com');
        $vendor->setPreferredLocale('ar');
        $this->addItem($order, $vendor, 'Item A');

        $locale = $this->resolver->resolveForRecipient(
            recipientEmail: 'shared@example.com',
            order: $order,
        );

        self::assertSame(
            'en',
            $locale,
            'Customer branch (Step 1) fires before vendor branch (Step 2)',
        );
    }

    // ===== Helpers =====

    private function makeOrder(string $customerEmail = 'customer@example.com'): Order
    {
        $user = new User(
            $customerEmail,
            '+971501234567',
            password_hash('p', PASSWORD_BCRYPT),
            'AE',
        );
        $this->setEntityId($user, 42);
        $order = new Order(user: $user, orderReference: 'V3-TEST-LOC', subtotal: '99.00');
        $this->setEntityId($order, 100);
        return $order;
    }

    private function makeOrderWithEmptyEmail(): Order
    {
        $user = (new \ReflectionClass(User::class))->newInstanceWithoutConstructor();
        $this->setEntityProp($user, 'id', 42);
        $this->setEntityProp($user, 'email', '');
        $order = new Order(user: $user, orderReference: 'V3-TEST-EMPTY', subtotal: '99.00');
        $this->setEntityId($order, 100);
        return $order;
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
