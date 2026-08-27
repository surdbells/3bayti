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
 * Coverage for LocaleResolver (M3.2.X.7-A, refactored in -D for
 * Q-Unification: customer side reads existing User.locale field
 * with normalization to short tag; vendor side reads new
 * Vendor.preferredLocale field).
 *
 * Locks the 4-branch decision tree (§2 of M3.2.X.7 plan):
 *   1. Customer email match → normalize(User.locale)
 *   2. Vendor email match → Vendor.preferredLocale (or default)
 *   3. Admin recipient match → always en
 *   4. Default → en
 *
 * Each branch tested in isolation + normalization edge cases +
 * cross-cutting fallback behavior.
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
    // normalizeToShortTag, direct unit coverage
    // -----------------------------------------------------------------

    #[Test]
    public function normalizeShortTagPassesThroughSupportedShortTags(): void
    {
        self::assertSame('en', LocaleResolver::normalizeToShortTag('en'));
        self::assertSame('ar', LocaleResolver::normalizeToShortTag('ar'));
    }

    #[Test]
    public function normalizeShortTagStripsRegionFromBcp47(): void
    {
        // Region-tagged locales are common in User.locale due to
        // M1.7.0's docblock noting 'en', 'ar', 'en-AE', 'ar-AE' as
        // valid. Strip region to get the renderer's expected form.
        self::assertSame('en', LocaleResolver::normalizeToShortTag('en-AE'));
        self::assertSame('ar', LocaleResolver::normalizeToShortTag('ar-AE'));
        self::assertSame('en', LocaleResolver::normalizeToShortTag('en-US'));
        self::assertSame('ar', LocaleResolver::normalizeToShortTag('ar-SA'));
    }

    #[Test]
    public function normalizeShortTagHandlesCaseInsensitively(): void
    {
        // Defensive: BCP-47 is case-insensitive for the primary
        // subtag. Some external sources may pass 'EN' or 'En'.
        self::assertSame('en', LocaleResolver::normalizeToShortTag('EN'));
        self::assertSame('ar', LocaleResolver::normalizeToShortTag('Ar'));
        self::assertSame('en', LocaleResolver::normalizeToShortTag('EN-AE'));
    }

    #[Test]
    public function normalizeShortTagFailsSafeOnUnsupportedLanguage(): void
    {
        // Unsupported language → fall back to English (not throw).
        // Catches misconfigured users without breaking the email send.
        self::assertSame('en', LocaleResolver::normalizeToShortTag('fr'));
        self::assertSame('en', LocaleResolver::normalizeToShortTag('hi'));
        self::assertSame('en', LocaleResolver::normalizeToShortTag('ur-PK'));
    }

    #[Test]
    public function normalizeShortTagFailsSafeOnEmptyOrNull(): void
    {
        self::assertSame('en', LocaleResolver::normalizeToShortTag(''));
        self::assertSame('en', LocaleResolver::normalizeToShortTag(null));
    }

    // -----------------------------------------------------------------
    // Branch 1: Customer email match (reads User.locale)
    // -----------------------------------------------------------------

    #[Test]
    public function customerWithArabicLocaleGetsArabic(): void
    {
        $order = $this->makeOrder(customerEmail: 'alice@example.com');
        $order->getUser()->setLocale('ar');

        $locale = $this->resolver->resolveForRecipient(
            recipientEmail: 'alice@example.com',
            order: $order,
        );

        self::assertSame('ar', $locale);
    }

    #[Test]
    public function customerWithArabicRegionTaggedLocaleGetsArabic(): void
    {
        // Existing users may have 'ar-AE' in their locale field
        // (M1.7.0 allowed it). The resolver strips the region.
        $order = $this->makeOrder(customerEmail: 'alice@example.com');
        $order->getUser()->setLocale('ar-AE');

        $locale = $this->resolver->resolveForRecipient(
            recipientEmail: 'alice@example.com',
            order: $order,
        );

        self::assertSame('ar', $locale);
    }

    #[Test]
    public function customerWithEnglishLocaleGetsEnglish(): void
    {
        $order = $this->makeOrder(customerEmail: 'alice@example.com');
        $order->getUser()->setLocale('en');

        $locale = $this->resolver->resolveForRecipient(
            recipientEmail: 'alice@example.com',
            order: $order,
        );

        self::assertSame('en', $locale);
    }

    #[Test]
    public function customerWithDefaultEnLocaleGetsEnglish(): void
    {
        // User created without explicit locale gets User.locale='en'
        // by constructor default (M1.7.0 ORM column DEFAULT 'en').
        $order = $this->makeOrder(customerEmail: 'alice@example.com');
        // No setLocale call; field default = 'en'
        self::assertSame('en', $order->getUser()->getLocale());

        $locale = $this->resolver->resolveForRecipient(
            recipientEmail: 'alice@example.com',
            order: $order,
        );

        self::assertSame('en', $locale);
    }

    // -----------------------------------------------------------------
    // Branch 2: Vendor email match (reads Vendor.preferredLocale)
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
    // Branch 3: Admin recipient match, locked to English
    // -----------------------------------------------------------------

    #[Test]
    public function adminRecipientAlwaysGetsEnglish(): void
    {
        // Q-VendorAdminLocale = A locked: admin emails always English
        // regardless of any other preference state.
        $order = $this->makeOrder(customerEmail: 'alice@example.com');
        // Customer prefers Arabic, but admin is the recipient
        $order->getUser()->setLocale('ar');

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
    // Branch 4: Unknown recipient, fail safe to English
    // -----------------------------------------------------------------

    #[Test]
    public function unknownRecipientGetsEnglish(): void
    {
        $order = $this->makeOrder(customerEmail: 'alice@example.com');
        $order->getUser()->setLocale('ar');

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
        $order->getUser()->setLocale('en');

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
        $this->setEntityProp($user, 'locale', 'en');
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
