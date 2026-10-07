<?php

declare(strict_types=1);

namespace Bayti\Api\Tests\Http\Controllers\Admin\Product;

use Bayti\Api\Domain\Catalog\Category;
use Bayti\Api\Domain\Catalog\CategoryRepository;
use Bayti\Api\Domain\Catalog\Product;
use Bayti\Api\Domain\Catalog\ProductRepository;
use Bayti\Api\Domain\Catalog\Vendor;
use Bayti\Api\Domain\Catalog\VendorLabel;
use Bayti\Api\Domain\Catalog\VendorLabelRepository;
use Bayti\Api\Domain\Catalog\VendorRepository;
use Bayti\Api\Domain\User\User;
use Bayti\Api\Domain\User\UserRepository;
use Bayti\Api\Http\Controllers\Admin\Product\CreateAdminProductController;
use Bayti\Api\Http\Controllers\Admin\Product\GetAdminProductController;
use Bayti\Api\Http\Controllers\Admin\Product\UpdateAdminProductController;
use Bayti\Api\Http\Controllers\Vendor\Product\ProductInputApplier;
use Bayti\Api\Infrastructure\Auth\JwtService;
use Bayti\Api\Tests\Http\HttpTestCase;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use Psr\Http\Message\ResponseInterface;

/**
 * POST /v3/admin/products and PUT /v3/admin/products/{id} used to apply only
 * name/description/price/sale_price/status/images/sizes/colors/category/
 * delivery_info/label_id, so admin edits to stock, order limits, cost,
 * merchandising flags and made-to-measure were SILENTLY discarded. They now
 * share the vendor endpoints' field application (ProductInputApplier) with
 * the same partial-update semantics; only collection_id stays ignored (admin
 * storefront collections are curated separately).
 *
 * Also covers the editor round-trip through GET /v3/admin/products/{id}: the
 * detail shape exposes every editable field under the write-DTO names, so
 * re-saving what was loaded changes nothing.
 */
#[CoversClass(CreateAdminProductController::class)]
#[CoversClass(UpdateAdminProductController::class)]
#[CoversClass(GetAdminProductController::class)]
#[CoversClass(ProductInputApplier::class)]
final class AdminProductFieldsTest extends HttpTestCase
{
    private const STORE = 5;
    private const LABEL = 31;

    private ?Product $saved = null;
    private int $saveCalls = 0;
    private Vendor $vendor;
    private VendorLabel $label;

    protected function setUp(): void
    {
        parent::setUp();
        $this->saved = null;
        $this->saveCalls = 0;
        $this->vendor = $this->makeVendor(self::STORE);
        $this->label = $this->makeLabel(self::LABEL, $this->vendor);
    }

    // ── PUT /v3/admin/products/{id} ─────────────────────────────────

    #[Test]
    public function updatePersistsEveryPreviouslyIgnoredField(): void
    {
        $product = $this->makeProduct(100);

        $res = $this->update($product, [
            'stock_quantity' => 7,
            'stock_status' => 'limited',
            'allow_oversell' => true,
            'min_order_qty' => 2,
            'max_order_qty' => 9,
            'cost_per_item' => 120.25,
            'is_featured' => true,
            'is_new' => true,
            'is_hot' => true,
            'is_sale' => true,
            'requires_extra_msmt' => true,
            'extra_msmt' => 'Bust, waist and length.',
        ]);

        self::assertSame(200, $res->getStatusCode(), (string) $res->getBody());
        self::assertSame(1, $this->saveCalls);
        self::assertSame(7, $product->getStockQuantity());
        self::assertSame(Product::STOCK_LIMITED, $product->getStockStatus());
        self::assertTrue($product->getAllowOversell());
        self::assertSame(2, $product->getMinOrderQty());
        self::assertSame(9, $product->getMaxOrderQty());
        self::assertSame('120.25', $product->getCostPerItem());
        self::assertTrue($product->isFeatured());
        self::assertTrue($product->isNew());
        self::assertTrue($product->isHot());
        self::assertTrue($product->isSale());
        self::assertTrue($product->requiresExtraMsmt());
        self::assertSame('Bust, waist and length.', $product->getExtraMsmt());
    }

    #[Test]
    public function updateAppliesExplicitFalseAndZero(): void
    {
        $product = $this->makePopulatedProduct(100);

        $res = $this->update($product, [
            'stock_quantity' => 0,
            'allow_oversell' => false,
            'is_featured' => false,
            'is_new' => false,
            'is_hot' => false,
            'is_sale' => false,
            'requires_extra_msmt' => false,
        ]);

        self::assertSame(200, $res->getStatusCode(), (string) $res->getBody());
        self::assertSame(0, $product->getStockQuantity());
        self::assertFalse($product->getAllowOversell());
        self::assertFalse($product->isFeatured());
        self::assertFalse($product->isNew());
        self::assertFalse($product->isHot());
        self::assertFalse($product->isSale());
        self::assertFalse($product->requiresExtraMsmt());
    }

    #[Test]
    public function updateLeavesAbsentFieldsUnchanged(): void
    {
        $product = $this->makePopulatedProduct(100);

        $res = $this->update($product, ['name' => 'Renamed']);

        self::assertSame(200, $res->getStatusCode(), (string) $res->getBody());
        self::assertSame('Renamed', $product->getName());
        self::assertSame('100.00', $product->getPrice());
        self::assertSame('80.00', $product->getSalePrice(), 'An omitted sale_price keeps the stored discount.');
        self::assertSame('40.00', $product->getCostPerItem());
        self::assertSame(12, $product->getStockQuantity());
        self::assertSame(Product::STOCK_LIMITED, $product->getStockStatus());
        self::assertTrue($product->getAllowOversell());
        self::assertSame(2, $product->getMinOrderQty());
        self::assertSame(6, $product->getMaxOrderQty());
        self::assertTrue($product->isFeatured());
        self::assertTrue($product->isNew());
        self::assertTrue($product->isHot());
        self::assertTrue($product->isSale());
        self::assertTrue($product->requiresExtraMsmt());
        self::assertSame('Shoulder width', $product->getExtraMsmt());
        self::assertSame(3, $product->getCollectionId());
        self::assertSame(self::LABEL, $product->getLabelId());
    }

    #[Test]
    public function updateWithNullFieldsLeavesThemUnchanged(): void
    {
        $product = $this->makePopulatedProduct(100);

        $res = $this->update($product, [
            'cost_per_item' => null,
            'min_order_qty' => null,
            'max_order_qty' => null,
            'is_featured' => null,
            'extra_msmt' => null,
        ]);

        self::assertSame(200, $res->getStatusCode(), (string) $res->getBody());
        self::assertSame('40.00', $product->getCostPerItem());
        self::assertSame(2, $product->getMinOrderQty());
        self::assertSame(6, $product->getMaxOrderQty());
        self::assertTrue($product->isFeatured());
        self::assertSame('Shoulder width', $product->getExtraMsmt());
    }

    #[Test]
    public function updateClearsSalePriceOnlyWhenTheKeyIsSentAsNull(): void
    {
        $product = $this->makePopulatedProduct(100);

        $res = $this->update($product, ['sale_price' => null]);

        self::assertSame(200, $res->getStatusCode(), (string) $res->getBody());
        self::assertNull($product->getSalePrice());
    }

    #[Test]
    public function updateIgnoresCollectionId(): void
    {
        $product = $this->makePopulatedProduct(100);

        $res = $this->update($product, ['collection_id' => 99, 'is_hot' => false]);

        self::assertSame(200, $res->getStatusCode(), (string) $res->getBody());
        self::assertSame(3, $product->getCollectionId(), 'Admin storefront collections are curated separately.');
        self::assertFalse($product->isHot());
    }

    #[Test]
    public function updateRejectsAnInvalidFieldAndPersistsNothing(): void
    {
        // Same DTO validation as the vendor flow: max_order_qty must be positive.
        $product = $this->makePopulatedProduct(100);

        $res = $this->update($product, ['max_order_qty' => 0, 'is_featured' => false]);

        self::assertSame(422, $res->getStatusCode(), (string) $res->getBody());
        self::assertSame(0, $this->saveCalls);
        self::assertSame(6, $product->getMaxOrderQty());
        self::assertTrue($product->isFeatured());
    }

    // ── POST /v3/admin/products ─────────────────────────────────────

    #[Test]
    public function createAppliesEveryVendorField(): void
    {
        $res = $this->create([
            'description' => 'Hand-finished silk.',
            'sale_price' => 399.5,
            'cost_per_item' => 120.25,
            'stock_quantity' => 7,
            'stock_status' => 'limited',
            'allow_oversell' => true,
            'min_order_qty' => 2,
            'max_order_qty' => 9,
            'is_featured' => true,
            'is_new' => true,
            'is_hot' => true,
            'is_sale' => true,
            'requires_extra_msmt' => true,
            'extra_msmt' => 'Bust, waist and length.',
            'sizes' => ['S', 'M'],
            'colors' => ['black'],
            'delivery_info' => ['time' => '3-5'],
            'label_id' => self::LABEL,
        ]);

        self::assertSame(201, $res->getStatusCode(), (string) $res->getBody());
        $p = $this->saved;
        self::assertNotNull($p);
        self::assertSame(self::STORE, $p->getVendor()->getId());
        self::assertSame('Silk Abaya', $p->getName());
        self::assertSame('Hand-finished silk.', $p->getDescription());
        self::assertSame('450.00', $p->getPrice());
        self::assertSame('399.50', $p->getSalePrice());
        self::assertSame('120.25', $p->getCostPerItem());
        self::assertSame(7, $p->getStockQuantity());
        self::assertSame(Product::STOCK_LIMITED, $p->getStockStatus());
        self::assertTrue($p->getAllowOversell());
        self::assertSame(2, $p->getMinOrderQty());
        self::assertSame(9, $p->getMaxOrderQty());
        self::assertTrue($p->isFeatured());
        self::assertTrue($p->isNew());
        self::assertTrue($p->isHot());
        self::assertTrue($p->isSale());
        self::assertTrue($p->requiresExtraMsmt());
        self::assertSame('Bust, waist and length.', $p->getExtraMsmt());
        self::assertSame(['S', 'M'], $p->getAvailableSizes());
        self::assertSame(['black'], $p->getAvailableColors());
        self::assertSame('3-5', $p->getDeliveryInfo()['time'] ?? null);
        self::assertSame(Product::STATUS_ACTIVE, $p->getStatus());
        self::assertSame(self::LABEL, $p->getLabelId());
    }

    #[Test]
    public function createWithoutOptionalFieldsKeepsEntityDefaults(): void
    {
        $res = $this->create([]);

        self::assertSame(201, $res->getStatusCode(), (string) $res->getBody());
        $p = $this->saved;
        self::assertNotNull($p);
        self::assertNull($p->getSalePrice());
        self::assertNull($p->getCostPerItem());
        self::assertNull($p->getMinOrderQty());
        self::assertNull($p->getMaxOrderQty());
        self::assertFalse($p->isFeatured());
        self::assertFalse($p->requiresExtraMsmt());
        self::assertNull($p->getExtraMsmt());
        self::assertNull($p->getCollectionId());
    }

    #[Test]
    public function createIgnoresCollectionId(): void
    {
        $res = $this->create(['collection_id' => 7]);

        self::assertSame(201, $res->getStatusCode(), (string) $res->getBody());
        self::assertNotNull($this->saved);
        self::assertNull($this->saved->getCollectionId());
    }

    // ── GET /v3/admin/products/{id} → PUT round-trip ────────────────

    #[Test]
    public function detailExposesEditorFieldsUnderDtoNames(): void
    {
        $product = $this->makePopulatedProduct(100);

        $res = $this->get($product);

        self::assertSame(200, $res->getStatusCode(), (string) $res->getBody());
        $data = $this->jsonBody($res)['data'];
        self::assertSame(2, $data['min_order_qty']);
        self::assertSame(6, $data['max_order_qty']);
        self::assertSame(2, $data['min_order_quantity']);
        self::assertSame(6, $data['max_order_quantity']);
        self::assertSame('40.00', $data['cost_per_item']);
        self::assertTrue($data['is_featured']);
        self::assertTrue($data['is_new']);
        self::assertTrue($data['is_hot']);
        self::assertTrue($data['is_sale']);
        self::assertTrue($data['requires_extra_msmt']);
        self::assertSame('Shoulder width', $data['extra_msmt']);
        self::assertSame(12, $data['stock_quantity']);
        self::assertSame(Product::STOCK_LIMITED, $data['stock_status']);
        self::assertTrue($data['allow_oversell']);
    }

    #[Test]
    public function resavingTheLoadedDetailChangesNothing(): void
    {
        // The editor loads the detail shape and PUTs its fields straight back.
        // Before the fix the DTO-named keys were missing, so a re-save sent
        // defaults and reset max order qty / cost / featured / made-to-measure.
        $product = $this->makePopulatedProduct(100);
        $data = $this->jsonBody($this->get($product))['data'];

        $body = [];
        foreach ([
            'stock_quantity', 'stock_status', 'allow_oversell', 'min_order_qty', 'max_order_qty',
            'is_featured', 'is_new', 'is_hot', 'is_sale', 'requires_extra_msmt', 'extra_msmt',
        ] as $key) {
            self::assertArrayHasKey($key, $data);
            $body[$key] = $data[$key];
        }
        // Decimal string in the read shape; the form posts it back as a number.
        $body['cost_per_item'] = (float) $data['cost_per_item'];

        $res = $this->update($product, $body);

        self::assertSame(200, $res->getStatusCode(), (string) $res->getBody());
        self::assertSame('40.00', $product->getCostPerItem());
        self::assertSame(12, $product->getStockQuantity());
        self::assertSame(Product::STOCK_LIMITED, $product->getStockStatus());
        self::assertTrue($product->getAllowOversell());
        self::assertSame(2, $product->getMinOrderQty());
        self::assertSame(6, $product->getMaxOrderQty());
        self::assertTrue($product->isFeatured());
        self::assertTrue($product->isNew());
        self::assertTrue($product->isHot());
        self::assertTrue($product->isSale());
        self::assertTrue($product->requiresExtraMsmt());
        self::assertSame('Shoulder width', $product->getExtraMsmt());
    }

    // =================================================================
    // Helpers
    // =================================================================

    /** @param array<string, mixed> $extra */
    private function create(array $extra): ResponseInterface
    {
        $admin = $this->makeAdmin();
        $this->bindDeps($admin, null);

        return $this->send($admin, 'POST', '/v3/admin/products', array_merge([
            'vendor_id' => self::STORE,
            'name' => 'Silk Abaya',
            'price' => 450,
            'status' => 'active',
        ], $extra));
    }

    /** @param array<string, mixed> $body */
    private function update(Product $product, array $body): ResponseInterface
    {
        $admin = $this->makeAdmin();
        $this->bindDeps($admin, $product);

        return $this->send($admin, 'PUT', '/v3/admin/products/' . $product->getId(), $body);
    }

    private function get(Product $product): ResponseInterface
    {
        $admin = $this->makeAdmin();
        $this->bindDeps($admin, $product);

        return $this->send($admin, 'GET', '/v3/admin/products/' . $product->getId(), []);
    }

    /** @param array<string, mixed> $body */
    private function send(User $user, string $method, string $uri, array $body): ResponseInterface
    {
        $jwt = $this->app->getContainer()->get(JwtService::class);
        $pair = $jwt->issueTokenPair($user);

        return $this->handle($this->jsonRequest($method, $uri, $body, [
            'Authorization' => 'Bearer ' . $pair->accessToken,
        ]));
    }

    private function bindDeps(User $admin, ?Product $product): void
    {
        $userRepo = $this->createMock(UserRepository::class);
        $userRepo->method('findById')->willReturn($admin);

        $vendorRepo = $this->createMock(VendorRepository::class);
        $vendorRepo->method('find')->willReturnCallback(
            fn (mixed $id): ?Vendor => (int) $id === self::STORE ? $this->vendor : null,
        );

        $catRepo = $this->createMock(CategoryRepository::class);
        $catRepo->method('find')->willReturn(null);

        $labelRepo = $this->createMock(VendorLabelRepository::class);
        $labelRepo->method('find')->willReturnCallback(
            fn (mixed $id): ?VendorLabel => (int) $id === self::LABEL ? $this->label : null,
        );

        $productRepo = $this->createMock(ProductRepository::class);
        $productRepo->method('find')->willReturnCallback(
            static fn (mixed $id): ?Product => $product !== null && (int) $id === $product->getId() ? $product : null,
        );
        $productRepo->method('save')->willReturnCallback(function (Product $p): void {
            $this->saveCalls++;
            $this->saved = $p;
            if ($p->getId() === null) {
                $rp = new \ReflectionProperty(Product::class, 'id');
                $rp->setAccessible(true);
                $rp->setValue($p, 4242);
            }
        });

        $em = $this->stubEm(function ($em) use ($userRepo, $vendorRepo, $catRepo, $labelRepo, $productRepo): void {
            $em->method('getRepository')->willReturnMap([
                [User::class, $userRepo],
                [Vendor::class, $vendorRepo],
                [Category::class, $catRepo],
                [VendorLabel::class, $labelRepo],
                [Product::class, $productRepo],
            ]);
        });
        $this->bind(EntityManagerInterface::class, $em);
    }

    private function makeAdmin(): User
    {
        $user = $this->makeUser(id: 99);
        $user->setRoles(admin: true);
        return $user;
    }

    private function makeVendor(int $id): Vendor
    {
        $vendor = new Vendor("store-{$id}", "Store {$id}", "store{$id}@example.com");
        $vendor->approve();
        $rp = new \ReflectionProperty(Vendor::class, 'id');
        $rp->setAccessible(true);
        $rp->setValue($vendor, $id);
        return $vendor;
    }

    private function makeLabel(int $id, Vendor $vendor): VendorLabel
    {
        $label = new VendorLabel($vendor, "label-{$id}", "Label {$id}");
        $rp = new \ReflectionProperty(VendorLabel::class, 'id');
        $rp->setAccessible(true);
        $rp->setValue($label, $id);
        return $label;
    }

    private function makeProduct(int $id): Product
    {
        $product = new Product($this->vendor, "product-{$id}", "Product {$id}");
        $rp = new \ReflectionProperty(Product::class, 'id');
        $rp->setAccessible(true);
        $rp->setValue($product, $id);
        $product->setPrice('100.00');
        return $product;
    }

    private function makePopulatedProduct(int $id): Product
    {
        $product = $this->makeProduct($id);
        $product->setSalePrice('80.00');
        $product->setCostPerItem('40.00');
        $product->setAllowOversell(true);
        $product->setStockQuantity(12);
        $product->setStockStatus(Product::STOCK_LIMITED);
        $product->setMinOrderQty(2);
        $product->setMaxOrderQty(6);
        $product->setIsFeatured(true);
        $product->setIsNew(true);
        $product->setIsHot(true);
        $product->setIsSale(true);
        $product->setRequiresExtraMsmt(true);
        $product->setExtraMsmt('Shoulder width');
        $product->setCollectionId(3);
        $product->setLabelId(self::LABEL);
        return $product;
    }
}
