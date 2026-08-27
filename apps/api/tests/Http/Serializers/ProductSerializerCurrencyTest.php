<?php

declare(strict_types=1);

namespace Bayti\Api\Tests\Http\Serializers;

use Bayti\Api\Domain\Catalog\Product;
use Bayti\Api\Domain\Catalog\Vendor;
use Bayti\Api\Domain\Currency\Currency;
use Bayti\Api\Domain\Currency\CurrencyConversionService;
use Bayti\Api\Domain\Currency\FxRate;
use Bayti\Api\Domain\Currency\FxRateRepository;
use Bayti\Api\Http\Middleware\CurrencyContextMiddleware;
use Bayti\Api\Http\Serializers\ProductSerializer;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Slim\Psr7\Factory\ServerRequestFactory;

/**
 * Unit tests for ProductSerializer currency awareness (M3.2.X.15-E).
 *
 * Verifies:
 *   - Default (no currency configured) emits the pre-X.15 single-
 *     amount AED shape (backward compatibility)
 *   - withDisplayCurrency(AED) is a no-op (same shape as default)
 *   - withDisplayCurrency(USD) emits dual-amount shape
 *   - configureFromRequest() pulls from the middleware attribute
 *   - Missing conversion service degrades to AED shape (defensive)
 *   - Sale prices convert too when set
 */
#[CoversClass(ProductSerializer::class)]
final class ProductSerializerCurrencyTest extends TestCase
{
    // =================================================================
    // Backward compatibility, pre-X.15 single-amount shape
    // =================================================================

    #[Test]
    public function defaultEmitsSingleAmountAedShape(): void
    {
        // No conversion service injected (matches existing call
        // sites until they explicitly opt-in to currency).
        $serializer = new ProductSerializer();
        $product = $this->makeProduct(price: '365.00');

        $shape = $serializer->listShape($product);

        self::assertSame(365.00, $shape['price']['amount']);
        self::assertSame('AED', $shape['price']['currency']);
        self::assertArrayNotHasKey('source_amount', $shape['price']);
        self::assertArrayNotHasKey('source_currency', $shape['price']);
    }

    #[Test]
    public function aedExplicitlyConfiguredEmitsSingleAmountShape(): void
    {
        // Even with conversion service wired, AED display is the
        // single-amount shape (identity short-circuit).
        $service = $this->makeService(rates: [
            $this->makeRate('AED', 'USD', '0.27225000'),
        ]);
        $serializer = (new ProductSerializer($service))
            ->withDisplayCurrency(Currency::AED);

        $product = $this->makeProduct(price: '365.00');
        $shape = $serializer->listShape($product);

        self::assertSame(365.00, $shape['price']['amount']);
        self::assertSame('AED', $shape['price']['currency']);
        self::assertArrayNotHasKey('source_amount', $shape['price']);
    }

    // =================================================================
    // Currency conversion
    // =================================================================

    #[Test]
    public function usdConfiguredEmitsDualAmountShape(): void
    {
        // 365 AED * 0.27225 = 99.371250 → 99.37 HALF_UP
        $service = $this->makeService(rates: [
            $this->makeRate('AED', 'USD', '0.27225000'),
        ]);
        $serializer = (new ProductSerializer($service))
            ->withDisplayCurrency(Currency::USD);

        $product = $this->makeProduct(price: '365.00');
        $shape = $serializer->listShape($product);

        // Dual-amount shape with source preserved
        self::assertSame(99.37, $shape['price']['amount']);
        self::assertSame('USD', $shape['price']['currency']);
        self::assertSame(365.00, $shape['price']['source_amount']);
        self::assertSame('AED', $shape['price']['source_currency']);
    }

    #[Test]
    public function gbpConfiguredAppliesCorrectRate(): void
    {
        // 365 AED * 0.21450 = 78.29250 → 78.29 HALF_UP
        $service = $this->makeService(rates: [
            $this->makeRate('AED', 'GBP', '0.21450000'),
        ]);
        $serializer = (new ProductSerializer($service))
            ->withDisplayCurrency(Currency::GBP);

        $shape = $serializer->listShape($this->makeProduct(price: '365.00'));

        self::assertSame(78.29, $shape['price']['amount']);
        self::assertSame('GBP', $shape['price']['currency']);
        self::assertSame(365.00, $shape['price']['source_amount']);
    }

    #[Test]
    public function salePriceAlsoConverts(): void
    {
        // 200 AED * 0.27225 = 54.45, clean
        $service = $this->makeService(rates: [
            $this->makeRate('AED', 'USD', '0.27225000'),
        ]);
        $serializer = (new ProductSerializer($service))
            ->withDisplayCurrency(Currency::USD);

        $product = $this->makeProduct(price: '365.00');
        $product->setSalePrice('200.00');

        $shape = $serializer->listShape($product);

        self::assertSame(54.45, $shape['sale_price']['amount']);
        self::assertSame('USD', $shape['sale_price']['currency']);
        self::assertSame(200.00, $shape['sale_price']['source_amount']);
    }

    #[Test]
    public function nullSalePriceStaysNull(): void
    {
        // Currency change must not turn null sale_price into a
        // money shape.
        $service = $this->makeService(rates: [
            $this->makeRate('AED', 'USD', '0.27225000'),
        ]);
        $serializer = (new ProductSerializer($service))
            ->withDisplayCurrency(Currency::USD);

        $product = $this->makeProduct(price: '365.00');
        $shape = $serializer->listShape($product);

        self::assertNull($shape['sale_price']);
    }

    // =================================================================
    // Missing-rate fallback
    // =================================================================

    #[Test]
    public function missingRateFallsBackToSingleAmountAed(): void
    {
        // Service configured but no rates loaded → convert() returns
        // converted=false → serializer emits single-amount AED shape
        // (NOT the dual-amount with source_currency=AED + currency=AED
        // which would be confusing).
        $service = $this->makeService(rates: []);  // empty
        $serializer = (new ProductSerializer($service))
            ->withDisplayCurrency(Currency::USD);

        $product = $this->makeProduct(price: '365.00');
        $shape = $serializer->listShape($product);

        // AED fallback: single-amount shape, no source_* keys
        self::assertSame(365.00, $shape['price']['amount']);
        self::assertSame('AED', $shape['price']['currency']);
        self::assertArrayNotHasKey('source_amount', $shape['price']);
    }

    // =================================================================
    // configureFromRequest helper
    // =================================================================

    #[Test]
    public function configureFromRequestReadsMiddlewareAttribute(): void
    {
        $service = $this->makeService(rates: [
            $this->makeRate('AED', 'EUR', '0.25180000'),
        ]);
        $serializer = new ProductSerializer($service);

        $request = (new ServerRequestFactory())
            ->createServerRequest('GET', '/v3/products')
            ->withAttribute(
                CurrencyContextMiddleware::ATTR_DISPLAY_CURRENCY,
                Currency::EUR,
            );

        $shape = $serializer
            ->configureFromRequest($request)
            ->listShape($this->makeProduct(price: '100.00'));

        // 100 * 0.25180 = 25.18, clean
        self::assertSame(25.18, $shape['price']['amount']);
        self::assertSame('EUR', $shape['price']['currency']);
    }

    #[Test]
    public function configureFromRequestMissingAttributeDefaultsToAed(): void
    {
        // Defensive: middleware not installed (test environments
        // sometimes bypass it). configureFromRequest should pull
        // AED from the attribute default.
        $service = $this->makeService(rates: []);
        $serializer = new ProductSerializer($service);

        $request = (new ServerRequestFactory())
            ->createServerRequest('GET', '/v3/products');

        $shape = $serializer
            ->configureFromRequest($request)
            ->listShape($this->makeProduct(price: '365.00'));

        self::assertSame(365.00, $shape['price']['amount']);
        self::assertSame('AED', $shape['price']['currency']);
        self::assertArrayNotHasKey('source_amount', $shape['price']);
    }

    #[Test]
    public function configureFromRequestNonCurrencyAttributeDefaultsToAed(): void
    {
        // Defensive: an attribute with the right name but a wrong
        // value type (string instead of Currency enum) should not
        // crash; fall back to AED.
        $service = $this->makeService(rates: [
            $this->makeRate('AED', 'USD', '0.27225000'),
        ]);
        $serializer = new ProductSerializer($service);

        $request = (new ServerRequestFactory())
            ->createServerRequest('GET', '/v3/products')
            ->withAttribute(
                CurrencyContextMiddleware::ATTR_DISPLAY_CURRENCY,
                'USD',  // wrong type, a string, not a Currency enum
            );

        $shape = $serializer
            ->configureFromRequest($request)
            ->listShape($this->makeProduct(price: '365.00'));

        // Falls back to AED rather than crashing
        self::assertSame(365.00, $shape['price']['amount']);
        self::assertSame('AED', $shape['price']['currency']);
    }

    // =================================================================
    // Helpers
    // =================================================================

    private function makeProduct(string $price): Product
    {
        $vendor = (new \ReflectionClass(Vendor::class))->newInstanceWithoutConstructor();
        $vIdRef = new \ReflectionProperty(Vendor::class, 'id');
        $vIdRef->setAccessible(true);
        $vIdRef->setValue($vendor, 5);
        $vSlugRef = new \ReflectionProperty(Vendor::class, 'slug');
        $vSlugRef->setAccessible(true);
        $vSlugRef->setValue($vendor, 'test-vendor');
        $vNameRef = new \ReflectionProperty(Vendor::class, 'name');
        $vNameRef->setAccessible(true);
        $vNameRef->setValue($vendor, 'Test Vendor');

        $product = new Product($vendor, 'test-product', 'Test Product');
        $idRef = new \ReflectionProperty(Product::class, 'id');
        $idRef->setAccessible(true);
        $idRef->setValue($product, 100);
        $product->setPrice($price);
        return $product;
    }

    /**
     * @param list<FxRate> $rates
     */
    private function makeService(array $rates): CurrencyConversionService
    {
        $repo = $this->createMock(FxRateRepository::class);
        $repo->method('findAllRates')->willReturn($rates);

        $em = $this->createMock(EntityManagerInterface::class);
        $em->method('getRepository')->with(FxRate::class)->willReturn($repo);

        return new CurrencyConversionService($em, new NullLogger());
    }

    private function makeRate(string $base, string $target, string $rate): FxRate
    {
        return new FxRate($base, $target, $rate);
    }
}
