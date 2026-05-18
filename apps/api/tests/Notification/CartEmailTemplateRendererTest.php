<?php

declare(strict_types=1);

namespace Bayti\Api\Tests\Notification;

use Bayti\Api\Domain\Cart\Cart;
use Bayti\Api\Domain\Cart\CartItem;
use Bayti\Api\Domain\Catalog\Product;
use Bayti\Api\Domain\User\User;
use Bayti\Api\Notification\CartEmailTemplateRenderer;
use Bayti\Api\Notification\EmailTemplate;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for CartEmailTemplateRenderer (M3.2.X.11-D).
 *
 * Covers:
 *   - English + Arabic dispatch
 *   - Item list rendering (text + HTML)
 *   - Singular vs plural noun handling
 *   - unsubscribe_url presence (required for legal compliance)
 *   - resume_url optional + CTA button render
 *   - HTML escaping (XSS defense in user-supplied product names)
 *   - RTL HTML wrap for Arabic
 *   - LogicException on non-cart template routing (regression guard)
 */
#[CoversClass(CartEmailTemplateRenderer::class)]
final class CartEmailTemplateRendererTest extends TestCase
{
    private CartEmailTemplateRenderer $renderer;

    protected function setUp(): void
    {
        $this->renderer = new CartEmailTemplateRenderer();
    }

    // =================================================================
    // English
    // =================================================================

    #[Test]
    public function englishSingleItemRendersCorrectly(): void
    {
        $cart = $this->makeCart(items: [['name' => 'Vintage Lamp', 'qty' => 1]]);

        $rendered = $this->renderer->render(
            EmailTemplate::CART_ABANDONED_CUSTOMER,
            $cart,
            ['unsubscribe_url' => 'https://3bayti.ae/unsubscribe?token=abc'],
        );

        // Subject: singular 'item'
        self::assertStringContainsString('left 1 item', $rendered->subject);
        self::assertStringContainsString('3bayti', $rendered->subject);

        // Text body has the item
        self::assertStringContainsString('Vintage Lamp x 1', $rendered->textBody);
        // Unsubscribe link present
        self::assertStringContainsString('https://3bayti.ae/unsubscribe?token=abc', $rendered->textBody);

        // HTML body has the item + opening tag
        self::assertStringContainsString('Vintage Lamp', $rendered->htmlBody);
        self::assertStringContainsString('<html lang="en" dir="ltr">', $rendered->htmlBody);
    }

    #[Test]
    public function englishMultipleItemsUsesPluralNoun(): void
    {
        $cart = $this->makeCart(items: [
            ['name' => 'Item A', 'qty' => 1],
            ['name' => 'Item B', 'qty' => 2],
        ]);

        $rendered = $this->renderer->render(
            EmailTemplate::CART_ABANDONED_CUSTOMER,
            $cart,
            ['unsubscribe_url' => 'https://3bayti.ae/u/x'],
        );

        self::assertStringContainsString('left 2 items', $rendered->subject);
        self::assertStringContainsString('Item A x 1', $rendered->textBody);
        self::assertStringContainsString('Item B x 2', $rendered->textBody);
    }

    #[Test]
    public function englishResumeUrlRendersCTAButton(): void
    {
        $cart = $this->makeCart(items: [['name' => 'Item', 'qty' => 1]]);

        $rendered = $this->renderer->render(
            EmailTemplate::CART_ABANDONED_CUSTOMER,
            $cart,
            [
                'unsubscribe_url' => 'https://3bayti.ae/u/x',
                'resume_url' => 'https://3bayti.ae/cart?cart_id=42',
            ],
        );

        // Text body has resume link
        self::assertStringContainsString('Resume your cart: https://3bayti.ae/cart?cart_id=42', $rendered->textBody);
        // HTML body has the styled CTA button
        self::assertStringContainsString('Resume Your Cart</a>', $rendered->htmlBody);
        self::assertStringContainsString('https://3bayti.ae/cart?cart_id=42', $rendered->htmlBody);
    }

    #[Test]
    public function englishWithoutResumeUrlOmitsCTA(): void
    {
        $cart = $this->makeCart(items: [['name' => 'Item', 'qty' => 1]]);

        $rendered = $this->renderer->render(
            EmailTemplate::CART_ABANDONED_CUSTOMER,
            $cart,
            ['unsubscribe_url' => 'https://3bayti.ae/u/x'],
        );

        self::assertStringNotContainsString('Resume Your Cart', $rendered->htmlBody);
        self::assertStringNotContainsString('Resume your cart:', $rendered->textBody);
    }

    // =================================================================
    // Arabic
    // =================================================================

    #[Test]
    public function arabicRendersWithRtl(): void
    {
        $cart = $this->makeCart(items: [['name' => 'Vintage Lamp', 'qty' => 1]]);

        $rendered = $this->renderer->render(
            EmailTemplate::CART_ABANDONED_CUSTOMER,
            $cart,
            ['unsubscribe_url' => 'https://3bayti.ae/u/x'],
            locale: User::LOCALE_AR,
        );

        // Subject in Arabic
        self::assertStringContainsString('تركت', $rendered->subject);
        self::assertStringContainsString('1', $rendered->subject);
        self::assertStringContainsString('منتج', $rendered->subject);

        // HTML wrapped with RTL direction
        self::assertStringContainsString('<html lang="ar" dir="rtl">', $rendered->htmlBody);
    }

    #[Test]
    public function arabicMultipleItemsPlural(): void
    {
        $cart = $this->makeCart(items: [
            ['name' => 'Item A', 'qty' => 1],
            ['name' => 'Item B', 'qty' => 2],
        ]);

        $rendered = $this->renderer->render(
            EmailTemplate::CART_ABANDONED_CUSTOMER,
            $cart,
            ['unsubscribe_url' => 'https://3bayti.ae/u/x'],
            locale: User::LOCALE_AR,
        );

        // Subject uses plural 'منتجات' not 'منتج'
        self::assertStringContainsString('2 منتجات', $rendered->subject);
    }

    #[Test]
    public function arabicResumeUrlRendersArabicCTA(): void
    {
        $cart = $this->makeCart(items: [['name' => 'Item', 'qty' => 1]]);

        $rendered = $this->renderer->render(
            EmailTemplate::CART_ABANDONED_CUSTOMER,
            $cart,
            [
                'unsubscribe_url' => 'https://3bayti.ae/u/x',
                'resume_url' => 'https://3bayti.ae/cart',
            ],
            locale: User::LOCALE_AR,
        );

        // Arabic CTA text
        self::assertStringContainsString('استأنف سلتك</a>', $rendered->htmlBody);
    }

    // =================================================================
    // Security + safety
    // =================================================================

    #[Test]
    public function htmlEscapesProductNames(): void
    {
        // Defense against stored-XSS via a malicious product name
        $cart = $this->makeCart(items: [
            ['name' => '<script>alert(1)</script>Lamp', 'qty' => 1],
        ]);

        $rendered = $this->renderer->render(
            EmailTemplate::CART_ABANDONED_CUSTOMER,
            $cart,
            ['unsubscribe_url' => 'https://3bayti.ae/u/x'],
        );

        // HTML body must NOT contain the raw <script> tag
        self::assertStringNotContainsString('<script>', $rendered->htmlBody);
        // Should contain the HTML-escaped form
        self::assertStringContainsString('&lt;script&gt;', $rendered->htmlBody);

        // Text body preserves the raw text (it's not HTML; not an XSS vector
        // in plain text emails)
        self::assertStringContainsString('<script>', $rendered->textBody);
    }

    #[Test]
    public function htmlEscapesUnsubscribeUrl(): void
    {
        $cart = $this->makeCart(items: [['name' => 'Item', 'qty' => 1]]);

        $rendered = $this->renderer->render(
            EmailTemplate::CART_ABANDONED_CUSTOMER,
            $cart,
            [
                // Defensive: the unsubscribe URL is generated by us so
                // shouldn't contain HTML, but we escape it anyway
                'unsubscribe_url' => 'https://3bayti.ae/u/x?token=a&b=c',
            ],
        );

        // The & in the URL is HTML-escaped to &amp; for the href attribute
        self::assertStringContainsString('a&amp;b=c', $rendered->htmlBody);
    }

    // =================================================================
    // Regression guard
    // =================================================================

    #[Test]
    public function nonCartTemplateThrows(): void
    {
        $cart = $this->makeCart(items: [['name' => 'Item', 'qty' => 1]]);

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('OrderEmailTemplateRenderer');

        $this->renderer->render(
            EmailTemplate::ORDER_PLACED_CUSTOMER,  // wrong renderer
            $cart,
            [],
        );
    }

    // =================================================================
    // Helpers
    // =================================================================

    /**
     * @param list<array{name: string, qty: int}> $items
     */
    private function makeCart(array $items): Cart
    {
        $user = (new \ReflectionClass(User::class))->newInstanceWithoutConstructor();
        $idRef = new \ReflectionProperty(User::class, 'id');
        $idRef->setAccessible(true);
        $idRef->setValue($user, 100);
        $emailRef = new \ReflectionProperty(User::class, 'email');
        $emailRef->setAccessible(true);
        $emailRef->setValue($user, 'customer@example.com');

        $cart = new Cart(user: $user);
        $idRef = new \ReflectionProperty(Cart::class, 'id');
        $idRef->setAccessible(true);
        $idRef->setValue($cart, 42);

        foreach ($items as $spec) {
            $product = $this->makeProduct($spec['name']);
            $item = new CartItem(
                product: $product,
                quantity: $spec['qty'],
                unitPriceSnapshot: '50.00',
            );
            $cart->addItem($item);
        }

        return $cart;
    }

    private function makeProduct(string $name): Product
    {
        $product = (new \ReflectionClass(Product::class))->newInstanceWithoutConstructor();
        $idRef = new \ReflectionProperty(Product::class, 'id');
        $idRef->setAccessible(true);
        $idRef->setValue($product, 200);
        $nameRef = new \ReflectionProperty(Product::class, 'name');
        $nameRef->setAccessible(true);
        $nameRef->setValue($product, $name);
        return $product;
    }
}
