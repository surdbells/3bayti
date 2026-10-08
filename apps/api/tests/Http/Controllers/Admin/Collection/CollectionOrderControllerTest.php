<?php

declare(strict_types=1);

namespace Bayti\Api\Tests\Http\Controllers\Admin\Collection;

use Bayti\Api\Domain\Catalog\CollectionCurationService;
use Bayti\Api\Http\Controllers\Admin\Collection\CollectionCrudController;
use Bayti\Api\Tests\Http\HttpTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;

/**
 * Drag-and-drop collection order + member counts:
 *   PUT /v3/admin/collections/order   (catalog.collections_manage)
 *   "product_count" on every admin collection shape (list/get/order).
 */
#[CoversClass(CollectionCrudController::class)]
#[CoversClass(CollectionCurationService::class)]
final class CollectionOrderControllerTest extends HttpTestCase
{
    use InMemoryCollectionStore;

    protected function setUp(): void
    {
        parent::setUp();
        $this->resetStore();

        // Current order: 1 (0), 2 (1), 3 (2), then NULL display orders by id
        // DESC: 5, 4.
        $this->addCollection(1, 'One', 0);
        $this->addCollection(2, 'Two', 1);
        $this->addCollection(3, 'Three', 2);
        $this->addCollection(4, 'Four', null);
        $this->addCollection(5, 'Five', null, active: false);

        $this->addProduct(10, 'Ten');
        $this->addProduct(11, 'Eleven');
        $this->addMember(1, 10, 0);
        $this->addMember(1, 11, 1);
        $this->addMember(3, 10, 0);
    }

    // ── B: PUT /v3/admin/collections/order ──────────────────────────

    #[Test]
    public function reorderPutsListedFirstAndKeepsUnlistedRelativeOrderAfter(): void
    {
        $admin = $this->makeAdmin();
        $this->bindStore($admin);

        $res = $this->send($admin, 'PUT', '/v3/admin/collections/order', ['collection_ids' => [3, 4]]);

        self::assertSame(200, $res->getStatusCode(), (string) $res->getBody());
        $data = $this->jsonBody($res)['data'];
        // Listed [3, 4] first; unlisted keep their old relative order: 1, 2, 5.
        self::assertSame([3, 4, 1, 2, 5], array_column($data, 'id'));
        self::assertSame([0, 1, 2, 3, 4], array_column($data, 'display_order'));
        self::assertSame(5, $this->jsonBody($res)['meta']['total']);

        // display_order rewritten 0..n-1 on ALL collections (storefront order follows).
        $persistedOrder = [];
        foreach ($this->collections as $id => $c) {
            $persistedOrder[$id] = $c->getDisplayOrder();
        }
        self::assertSame([1 => 2, 2 => 3, 3 => 0, 4 => 1, 5 => 4], $persistedOrder);
        $this->assertSingleTransaction();
    }

    #[Test]
    public function reorderResponseUsesTheFullCollectionShapeWithProductCounts(): void
    {
        $admin = $this->makeAdmin();
        $this->bindStore($admin);

        $res = $this->send($admin, 'PUT', '/v3/admin/collections/order', ['collection_ids' => [2, 1, 3, 4, 5]]);

        self::assertSame(200, $res->getStatusCode(), (string) $res->getBody());
        $data = $this->jsonBody($res)['data'];
        self::assertSame([2, 1, 3, 4, 5], array_column($data, 'id'));
        self::assertSame([0, 2, 1, 0, 0], array_column($data, 'product_count'));
        foreach (['id', 'collection', 'name', 'slug', 'description', 'cover_image_url', 'is_active', 'display_order', 'product_count', 'created_at'] as $key) {
            self::assertArrayHasKey($key, $data[0]);
        }
        self::assertSame(1, $this->repoCalls['join.countsByCollection'] ?? 0, 'one grouped count for the whole list');
    }

    #[Test]
    public function reorderWithAnEmptyListNormalisesTheCurrentOrder(): void
    {
        $admin = $this->makeAdmin();
        $this->bindStore($admin);

        $res = $this->send($admin, 'PUT', '/v3/admin/collections/order', ['collection_ids' => []]);

        self::assertSame(200, $res->getStatusCode());
        self::assertSame([1, 2, 3, 5, 4], array_column($this->jsonBody($res)['data'], 'id'));
        self::assertSame(3, $this->collections[5]->getDisplayOrder());
        self::assertSame(4, $this->collections[4]->getDisplayOrder());
    }

    /** @return iterable<string, array{mixed}> */
    public static function invalidOrderBodies(): iterable
    {
        yield 'missing'   => [null];
        yield 'not array' => ['3,1'];
        yield 'duplicate' => [[3, 1, 3]];
        yield 'zero'      => [[0, 1]];
        yield 'negative'  => [[-1]];
        yield 'float'     => [[1.5]];
    }

    #[Test]
    #[DataProvider('invalidOrderBodies')]
    public function reorderRejectsMalformedIdsWith422(mixed $collectionIds): void
    {
        $admin = $this->makeAdmin();
        $this->bindStore($admin);

        $body = $collectionIds === null ? [] : ['collection_ids' => $collectionIds];
        $res = $this->send($admin, 'PUT', '/v3/admin/collections/order', $body);

        self::assertSame(422, $res->getStatusCode(), (string) $res->getBody());
        self::assertArrayHasKey('collection_ids', $this->jsonBody($res)['error']['details']['fields']);
        $this->assertOrderUnchanged();
    }

    #[Test]
    public function reorderRejectsUnknownIdsWith422ListingThem(): void
    {
        $admin = $this->makeAdmin();
        $this->bindStore($admin);

        $res = $this->send($admin, 'PUT', '/v3/admin/collections/order', ['collection_ids' => [3, 404, 1, 505]]);

        self::assertSame(422, $res->getStatusCode());
        $msg = $this->jsonBody($res)['error']['details']['fields']['collection_ids'][0];
        self::assertStringContainsString('404', $msg);
        self::assertStringContainsString('505', $msg);
        $this->assertOrderUnchanged();
    }

    #[Test]
    public function reorderRequiresCollectionsManagePermission(): void
    {
        $viewer = $this->makeStaffWith('catalog.collections_view');
        $this->bindStore($viewer);

        $res = $this->send($viewer, 'PUT', '/v3/admin/collections/order', ['collection_ids' => [3]]);

        self::assertSame(403, $res->getStatusCode());
        self::assertSame('catalog.collections_manage', $this->jsonBody($res)['error']['required_permission']);
        $this->assertOrderUnchanged();
    }

    #[Test]
    public function reorderIsAllowedWithCollectionsManagePermission(): void
    {
        $manager = $this->makeStaffWith('catalog.collections_manage');
        $this->bindStore($manager);

        $res = $this->send($manager, 'PUT', '/v3/admin/collections/order', ['collection_ids' => [2]]);

        self::assertSame(200, $res->getStatusCode(), (string) $res->getBody());
        self::assertSame(0, $this->collections[2]->getDisplayOrder());
    }

    #[Test]
    public function orderRouteDoesNotCollideWithTheNumericIdUpdateRoute(): void
    {
        $admin = $this->makeAdmin();
        $this->bindStore($admin);

        // /collections/order must reach reorder (a list response), never
        // update() with id="order".
        $res = $this->send($admin, 'PUT', '/v3/admin/collections/order', ['collection_ids' => [1]]);
        self::assertSame(200, $res->getStatusCode());
        self::assertTrue(array_is_list($this->jsonBody($res)['data']));

        // And the numeric update route still works next to it.
        $res = $this->send($admin, 'PUT', '/v3/admin/collections/2', ['name' => 'Two (renamed)']);
        self::assertSame(200, $res->getStatusCode(), (string) $res->getBody());
        self::assertSame('Two (renamed)', $this->jsonBody($res)['data']['name']);
    }

    // ── B: concurrent reorders are serialised ───────────────────────

    #[Test]
    public function reorderTakesTheOrderLockThenReadsWithRefresh(): void
    {
        $admin = $this->makeAdmin();
        $this->bindStore($admin);

        $res = $this->send($admin, 'PUT', '/v3/admin/collections/order', ['collection_ids' => [2]]);

        self::assertSame(200, $res->getStatusCode(), (string) $res->getBody());
        self::assertSame(['order'], $this->locksTaken());
        // refresh: an already-managed entity's in-memory display_order must
        // not be diffed against (it may predate the reorder that just held
        // the lock), or UPDATEs get skipped and positions collide.
        $this->assertLockedBeforeRead(['order'], 'read:findAllOrdered(refresh)');
        self::assertSame(0, $this->repoCalls['collections.findBy'] ?? 0, 'no entities hydrated outside the transaction');
        $this->assertSingleTransaction();
    }

    #[Test]
    public function reorderBuildsOnAReorderThatCommittedWhileItWaitedForTheLock(): void
    {
        $admin = $this->makeAdmin();
        $this->bindStore($admin);

        // Another admin's reorder to [2, 1, 3, 4, 5] commits while this one
        // waits for the lock. This request moves 3 to the top; everything it
        // did not list must follow the order JUST committed (2, 1, 4, 5), not
        // the order this request's page was loaded with, and positions must
        // come out as a clean 0..n-1 with no duplicates.
        $this->onLock = function (string $key): void {
            if ($key === 'order') {
                foreach ([2 => 0, 1 => 1, 3 => 2, 4 => 3, 5 => 4] as $id => $order) {
                    $this->collections[$id]->setDisplayOrder($order);
                }
            }
        };

        $res = $this->send($admin, 'PUT', '/v3/admin/collections/order', ['collection_ids' => [3]]);

        self::assertSame(200, $res->getStatusCode(), (string) $res->getBody());
        self::assertSame([3, 2, 1, 4, 5], array_column($this->jsonBody($res)['data'], 'id'));
        $orders = array_map(static fn ($c): ?int => $c->getDisplayOrder(), $this->collections);
        self::assertSame([1 => 2, 2 => 1, 3 => 0, 4 => 3, 5 => 4], $orders);
    }

    // ── position (1-based row in the admin Collections list) ────────

    #[Test]
    public function detailPositionIsTheListRowEvenWithGappyDisplayOrders(): void
    {
        $admin = $this->makeAdmin();
        $this->bindStore($admin);
        // Legacy hand-typed orders with gaps (never drag-sorted yet): 0, 10, 20.
        $this->collections[2]->setDisplayOrder(10);
        $this->collections[3]->setDisplayOrder(20);

        $three = $this->jsonBody($this->send($admin, 'GET', '/v3/admin/collections/3'))['data'];
        self::assertSame(20, $three['display_order']);
        self::assertSame(3, $three['position'], 'not display_order + 1 (21)');

        // NULL display orders sort last, newest (highest id) first: 5 then 4.
        self::assertSame(4, $this->jsonBody($this->send($admin, 'GET', '/v3/admin/collections/5'))['data']['position']);
        self::assertSame(5, $this->jsonBody($this->send($admin, 'GET', '/v3/admin/collections/4'))['data']['position']);
    }

    #[Test]
    public function listAndReorderCarryContiguousPositions(): void
    {
        $admin = $this->makeAdmin();
        $this->bindStore($admin);

        $page = $this->jsonBody($this->send($admin, 'GET', '/v3/admin/collections?limit=2&offset=2'))['data'];
        self::assertSame([3, 5], array_column($page, 'id'));
        self::assertSame([3, 4], array_column($page, 'position'));

        $res = $this->send($admin, 'PUT', '/v3/admin/collections/order', ['collection_ids' => [4]]);
        self::assertSame([1, 2, 3, 4, 5], array_column($this->jsonBody($res)['data'], 'position'));
        self::assertSame(4, $this->jsonBody($res)['data'][0]['id']);
    }

    #[Test]
    public function updateResponseCarriesThePosition(): void
    {
        $admin = $this->makeAdmin();
        $this->bindStore($admin);

        $res = $this->send($admin, 'PUT', '/v3/admin/collections/2', ['name' => 'Two!']);

        self::assertSame(200, $res->getStatusCode(), (string) $res->getBody());
        self::assertSame(2, $this->jsonBody($res)['data']['position']);
    }

    // ── E: product_count on list / get ──────────────────────────────

    #[Test]
    public function listIncludesProductCountsFromOneGroupedQuery(): void
    {
        $admin = $this->makeAdmin();
        $this->bindStore($admin);

        $res = $this->send($admin, 'GET', '/v3/admin/collections?limit=100');

        self::assertSame(200, $res->getStatusCode(), (string) $res->getBody());
        $data = $this->jsonBody($res)['data'];
        self::assertSame([1, 2, 3, 5, 4], array_column($data, 'id'));
        self::assertSame([2, 0, 1, 0, 0], array_column($data, 'product_count'));
        self::assertSame(1, $this->repoCalls['join.countsByCollection'] ?? 0, 'no per-collection N+1');
    }

    #[Test]
    public function detailIncludesProductCount(): void
    {
        $admin = $this->makeAdmin();
        $this->bindStore($admin);

        $res = $this->send($admin, 'GET', '/v3/admin/collections/1');

        self::assertSame(200, $res->getStatusCode());
        self::assertSame(2, $this->jsonBody($res)['data']['product_count']);
    }

    private function assertOrderUnchanged(): void
    {
        self::assertSame(0, $this->flushes, 'nothing flushed');
        $order = [];
        foreach ($this->collections as $id => $c) {
            $order[$id] = $c->getDisplayOrder();
        }
        self::assertSame([1 => 0, 2 => 1, 3 => 2, 4 => null, 5 => null], $order);
    }
}
