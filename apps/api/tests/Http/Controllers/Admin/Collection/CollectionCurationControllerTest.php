<?php

declare(strict_types=1);

namespace Bayti\Api\Tests\Http\Controllers\Admin\Collection;

use Bayti\Api\Domain\Catalog\CollectionProduct;
use Bayti\Api\Domain\Catalog\CollectionProductRepository;
use Bayti\Api\Domain\Catalog\Product;
use Bayti\Api\Domain\Catalog\ProductCollection;
use Bayti\Api\Domain\Catalog\ProductCollectionRepository;
use Bayti\Api\Domain\Catalog\ProductRepository;
use Bayti\Api\Domain\Catalog\Vendor;
use Bayti\Api\Domain\User\User;
use Bayti\Api\Domain\User\UserRepository;
use Bayti\Api\Http\Controllers\Admin\Collection\CollectionCrudController;
use Bayti\Api\Infrastructure\Auth\JwtService;
use Bayti\Api\Tests\Http\HttpTestCase;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use Psr\Http\Message\ResponseInterface;

/**
 * Admin product-curation for collections:
 *   GET /v3/admin/collections/{id}/products   (catalog.collections_view)
 *   PUT /v3/admin/collections/{id}/products   (catalog.collections_manage)
 */
#[CoversClass(CollectionCrudController::class)]
final class CollectionCurationControllerTest extends HttpTestCase
{
    /** @var list<CollectionProduct> */
    private array $persisted = [];
    /** @var list<list<int>> collection ids passed to each lockCollections() call */
    private array $lockCalls = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->persisted = [];
        $this->lockCalls = [];
    }

    #[Test]
    public function setProductsReplacesMembershipInOrder(): void
    {
        $admin = $this->makeAdminUser(99);
        $col = $this->makeCollection(5, 'summer-edit', 'Summer Edit');
        $vendor = $this->makeVendor(1, 'almas');
        $products = [
            7 => $this->makeProduct($vendor, 7, 'p-seven', 'Seven'),
            3 => $this->makeProduct($vendor, 3, 'p-three', 'Three'),
        ];
        $this->bindEnv($admin, $col, $products);

        // Curation order [7, 3] → sort_order 0, 1 respectively.
        $response = $this->makePut($admin, '/v3/admin/collections/5/products', [
            'product_ids' => [7, 3],
        ]);

        self::assertSame(200, $response->getStatusCode());
        $body = $this->jsonBody($response);
        self::assertCount(2, $body['data']);
        self::assertSame('p-seven', $body['data'][0]['slug']);
        self::assertSame('p-three', $body['data'][1]['slug']);

        // Full re-set of an empty collection: locked once, then two
        // membership rows inserted in order (through the UnitOfWork).
        self::assertSame([[5]], $this->lockCalls);
        self::assertCount(2, $this->persisted);
        self::assertSame(7, $this->persisted[0]->getProduct()->getId());
        self::assertSame(0, $this->persisted[0]->getSortOrder());
        self::assertSame(3, $this->persisted[1]->getProduct()->getId());
        self::assertSame(1, $this->persisted[1]->getSortOrder());
    }

    #[Test]
    public function setProductsRejectsAnUnknownProductId(): void
    {
        $admin = $this->makeAdminUser(99);
        $col = $this->makeCollection(5, 'summer-edit', 'Summer Edit');
        $vendor = $this->makeVendor(1, 'almas');
        // Only product 7 exists; 999 does not.
        $this->bindEnv($admin, $col, [7 => $this->makeProduct($vendor, 7, 'p-seven', 'Seven')]);

        $response = $this->makePut($admin, '/v3/admin/collections/5/products', [
            'product_ids' => [7, 999],
        ]);

        self::assertSame(422, $response->getStatusCode());
        self::assertStringContainsString('999', json_encode($this->jsonBody($response)) ?: '');
        self::assertCount(0, $this->persisted, 'nothing persisted when validation fails');
        self::assertSame([], $this->lockCalls, 'membership untouched when validation fails');
    }

    #[Test]
    public function setProductsRejectsANonArrayBody(): void
    {
        $admin = $this->makeAdminUser(99);
        $col = $this->makeCollection(5, 'summer-edit', 'Summer Edit');
        $this->bindEnv($admin, $col, []);

        $response = $this->makePut($admin, '/v3/admin/collections/5/products', [
            'product_ids' => 'nope',
        ]);

        self::assertSame(400, $response->getStatusCode());
    }

    #[Test]
    public function setProductsRequiresManagePermission(): void
    {
        $regular = $this->makeUser(id: 200);
        $col = $this->makeCollection(5, 'summer-edit', 'Summer Edit');
        $this->bindEnv($regular, $col, []);

        $response = $this->makePut($regular, '/v3/admin/collections/5/products', [
            'product_ids' => [],
        ]);

        self::assertSame(403, $response->getStatusCode());
    }

    #[Test]
    public function listProductsReturnsCuratedMembersInOrder(): void
    {
        $admin = $this->makeAdminUser(99);
        $col = $this->makeCollection(5, 'summer-edit', 'Summer Edit');
        $vendor = $this->makeVendor(1, 'almas');
        $this->bindEnv($admin, $col, [
            7 => $this->makeProduct($vendor, 7, 'p-seven', 'Seven'),
            3 => $this->makeProduct($vendor, 3, 'p-three', 'Three'),
        ], memberIds: [7, 3]);

        $response = $this->makeGet($admin, '/v3/admin/collections/5/products');

        self::assertSame(200, $response->getStatusCode());
        $body = $this->jsonBody($response);
        self::assertSame(['p-seven', 'p-three'], array_column($body['data'], 'slug'));
    }

    #[Test]
    public function updateRejectsAnOverlongNameWith422(): void
    {
        $admin = $this->makeAdminUser(99);
        $this->bindEnv($admin, $this->makeCollection(5, 'summer-edit', 'Summer Edit'), []);

        $response = $this->makePut($admin, '/v3/admin/collections/5', ['name' => str_repeat('a', 201)]);

        self::assertSame(422, $response->getStatusCode());
    }

    #[Test]
    public function updateRejectsAnOutOfRangeDisplayOrderWith422(): void
    {
        $admin = $this->makeAdminUser(99);
        $this->bindEnv($admin, $this->makeCollection(5, 'summer-edit', 'Summer Edit'), []);

        self::assertSame(422, $this->makePut($admin, '/v3/admin/collections/5', ['display_order' => 40000])->getStatusCode());
        self::assertSame(422, $this->makePut($admin, '/v3/admin/collections/5', ['display_order' => -1])->getStatusCode());
    }

    #[Test]
    public function updateStoresABlankOrNullDescriptionAsNull(): void
    {
        $admin = $this->makeAdminUser(99);
        $col = $this->makeCollection(5, 'summer-edit', 'Summer Edit');
        $col->setDescription('Old copy');
        $this->bindEnv($admin, $col, []);

        self::assertSame(200, $this->makePut($admin, '/v3/admin/collections/5', ['description' => null])->getStatusCode());
        self::assertNull($col->getDescription());

        $col->setDescription('Old copy');
        self::assertSame(200, $this->makePut($admin, '/v3/admin/collections/5', ['description' => '   '])->getStatusCode());
        self::assertNull($col->getDescription());
    }

    // ===== Helpers =====

    /**
     * @param array<int, Product> $productsById
     * @param list<int> $memberIds
     */
    private function bindEnv(User $user, ProductCollection $col, array $productsById, array $memberIds = []): void
    {
        $userRepo = $this->createMock(UserRepository::class);
        $userRepo->method('findById')->willReturn($user);

        $collectionRepo = $this->createMock(ProductCollectionRepository::class);
        $collectionRepo->method('find')->willReturn($col);

        $productRepo = $this->createMock(ProductRepository::class);
        $productRepo->method('findBy')->willReturnCallback(
            function (array $criteria) use ($productsById): array {
                $ids = $criteria['id'] ?? [];
                $out = [];
                foreach ((array) $ids as $id) {
                    if (isset($productsById[(int) $id])) {
                        $out[] = $productsById[(int) $id];
                    }
                }
                return $out;
            },
        );

        $joinRepo = $this->createMock(CollectionProductRepository::class);
        $joinRepo->method('productIdsForCollection')->willReturn($memberIds);
        $joinRepo->method('lockCollections')->willReturnCallback(function (array $ids): void {
            $this->lockCalls[] = $ids;
        });
        $joinRepo->method('findForCollection')->willReturn([]);

        $em = $this->stubEm(function ($em) use ($userRepo, $collectionRepo, $productRepo, $joinRepo): void {
            $em->method('getRepository')->willReturnMap([
                [User::class, $userRepo],
                [ProductCollection::class, $collectionRepo],
                [Product::class, $productRepo],
                [CollectionProduct::class, $joinRepo],
            ]);
            $em->method('persist')->willReturnCallback(function (object $e): void {
                if ($e instanceof CollectionProduct) {
                    $this->persisted[] = $e;
                }
            });
        });
        $this->bind(EntityManagerInterface::class, $em);
    }

    private function makeAdminUser(int $id): User
    {
        $u = $this->makeUser(id: $id);
        $u->setRoles(admin: true);
        return $u;
    }

    private function makeCollection(int $id, string $slug, string $name): ProductCollection
    {
        $c = new ProductCollection($name, $slug);
        $ref = new \ReflectionProperty(ProductCollection::class, 'id');
        $ref->setAccessible(true);
        $ref->setValue($c, $id);
        return $c;
    }

    private function makeVendor(int $id, string $slug): Vendor
    {
        $v = new Vendor($slug, ucfirst($slug), 'v@example.test');
        $ref = new \ReflectionProperty(Vendor::class, 'id');
        $ref->setAccessible(true);
        $ref->setValue($v, $id);
        return $v;
    }

    private function makeProduct(Vendor $vendor, int $id, string $slug, string $name): Product
    {
        $p = new Product($vendor, $slug, $name);
        $ref = new \ReflectionProperty(Product::class, 'id');
        $ref->setAccessible(true);
        $ref->setValue($p, $id);
        return $p;
    }

    /** @param array<string, mixed> $body */
    private function makePut(User $user, string $uri, array $body): ResponseInterface
    {
        $jwt = $this->app->getContainer()->get(JwtService::class);
        $pair = $jwt->issueTokenPair($user);
        return $this->handle($this->jsonRequest('PUT', $uri, $body, [
            'Authorization' => 'Bearer ' . $pair->accessToken,
        ]));
    }

    private function makeGet(User $user, string $uri): ResponseInterface
    {
        $jwt = $this->app->getContainer()->get(JwtService::class);
        $pair = $jwt->issueTokenPair($user);
        return $this->handle($this->jsonRequest('GET', $uri, [], [
            'Authorization' => 'Bearer ' . $pair->accessToken,
        ]));
    }
}
