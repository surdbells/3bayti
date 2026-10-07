<?php

declare(strict_types=1);

namespace Bayti\Api\Tests\Http\Controllers\Vendor;

use Bayti\Api\Domain\Catalog\Category;
use Bayti\Api\Domain\Catalog\CategoryRepository;
use Bayti\Api\Domain\Catalog\Product;
use Bayti\Api\Domain\Catalog\ProductRepository;
use Bayti\Api\Domain\Catalog\Vendor;
use Bayti\Api\Domain\Catalog\VendorLabel;
use Bayti\Api\Domain\Catalog\VendorLabelRepository;
use Bayti\Api\Domain\Catalog\VendorRepository;
use Bayti\Api\Domain\Media\ImageStorageService;
use Bayti\Api\Domain\User\User;
use Bayti\Api\Domain\User\UserRepository;
use Bayti\Api\Http\Controllers\Vendor\Product\ProductInputApplier;
use Bayti\Api\Http\Controllers\Vendor\Product\UpdateVendorProductController;
use Bayti\Api\Infrastructure\Auth\JwtService;
use Bayti\Api\Tests\Http\HttpTestCase;
use Doctrine\ORM\EntityManagerInterface;
use League\Flysystem\FilesystemOperator;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use Psr\Http\Message\ResponseInterface;

/**
 * PUT /v3/vendor/products/{id}. Its field application moved into the shared
 * ProductInputApplier (now also used by the admin endpoints); these lock the
 * vendor behaviour that must not change: every DTO field incl. collection_id
 * applied, absent/null fields kept, sale_price only when the key is sent,
 * label validated BEFORE any mutation (incl. image cleanup), and replaced
 * hosted images deleted only after the save.
 */
#[CoversClass(UpdateVendorProductController::class)]
#[CoversClass(ProductInputApplier::class)]
final class UpdateVendorProductControllerTest extends HttpTestCase
{
    private const USER = 100;
    private const STORE = 101;
    private const LABEL = 31;
    private const FOREIGN_LABEL = 32;

    private Vendor $vendor;
    private Product $product;
    private Category $category;

    /** @var array<int, VendorLabel> */
    private array $labels = [];

    /** @var list<string> ordered "save" / "delete:{path}" events */
    private array $events = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->events = [];
        $this->vendor = $this->makeVendor(self::STORE);
        $other = $this->makeVendor(999);
        $this->labels = [
            self::LABEL => $this->makeLabel(self::LABEL, $this->vendor),
            self::FOREIGN_LABEL => $this->makeLabel(self::FOREIGN_LABEL, $other),
        ];
        $this->category = new Category('abayas', 'Abayas');
        $this->product = $this->makePopulatedProduct(5);
    }

    #[Test]
    public function appliesEveryFieldIncludingCollection(): void
    {
        $res = $this->update([
            'name' => 'Silk Abaya',
            'price' => 450,
            'sale_price' => 399.5,
            'cost_per_item' => 120.25,
            'stock_quantity' => 7,
            'stock_status' => 'on_backorder',
            'allow_oversell' => false,
            'min_order_qty' => 1,
            'max_order_qty' => 9,
            'is_featured' => false,
            'is_new' => false,
            'is_hot' => false,
            'is_sale' => false,
            'requires_extra_msmt' => false,
            'extra_msmt' => 'Length only',
            'collection_id' => 8,
            'label_id' => self::LABEL,
            'category_id' => 4,
            'status' => 'draft',
        ]);

        self::assertSame(200, $res->getStatusCode(), (string) $res->getBody());
        $p = $this->product;
        self::assertSame('Silk Abaya', $p->getName());
        self::assertSame('450.00', $p->getPrice());
        self::assertSame('399.50', $p->getSalePrice());
        self::assertSame('120.25', $p->getCostPerItem());
        self::assertSame(7, $p->getStockQuantity());
        self::assertSame(Product::STOCK_BACKORDER, $p->getStockStatus());
        self::assertFalse($p->getAllowOversell());
        self::assertSame(1, $p->getMinOrderQty());
        self::assertSame(9, $p->getMaxOrderQty());
        self::assertFalse($p->isFeatured());
        self::assertFalse($p->isNew());
        self::assertFalse($p->isHot());
        self::assertFalse($p->isSale());
        self::assertFalse($p->requiresExtraMsmt());
        self::assertSame('Length only', $p->getExtraMsmt());
        self::assertSame(8, $p->getCollectionId(), 'The vendor flow still applies collection_id.');
        self::assertSame(self::LABEL, $p->getLabelId());
        self::assertSame($this->category, $p->getCategory());
        self::assertSame(Product::STATUS_DRAFT, $p->getStatus());
        self::assertSame(['save'], $this->events, 'No image was replaced, so nothing is deleted.');
    }

    #[Test]
    public function partialUpdateKeepsAbsentFields(): void
    {
        $res = $this->update(['name' => 'Renamed']);

        self::assertSame(200, $res->getStatusCode(), (string) $res->getBody());
        $p = $this->product;
        self::assertSame('Renamed', $p->getName());
        self::assertSame('100.00', $p->getPrice());
        self::assertSame('80.00', $p->getSalePrice(), 'An omitted sale_price keeps the stored discount.');
        self::assertSame('40.00', $p->getCostPerItem());
        self::assertSame(12, $p->getStockQuantity());
        self::assertSame(Product::STOCK_LIMITED, $p->getStockStatus());
        self::assertTrue($p->getAllowOversell());
        self::assertSame(2, $p->getMinOrderQty());
        self::assertSame(6, $p->getMaxOrderQty());
        self::assertTrue($p->isFeatured());
        self::assertTrue($p->requiresExtraMsmt());
        self::assertSame('Shoulder width', $p->getExtraMsmt());
        self::assertSame(3, $p->getCollectionId());
        self::assertNull($p->getLabelId());
        self::assertNull($p->getCategory());
        self::assertSame([$this->hostedUrl('a.jpg'), 'https://legacy.example.com/b.jpg'], $p->getImages());
    }

    #[Test]
    public function explicitNullSalePriceClearsTheDiscount(): void
    {
        $res = $this->update(['sale_price' => null]);

        self::assertSame(200, $res->getStatusCode(), (string) $res->getBody());
        self::assertNull($this->product->getSalePrice());
    }

    #[Test]
    public function rejectedLabelChangesNothingAndDeletesNoImage(): void
    {
        $res = $this->update([
            'name' => 'Renamed',
            'image_urls' => [$this->hostedUrl('c.jpg')],
            'label_id' => self::FOREIGN_LABEL,
        ]);

        self::assertSame(422, $res->getStatusCode(), (string) $res->getBody());
        self::assertArrayHasKey('label_id', $this->jsonBody($res)['error']['details']['fields']);
        self::assertSame([], $this->events, 'Nothing saved, no stored image deleted.');
        self::assertSame('Product 5', $this->product->getName());
        self::assertSame([$this->hostedUrl('a.jpg'), 'https://legacy.example.com/b.jpg'], $this->product->getImages());
    }

    #[Test]
    public function replacedHostedImagesAreDeletedAfterTheSave(): void
    {
        $res = $this->update(['image_urls' => [$this->hostedUrl('c.jpg')]]);

        self::assertSame(200, $res->getStatusCode(), (string) $res->getBody());
        self::assertSame([$this->hostedUrl('c.jpg')], $this->product->getImages());
        // a.jpg (hosted) is orphaned -> deleted after the save; the external
        // legacy URL is never deleted; the untouched hosted primary stays.
        self::assertSame(['save', 'delete:products/store-101/a.jpg'], $this->events);
    }

    // =================================================================
    // Helpers
    // =================================================================

    /** @param array<string, mixed> $body */
    private function update(array $body): ResponseInterface
    {
        $user = $this->makeUser(id: self::USER);
        $user->setRoles(vendor: true);
        $this->bindDeps($user);

        $jwt = $this->app->getContainer()->get(JwtService::class);
        $pair = $jwt->issueTokenPair($user);

        return $this->handle($this->jsonRequest('PUT', '/v3/vendor/products/' . $this->product->getId(), $body, [
            'Authorization' => 'Bearer ' . $pair->accessToken,
        ]));
    }

    private function bindDeps(User $user): void
    {
        $userRepo = $this->createMock(UserRepository::class);
        $userRepo->method('findById')->willReturn($user);

        $vendorRepo = $this->createMock(VendorRepository::class);
        $vendorRepo->method('findIdsByOwnerUser')->willReturn([self::STORE]);
        $vendorRepo->method('existsApprovedForOwnerUser')->willReturn(true);

        $catRepo = $this->createMock(CategoryRepository::class);
        $catRepo->method('find')->willReturnCallback(
            fn (mixed $id): ?Category => (int) $id === 4 ? $this->category : null,
        );

        $labelRepo = $this->createMock(VendorLabelRepository::class);
        $labelRepo->method('find')->willReturnCallback(
            fn (mixed $id): ?VendorLabel => $this->labels[(int) $id] ?? null,
        );

        $productRepo = $this->createMock(ProductRepository::class);
        $productRepo->method('find')->willReturnCallback(
            fn (mixed $id): ?Product => (int) $id === $this->product->getId() ? $this->product : null,
        );
        $productRepo->method('save')->willReturnCallback(function (): void {
            $this->events[] = 'save';
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

        $fs = $this->createMock(FilesystemOperator::class);
        $fs->method('delete')->willReturnCallback(function (string $path): void {
            $this->events[] = 'delete:' . $path;
        });
        $this->bind(ImageStorageService::class, new ImageStorageService($fs));
    }

    private function hostedUrl(string $file): string
    {
        return ImageStorageService::publicUrl('products/store-101/' . $file);
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

    private function makePopulatedProduct(int $id): Product
    {
        $product = new Product($this->vendor, "product-{$id}", "Product {$id}");
        $rp = new \ReflectionProperty(Product::class, 'id');
        $rp->setAccessible(true);
        $rp->setValue($product, $id);
        $product->setPrice('100.00');
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
        $product->setPrimaryImageUrl($this->hostedUrl('hero.jpg'));
        $product->setImages([$this->hostedUrl('a.jpg'), 'https://legacy.example.com/b.jpg']);
        return $product;
    }
}
