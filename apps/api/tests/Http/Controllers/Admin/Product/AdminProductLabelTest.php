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
use Bayti\Api\Http\Controllers\Admin\Product\AdminProductLabelValidator;
use Bayti\Api\Http\Controllers\Admin\Product\CreateAdminProductController;
use Bayti\Api\Http\Controllers\Admin\Product\UpdateAdminProductController;
use Bayti\Api\Infrastructure\Auth\JwtService;
use Bayti\Api\Tests\Http\HttpTestCase;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use Psr\Http\Message\ResponseInterface;

/**
 * The admin product form's store-label choice (label_id) used to be silently
 * dropped by POST /v3/admin/products and PUT /v3/admin/products/{id}. It must
 * now be persisted, but only when it is an ACTIVE label of the product's own
 * store; anything else is a 422 on `label_id` and nothing is written.
 */
#[CoversClass(CreateAdminProductController::class)]
#[CoversClass(UpdateAdminProductController::class)]
#[CoversClass(AdminProductLabelValidator::class)]
final class AdminProductLabelTest extends HttpTestCase
{
    private const STORE = 5;
    private const OTHER_STORE = 6;

    private const LABEL_SAME_STORE = 31;
    private const LABEL_OTHER_STORE = 32;
    private const LABEL_SAME_STORE_DELETED = 33;
    private const LABEL_SAME_STORE_ALT = 34;

    private ?Product $saved = null;
    private int $saveCalls = 0;

    /** @var array<int, Vendor> */
    private array $vendors = [];

    /** @var array<int, VendorLabel> */
    private array $labels = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->saved = null;
        $this->saveCalls = 0;

        $this->vendors = [
            self::STORE => $this->makeVendor(self::STORE),
            self::OTHER_STORE => $this->makeVendor(self::OTHER_STORE),
        ];
        $this->labels = [
            self::LABEL_SAME_STORE => $this->makeLabel(self::LABEL_SAME_STORE, $this->vendors[self::STORE]),
            self::LABEL_OTHER_STORE => $this->makeLabel(self::LABEL_OTHER_STORE, $this->vendors[self::OTHER_STORE]),
            self::LABEL_SAME_STORE_DELETED => $this->makeLabel(self::LABEL_SAME_STORE_DELETED, $this->vendors[self::STORE], active: false),
            self::LABEL_SAME_STORE_ALT => $this->makeLabel(self::LABEL_SAME_STORE_ALT, $this->vendors[self::STORE]),
        ];
    }

    // ── POST /v3/admin/products ─────────────────────────────────────

    #[Test]
    public function createAssignsASameStoreLabel(): void
    {
        $res = $this->create(['label_id' => self::LABEL_SAME_STORE]);

        self::assertSame(201, $res->getStatusCode(), (string) $res->getBody());
        self::assertNotNull($this->saved);
        self::assertSame(self::STORE, $this->saved->getVendor()->getId());
        self::assertSame(self::LABEL_SAME_STORE, $this->saved->getLabelId());
        self::assertSame(self::LABEL_SAME_STORE, $this->jsonBody($res)['data']['label_id']);
    }

    #[Test]
    public function createRejectsAnotherStoresLabelAndPersistsNothing(): void
    {
        $res = $this->create(['label_id' => self::LABEL_OTHER_STORE]);

        self::assertSame(422, $res->getStatusCode(), (string) $res->getBody());
        $body = $this->jsonBody($res);
        self::assertSame('VALIDATION_FAILED', $body['error']['code']);
        self::assertArrayHasKey('label_id', $body['error']['details']['fields']);
        self::assertSame(0, $this->saveCalls, 'A product must not be created with another store\'s label.');
    }

    #[Test]
    public function createRejectsAnUnknownLabel(): void
    {
        $res = $this->create(['label_id' => 999]);

        self::assertSame(422, $res->getStatusCode(), (string) $res->getBody());
        self::assertArrayHasKey('label_id', $this->jsonBody($res)['error']['details']['fields']);
        self::assertSame(0, $this->saveCalls);
    }

    #[Test]
    public function createRejectsADeletedLabel(): void
    {
        $res = $this->create(['label_id' => self::LABEL_SAME_STORE_DELETED]);

        self::assertSame(422, $res->getStatusCode(), (string) $res->getBody());
        self::assertArrayHasKey('label_id', $this->jsonBody($res)['error']['details']['fields']);
        self::assertSame(0, $this->saveCalls);
    }

    #[Test]
    public function createWithoutLabelLeavesItUnset(): void
    {
        $res = $this->create([]);

        self::assertSame(201, $res->getStatusCode(), (string) $res->getBody());
        self::assertNotNull($this->saved);
        self::assertNull($this->saved->getLabelId());
    }

    #[Test]
    public function createRejectsAZeroLabelId(): void
    {
        // There is no "clear" value: label_id must be a positive id (DTO rule
        // shared with the vendor endpoints).
        $res = $this->create(['label_id' => 0]);

        self::assertSame(422, $res->getStatusCode(), (string) $res->getBody());
        self::assertSame(0, $this->saveCalls);
    }

    // ── PUT /v3/admin/products/{id} ─────────────────────────────────

    #[Test]
    public function updateAssignsASameStoreLabel(): void
    {
        $product = $this->makeProduct(100, $this->vendors[self::STORE]);

        $res = $this->update($product, ['label_id' => self::LABEL_SAME_STORE]);

        self::assertSame(200, $res->getStatusCode(), (string) $res->getBody());
        self::assertSame(1, $this->saveCalls);
        self::assertSame(self::LABEL_SAME_STORE, $product->getLabelId());
        self::assertSame(self::LABEL_SAME_STORE, $this->jsonBody($res)['data']['label_id']);
    }

    #[Test]
    public function updateReplacesTheExistingLabel(): void
    {
        $product = $this->makeProduct(100, $this->vendors[self::STORE]);
        $product->setLabelId(self::LABEL_SAME_STORE);

        $res = $this->update($product, ['label_id' => self::LABEL_SAME_STORE_ALT]);

        self::assertSame(200, $res->getStatusCode(), (string) $res->getBody());
        self::assertSame(self::LABEL_SAME_STORE_ALT, $product->getLabelId());
    }

    #[Test]
    public function updateRejectsAnotherStoresLabelAndChangesNothing(): void
    {
        $product = $this->makeProduct(100, $this->vendors[self::STORE]);
        $product->setLabelId(self::LABEL_SAME_STORE);

        $res = $this->update($product, [
            'name' => 'Renamed',
            'label_id' => self::LABEL_OTHER_STORE,
        ]);

        self::assertSame(422, $res->getStatusCode(), (string) $res->getBody());
        self::assertArrayHasKey('label_id', $this->jsonBody($res)['error']['details']['fields']);
        self::assertSame(0, $this->saveCalls, 'Nothing may be persisted on a rejected label.');
        self::assertSame(self::LABEL_SAME_STORE, $product->getLabelId());
        self::assertSame('Product 100', $product->getName(), 'Other fields must not be applied either.');
    }

    #[Test]
    public function updateRejectsADeletedLabelThatIsNotTheCurrentOne(): void
    {
        $product = $this->makeProduct(100, $this->vendors[self::STORE]);
        $product->setLabelId(self::LABEL_SAME_STORE);

        $res = $this->update($product, ['label_id' => self::LABEL_SAME_STORE_DELETED]);

        self::assertSame(422, $res->getStatusCode(), (string) $res->getBody());
        self::assertSame(0, $this->saveCalls);
        self::assertSame(self::LABEL_SAME_STORE, $product->getLabelId());
    }

    #[Test]
    public function updateWithoutLabelKeepsTheExistingOne(): void
    {
        $product = $this->makeProduct(100, $this->vendors[self::STORE]);
        $product->setLabelId(self::LABEL_SAME_STORE);

        $res = $this->update($product, ['name' => 'Renamed']);

        self::assertSame(200, $res->getStatusCode(), (string) $res->getBody());
        self::assertSame('Renamed', $product->getName());
        self::assertSame(self::LABEL_SAME_STORE, $product->getLabelId());
    }

    #[Test]
    public function updateWithNullLabelKeepsTheExistingOne(): void
    {
        $product = $this->makeProduct(100, $this->vendors[self::STORE]);
        $product->setLabelId(self::LABEL_SAME_STORE);

        $res = $this->update($product, ['label_id' => null]);

        self::assertSame(200, $res->getStatusCode(), (string) $res->getBody());
        self::assertSame(self::LABEL_SAME_STORE, $product->getLabelId());
    }

    #[Test]
    public function updateResendingTheCurrentLabelIsAcceptedEvenIfItWasDeleted(): void
    {
        // The edit form round-trips the stored label on every save; a label
        // soft-deleted since must not block unrelated edits.
        $product = $this->makeProduct(100, $this->vendors[self::STORE]);
        $product->setLabelId(self::LABEL_SAME_STORE_DELETED);

        $res = $this->update($product, ['name' => 'Renamed', 'label_id' => self::LABEL_SAME_STORE_DELETED]);

        self::assertSame(200, $res->getStatusCode(), (string) $res->getBody());
        self::assertSame('Renamed', $product->getName());
        self::assertSame(self::LABEL_SAME_STORE_DELETED, $product->getLabelId());
    }

    #[Test]
    public function updateRejectsAZeroLabelId(): void
    {
        $product = $this->makeProduct(100, $this->vendors[self::STORE]);
        $product->setLabelId(self::LABEL_SAME_STORE);

        $res = $this->update($product, ['label_id' => 0]);

        self::assertSame(422, $res->getStatusCode(), (string) $res->getBody());
        self::assertSame(0, $this->saveCalls);
        self::assertSame(self::LABEL_SAME_STORE, $product->getLabelId());
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
            fn (mixed $id): ?Vendor => $this->vendors[(int) $id] ?? null,
        );

        $catRepo = $this->createMock(CategoryRepository::class);
        $catRepo->method('find')->willReturn(null);

        $labelRepo = $this->createMock(VendorLabelRepository::class);
        $labelRepo->method('find')->willReturnCallback(
            fn (mixed $id): ?VendorLabel => $this->labels[(int) $id] ?? null,
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

    private function makeLabel(int $id, Vendor $vendor, bool $active = true): VendorLabel
    {
        $label = new VendorLabel($vendor, "label-{$id}", "Label {$id}");
        $label->setActive($active);
        $rp = new \ReflectionProperty(VendorLabel::class, 'id');
        $rp->setAccessible(true);
        $rp->setValue($label, $id);
        return $label;
    }

    private function makeProduct(int $id, Vendor $vendor): Product
    {
        $product = new Product($vendor, "product-{$id}", "Product {$id}");
        $rp = new \ReflectionProperty(Product::class, 'id');
        $rp->setAccessible(true);
        $rp->setValue($product, $id);
        $product->setPrice('100.00');
        return $product;
    }
}
