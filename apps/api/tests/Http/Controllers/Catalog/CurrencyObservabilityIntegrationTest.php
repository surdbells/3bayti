<?php

declare(strict_types=1);

namespace Bayti\Api\Tests\Http\Controllers\Catalog;

use Bayti\Api\Domain\Catalog\Product;
use Bayti\Api\Domain\Catalog\ProductRepository;
use Bayti\Api\Domain\Catalog\Vendor;
use Bayti\Api\Domain\Currency\Currency;
use Bayti\Api\Domain\Currency\CurrencyConversionService;
use Bayti\Api\Domain\Currency\FxRate;
use Bayti\Api\Domain\Currency\FxRateRepository;
use Bayti\Api\Http\Controllers\Catalog\ListProductsController;
use Bayti\Api\Http\Serializers\ProductSerializer;
use Bayti\Api\Tests\Http\HttpTestCase;
use Bayti\Api\Tests\Support\InMemoryLogger;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\Test;
use Psr\Http\Message\ResponseInterface;
use Psr\Log\LoggerInterface;

/**
 * M3.2.X.15-G integration coverage. End-to-end verification
 * that the X.15 stack (middleware → controller → serializer →
 * conversion service) wires up correctly with REAL service
 * instances and that observability events propagate end-to-end.
 *
 * Mirrors the X.14-E / X.17-E / X.11-H pattern: bind real
 * services with mocked Connection / Repository + capturing
 * logger; drive a real HTTP request; verify observability
 * survives the full composition.
 *
 * Catches the failure mode where:
 *   - Unit tests pass (each service emits PSR-3 events in
 *     isolation)
 *   - Wiring + DI changes silently break log propagation
 *   - Production logs silently die
 *
 * Test matrix:
 *   - Happy path: fresh rates → conversion succeeds, no
 *     warning logs
 *   - Stale rate: > 48h old → fx_rate.stale warning fires
 *   - Missing rate: target not in rates table → fx_rate.missing
 *     warning fires
 *   - AED request: no conversion, no service call, no warnings
 */
final class CurrencyObservabilityIntegrationTest extends HttpTestCase
{
    private InMemoryLogger $logger;

    protected function setUp(): void
    {
        parent::setUp();
        $this->logger = new InMemoryLogger();
        // Bind the in-memory logger into the container so the
        // real CurrencyConversionService gets it via autowire.
        $this->bind(LoggerInterface::class, $this->logger);
    }

    #[Test]
    public function freshRateLogsNoStalnessWarning(): void
    {
        $this->bindCatalogStack(
            rates: [
                $this->makeRate('AED', 'USD', '0.27225000', ageHours: 2),
            ],
            products: [$this->makeProduct(price: '365.00')],
        );

        $response = $this->get('/v3/products?currency=USD');

        self::assertSame(200, $response->getStatusCode());
        $body = $this->jsonBody($response);

        // Conversion happened
        $price = $body['data'][0]['price'];
        self::assertSame(99.37, $price['amount']);
        self::assertSame('USD', $price['currency']);
        self::assertSame(365.00, $price['source_amount']);

        // No warnings of any kind
        self::assertCount(0, $this->logger->findByMessage('fx_rate.stale'));
        self::assertCount(0, $this->logger->findByMessage('fx_rate.missing'));
    }

    #[Test]
    public function staleRateEmitsWarningEndToEnd(): void
    {
        $this->bindCatalogStack(
            rates: [
                $this->makeRate('AED', 'USD', '0.27225000', ageHours: 72),
            ],
            products: [$this->makeProduct(price: '365.00')],
        );

        $response = $this->get('/v3/products?currency=USD');

        self::assertSame(200, $response->getStatusCode());

        // Conversion still succeeded, sticky last-known rate
        $body = $this->jsonBody($response);
        self::assertSame('USD', $body['data'][0]['price']['currency']);

        // Warning fired through the full stack
        $stale = $this->logger->findByMessage('fx_rate.stale');
        self::assertCount(1, $stale);
        self::assertSame('USD', $stale[0]['context']['target']);
        self::assertGreaterThanOrEqual(48, $stale[0]['context']['age_hours']);
        self::assertSame(48, $stale[0]['context']['threshold_hours']);
    }

    #[Test]
    public function missingRateEmitsWarningEndToEnd(): void
    {
        // No rates in the table at all
        $this->bindCatalogStack(
            rates: [],
            products: [$this->makeProduct(price: '365.00')],
        );

        $response = $this->get('/v3/products?currency=USD');

        self::assertSame(200, $response->getStatusCode());

        // Falls back to AED, single-amount shape
        $body = $this->jsonBody($response);
        $price = $body['data'][0]['price'];
        self::assertSame('AED', $price['currency']);
        self::assertArrayNotHasKey('source_amount', $price);

        // Warning fired
        $missing = $this->logger->findByMessage('fx_rate.missing');
        self::assertCount(1, $missing);
        self::assertSame('USD', $missing[0]['context']['target']);
    }

    #[Test]
    public function aedRequestNoServiceCallNoWarnings(): void
    {
        $this->bindCatalogStack(
            rates: [
                $this->makeRate('AED', 'USD', '0.27225000', ageHours: 72), // stale
            ],
            products: [$this->makeProduct(price: '365.00')],
        );

        // No currency param → middleware defaults to AED → conversion
        // service short-circuits → no DB load → no staleness check
        $response = $this->get('/v3/products');

        self::assertSame(200, $response->getStatusCode());

        $body = $this->jsonBody($response);
        $price = $body['data'][0]['price'];
        // Single-amount AED shape (no source_* keys)
        self::assertSame(365.00, $price['amount']);
        self::assertSame('AED', $price['currency']);
        self::assertArrayNotHasKey('source_amount', $price);

        // No warnings, the AED short-circuit avoids even loading
        // the rates table, so we don't see the stale USD rate
        self::assertCount(0, $this->logger->findByMessage('fx_rate.stale'));
        self::assertCount(0, $this->logger->findByMessage('fx_rate.missing'));
    }

    #[Test]
    public function explicitAedQueryParamAlsoSkipsConversion(): void
    {
        $this->bindCatalogStack(
            rates: [],
            products: [$this->makeProduct(price: '365.00')],
        );

        $response = $this->get('/v3/products?currency=AED');

        self::assertSame(200, $response->getStatusCode());

        $body = $this->jsonBody($response);
        $price = $body['data'][0]['price'];
        self::assertSame('AED', $price['currency']);
        // No source_amount when AED is the target (identity)
        self::assertArrayNotHasKey('source_amount', $price);
    }

    // =================================================================
    // Helpers
    // =================================================================

    /**
     * @param list<FxRate> $rates
     * @param list<Product> $products
     */
    private function bindCatalogStack(array $rates, array $products): void
    {
        $fxRepo = $this->createMock(FxRateRepository::class);
        $fxRepo->method('findAllRates')->willReturn($rates);

        $productRepo = $this->createMock(ProductRepository::class);
        $productRepo->method('findActivePaginated')->willReturn([
            'items' => $products,
            'total' => count($products),
        ]);

        $em = $this->createMock(EntityManagerInterface::class);
        $em->method('getRepository')->willReturnCallback(
            fn (string $class) => match ($class) {
                FxRate::class => $fxRepo,
                Product::class => $productRepo,
                default => throw new \LogicException("Unexpected repo: {$class}"),
            },
        );
        $this->bind(EntityManagerInterface::class, $em);

        // Real services, autowired via the container
        $this->bind(CurrencyConversionService::class,
            new CurrencyConversionService($em, $this->logger));
        $this->bind(ProductSerializer::class,
            new ProductSerializer($this->app->getContainer()->get(CurrencyConversionService::class)));
    }

    private function makeRate(string $base, string $target, string $rate, int $ageHours): FxRate
    {
        $r = new FxRate($base, $target, $rate);
        $when = (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))
            ->modify("-{$ageHours} hours");
        $ref = new \ReflectionProperty(FxRate::class, 'updatedAt');
        $ref->setAccessible(true);
        $ref->setValue($r, $when);
        return $r;
    }

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

    private function get(string $uri): ResponseInterface
    {
        return $this->handle($this->jsonRequest('GET', $uri, []));
    }
}
