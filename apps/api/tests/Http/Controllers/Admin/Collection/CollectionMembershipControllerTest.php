<?php

declare(strict_types=1);

namespace Bayti\Api\Tests\Http\Controllers\Admin\Collection;

use Bayti\Api\Domain\Catalog\CollectionCurationService;
use Bayti\Api\Http\Controllers\Admin\Collection\CollectionMembershipController;
use Bayti\Api\Http\Validator\IdListParser;
use Bayti\Api\Tests\Http\HttpTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;

/**
 * Product-side collection membership (a product can be in MANY collections):
 *   GET /v3/admin/collections/memberships?product_ids=…   (catalog.collections_view)
 *   GET /v3/admin/products/{id}/collections               (catalog.collections_view)
 *   PUT /v3/admin/products/{id}/collections               (catalog.collections_manage)
 * plus the collection-side PUT /v3/admin/collections/{id}/products proving a
 * product curated into one collection is NOT removed from another.
 */
#[CoversClass(CollectionMembershipController::class)]
#[CoversClass(CollectionCurationService::class)]
#[CoversClass(IdListParser::class)]
final class CollectionMembershipControllerTest extends HttpTestCase
{
    use InMemoryCollectionStore;

    protected function setUp(): void
    {
        parent::setUp();
        $this->resetStore();

        // Collection display order: Eid (0), Summer (1), Gifts (null → last).
        $this->addCollection(5, 'Summer', 1);
        $this->addCollection(6, 'Eid', 0);
        $this->addCollection(8, 'Gifts', null, active: false);
        $this->addCollection(9, 'Empty', 2);

        $this->addProduct(7, 'Seven');
        $this->addProduct(3, 'Three');
        $this->addProduct(4, 'Four');

        // Product 7 is in THREE collections; product 3 in one; product 4 in none.
        $this->addMember(5, 3, 0);
        $this->addMember(5, 7, 1);
        $this->addMember(6, 7, 4);
        $this->addMember(8, 7, 0);
    }

    // ── A: GET /v3/admin/collections/memberships ────────────────────

    #[Test]
    public function membershipsReturnsAKeyForEveryRequestedIdInDisplayOrder(): void
    {
        $admin = $this->makeAdmin();
        $this->bindStore($admin);

        // 4 has no memberships, 12345 is not a product at all.
        $res = $this->send($admin, 'GET', '/v3/admin/collections/memberships?product_ids=7,3,4,12345');

        self::assertSame(200, $res->getStatusCode(), (string) $res->getBody());
        $data = $this->jsonBody($res)['data'];
        self::assertSame(['7', '3', '4', '12345'], array_map('strval', array_keys($data)));

        // Ordered by collection display_order ASC NULLS LAST: Eid(0), Summer(1), Gifts(null).
        self::assertSame([6, 5, 8], array_column($data['7'], 'id'));
        self::assertSame(
            ['id' => 6, 'name' => 'Eid', 'slug' => 'eid', 'is_active' => true],
            $data['7'][0],
        );
        self::assertFalse($data['7'][2]['is_active'], 'inactive collections are still reported');
        self::assertSame([5], array_column($data['3'], 'id'));
        self::assertSame([], $data['4']);
        self::assertSame([], $data['12345']);

        // One batch query for the whole page, not one per product.
        self::assertSame(1, $this->repoCalls['join.membershipsForProducts'] ?? 0);
    }

    #[Test]
    public function membershipsIsAJsonObjectEvenForASingleUnassignedId(): void
    {
        $admin = $this->makeAdmin();
        $this->bindStore($admin);

        $res = $this->send($admin, 'GET', '/v3/admin/collections/memberships?product_ids=4');

        self::assertSame(200, $res->getStatusCode());
        self::assertStringContainsString('"data":{"4":[]}', (string) $res->getBody());
    }

    #[Test]
    public function membershipsCollapsesDuplicateIdsAndToleratesSpaces(): void
    {
        $admin = $this->makeAdmin();
        $this->bindStore($admin);

        $res = $this->send($admin, 'GET', '/v3/admin/collections/memberships?product_ids=' . rawurlencode('3, 3 ,7'));

        self::assertSame(200, $res->getStatusCode(), (string) $res->getBody());
        self::assertSame(['3', '7'], array_map('strval', array_keys($this->jsonBody($res)['data'])));
    }

    /** @return iterable<string, array{string}> */
    public static function invalidProductIds(): iterable
    {
        yield 'missing'        => [''];
        yield 'empty'          => ['?product_ids='];
        yield 'blank'          => ['?product_ids=%20'];
        yield 'non-numeric'    => ['?product_ids=7,abc'];
        yield 'zero'           => ['?product_ids=0'];
        yield 'negative'       => ['?product_ids=-3'];
        yield 'empty token'    => ['?product_ids=7,,3'];
        yield 'decimal'        => ['?product_ids=7.5'];
        yield 'array form'     => ['?product_ids[]=7'];
        yield 'too many (201)' => ['?product_ids=' . implode(',', range(1, 201))];
    }

    #[Test]
    #[DataProvider('invalidProductIds')]
    public function membershipsRejectsInvalidProductIdsWith422(string $query): void
    {
        $admin = $this->makeAdmin();
        $this->bindStore($admin);

        $res = $this->send($admin, 'GET', '/v3/admin/collections/memberships' . $query);

        self::assertSame(422, $res->getStatusCode(), (string) $res->getBody());
        self::assertArrayHasKey('product_ids', $this->jsonBody($res)['error']['details']['fields']);
        self::assertSame(0, $this->repoCalls['join.membershipsForProducts'] ?? 0);
    }

    #[Test]
    public function membershipsAcceptsExactly200DistinctIds(): void
    {
        $admin = $this->makeAdmin();
        $this->bindStore($admin);

        $res = $this->send($admin, 'GET', '/v3/admin/collections/memberships?product_ids=' . implode(',', range(1, 200)));

        self::assertSame(200, $res->getStatusCode());
        self::assertCount(200, $this->jsonBody($res)['data']);
    }

    #[Test]
    public function membershipsRequiresCollectionsViewPermission(): void
    {
        $staff = $this->makeStaffWithoutPermissions();
        $this->bindStore($staff);

        $res = $this->send($staff, 'GET', '/v3/admin/collections/memberships?product_ids=7');

        self::assertSame(403, $res->getStatusCode());
        self::assertSame('catalog.collections_view', $this->jsonBody($res)['error']['required_permission']);
    }

    #[Test]
    public function membershipsIsReadableWithViewOnlyPermission(): void
    {
        $viewer = $this->makeStaffWith('catalog.collections_view');
        $this->bindStore($viewer);

        $res = $this->send($viewer, 'GET', '/v3/admin/collections/memberships?product_ids=7');

        self::assertSame(200, $res->getStatusCode());
    }

    // ── C: GET /v3/admin/products/{id}/collections ──────────────────

    #[Test]
    public function productCollectionsListsEveryCollectionTheProductIsIn(): void
    {
        $admin = $this->makeAdmin();
        $this->bindStore($admin);

        $res = $this->send($admin, 'GET', '/v3/admin/products/7/collections');

        self::assertSame(200, $res->getStatusCode(), (string) $res->getBody());
        self::assertSame([
            ['id' => 6, 'name' => 'Eid', 'slug' => 'eid', 'is_active' => true, 'sort_order' => 4],
            ['id' => 5, 'name' => 'Summer', 'slug' => 'summer', 'is_active' => true, 'sort_order' => 1],
            ['id' => 8, 'name' => 'Gifts', 'slug' => 'gifts', 'is_active' => false, 'sort_order' => 0],
        ], $this->jsonBody($res)['data']);
    }

    #[Test]
    public function productCollectionsIsEmptyForAnUnassignedProduct(): void
    {
        $admin = $this->makeAdmin();
        $this->bindStore($admin);

        $res = $this->send($admin, 'GET', '/v3/admin/products/4/collections');

        self::assertSame(200, $res->getStatusCode());
        self::assertSame('{"data":[]}', (string) $res->getBody());
    }

    #[Test]
    public function productCollectionsReturns404ForAnUnknownProduct(): void
    {
        $admin = $this->makeAdmin();
        $this->bindStore($admin);

        self::assertSame(404, $this->send($admin, 'GET', '/v3/admin/products/12345/collections')->getStatusCode());
    }

    #[Test]
    public function productCollectionsRequiresCollectionsViewPermission(): void
    {
        $staff = $this->makeStaffWithoutPermissions();
        $this->bindStore($staff);

        $res = $this->send($staff, 'GET', '/v3/admin/products/7/collections');

        self::assertSame(403, $res->getStatusCode());
        self::assertSame('catalog.collections_view', $this->jsonBody($res)['error']['required_permission']);
    }

    // ── D: PUT /v3/admin/products/{id}/collections ──────────────────

    #[Test]
    public function setCollectionsAppendsRetainsAndRemovesInOneTransaction(): void
    {
        $admin = $this->makeAdmin();
        $this->bindStore($admin);

        // 7 is in 5 (sort 1), 6 (sort 4), 8 (sort 0). New set: keep 5, drop 6
        // and 8, add 9 (empty → sort 0). Duplicate 5 collapses.
        $res = $this->send($admin, 'PUT', '/v3/admin/products/7/collections', ['collection_ids' => [9, 5, 5]]);

        self::assertSame(200, $res->getStatusCode(), (string) $res->getBody());
        self::assertSame([5 => 1, 9 => 0], $this->membershipOf(7), 'kept 5 at its sort_order, added 9 at 0');
        self::assertCount(1, $this->persisted);
        self::assertCount(2, $this->removed);
        $this->assertSingleTransaction();
        // Other products' memberships untouched.
        self::assertSame([5 => 0], $this->membershipOf(3));
        // Response = the product's collections (C shape), display order: Summer(1), Empty(2).
        self::assertSame([
            ['id' => 5, 'name' => 'Summer', 'slug' => 'summer', 'is_active' => true, 'sort_order' => 1],
            ['id' => 9, 'name' => 'Empty', 'slug' => 'empty', 'is_active' => true, 'sort_order' => 0],
        ], $this->jsonBody($res)['data']);
    }

    #[Test]
    public function setCollectionsAppendsAtTheEndOfANonEmptyCollection(): void
    {
        $admin = $this->makeAdmin();
        $this->bindStore($admin);

        // Product 4 joins Summer (members: 3@0, 7@1) and Eid (7@4).
        $res = $this->send($admin, 'PUT', '/v3/admin/products/4/collections', ['collection_ids' => [5, 6]]);

        self::assertSame(200, $res->getStatusCode(), (string) $res->getBody());
        self::assertSame([5 => 2, 6 => 5], $this->membershipOf(4), 'sort_order = current max + 1');
        self::assertSame([3, 7, 4], $this->membersOf(5), 'existing curation (and its card image) unchanged');
        self::assertSame(1, $this->repoCalls['join.maxSortOrders'] ?? 0, 'one grouped MAX query for all added collections');
    }

    #[Test]
    public function setCollectionsLeavesRetainedMembershipsUntouched(): void
    {
        $admin = $this->makeAdmin();
        $this->bindStore($admin);

        // Same set as today → no writes at all.
        $res = $this->send($admin, 'PUT', '/v3/admin/products/7/collections', ['collection_ids' => [8, 6, 5]]);

        self::assertSame(200, $res->getStatusCode());
        self::assertSame([5 => 1, 6 => 4, 8 => 0], $this->membershipOf(7));
        self::assertSame([], $this->persisted);
        self::assertSame([], $this->removed);
        self::assertSame(0, $this->repoCalls['join.maxSortOrders'] ?? 0);
    }

    #[Test]
    public function setCollectionsWithAnEmptyArrayRemovesTheProductFromEveryCollection(): void
    {
        $admin = $this->makeAdmin();
        $this->bindStore($admin);

        $res = $this->send($admin, 'PUT', '/v3/admin/products/7/collections', ['collection_ids' => []]);

        self::assertSame(200, $res->getStatusCode());
        self::assertSame([], $this->membershipOf(7));
        self::assertCount(3, $this->removed);
        $this->assertSingleTransaction();
        self::assertSame('{"data":[]}', (string) $res->getBody());
        self::assertSame([5 => 0], $this->membershipOf(3), 'other products stay put');
    }

    #[Test]
    public function setCollectionsRejectsUnknownCollectionIdsWith422AndChangesNothing(): void
    {
        $admin = $this->makeAdmin();
        $this->bindStore($admin);

        $res = $this->send($admin, 'PUT', '/v3/admin/products/7/collections', ['collection_ids' => [5, 777, 888]]);

        self::assertSame(422, $res->getStatusCode());
        $msg = $this->jsonBody($res)['error']['details']['fields']['collection_ids'][0];
        self::assertStringContainsString('777', $msg);
        self::assertStringContainsString('888', $msg);
        self::assertSame([5 => 1, 6 => 4, 8 => 0], $this->membershipOf(7));
        self::assertSame(0, $this->flushes);
    }

    /** @return iterable<string, array{mixed}> */
    public static function invalidCollectionIdsBodies(): iterable
    {
        yield 'missing'      => [null];
        yield 'string'       => ['5,6'];
        yield 'object'       => [['a' => 5]];
        yield 'zero'         => [[0]];
        yield 'negative'     => [[-5]];
        yield 'float'        => [[5.5]];
        yield 'nested array' => [[[5]]];
    }

    #[Test]
    #[DataProvider('invalidCollectionIdsBodies')]
    public function setCollectionsRejectsMalformedIdsWith422(mixed $collectionIds): void
    {
        $admin = $this->makeAdmin();
        $this->bindStore($admin);

        $body = $collectionIds === null ? [] : ['collection_ids' => $collectionIds];
        $res = $this->send($admin, 'PUT', '/v3/admin/products/7/collections', $body);

        self::assertSame(422, $res->getStatusCode(), (string) $res->getBody());
        self::assertArrayHasKey('collection_ids', $this->jsonBody($res)['error']['details']['fields']);
        self::assertSame(0, $this->flushes);
    }

    #[Test]
    public function setCollectionsReturns404ForAnUnknownProduct(): void
    {
        $admin = $this->makeAdmin();
        $this->bindStore($admin);

        $res = $this->send($admin, 'PUT', '/v3/admin/products/12345/collections', ['collection_ids' => [5]]);

        self::assertSame(404, $res->getStatusCode());
        self::assertSame([], $this->persisted);
    }

    #[Test]
    public function setCollectionsRequiresCollectionsManagePermission(): void
    {
        // View-only staff can read memberships but not change them.
        $viewer = $this->makeStaffWith('catalog.collections_view');
        $this->bindStore($viewer);

        $res = $this->send($viewer, 'PUT', '/v3/admin/products/7/collections', ['collection_ids' => []]);

        self::assertSame(403, $res->getStatusCode());
        self::assertSame('catalog.collections_manage', $this->jsonBody($res)['error']['required_permission']);
        self::assertSame([5 => 1, 6 => 4, 8 => 0], $this->membershipOf(7));
    }

    // ── D: concurrency (locks, precondition, unique-key backstop) ───

    #[Test]
    public function setCollectionsLocksTheProductThenEveryTouchedCollectionAscendingBeforeReading(): void
    {
        $admin = $this->makeAdmin();
        $this->bindStore($admin);

        // 7 is in 5, 6, 8; new set [9, 5] touches 5 (kept), 6 + 8 (removed), 9 (added).
        $res = $this->send($admin, 'PUT', '/v3/admin/products/7/collections', ['collection_ids' => [9, 5]]);

        self::assertSame(200, $res->getStatusCode(), (string) $res->getBody());
        self::assertSame(
            ['product:7', 'collection:5', 'collection:6', 'collection:8', 'collection:9'],
            $this->locksTaken(),
            'product first, then every touched collection in ascending id order (global lock order: no deadlocks)',
        );
        $this->assertLockedBeforeRead(['product:7', 'collection:9'], 'read:findForProduct');
        $this->assertLockedBeforeRead(['collection:9'], 'read:maxSortOrders');
        $this->assertSingleTransaction();
    }

    #[Test]
    public function setCollectionsSeesARowAnotherAdminCommittedWhileItWaitedForTheLock(): void
    {
        $admin = $this->makeAdmin();
        $this->bindStore($admin);

        // While this request waits for Summer's lock, a collection builder
        // commits product 4 into Summer (sort 9). Because the membership is
        // read AFTER the lock, this write sees it: no duplicate INSERT (which
        // would hit UNIQUE(collection_id, product_id) → 500), the existing row
        // is simply kept.
        $this->onLock = function (string $key): void {
            if ($key === 'collection:5') {
                $this->commitConcurrently(5, 4, 9);
            }
        };

        $res = $this->send($admin, 'PUT', '/v3/admin/products/4/collections', ['collection_ids' => [5, 6]]);

        self::assertSame(200, $res->getStatusCode(), (string) $res->getBody());
        self::assertSame([5 => 9, 6 => 5], $this->membershipOf(4), 'kept the committed Summer row; appended to Eid at max + 1');
        self::assertCount(1, $this->persisted, 'only Eid inserted');
        $this->assertSingleTransaction();
    }

    #[Test]
    public function setCollectionsTurnsALostUniqueKeyRaceInto409AndRollsBack(): void
    {
        $admin = $this->makeAdmin();
        $this->bindStore($admin);

        // A writer that ignores the membership locks inserts (9, 7) right
        // after this request read the product's rows: the INSERT of the same
        // pair fails at flush. Must be a clean 409 (not a 500), with this
        // request's other changes (removing 5, 6, 8) rolled back.
        $this->afterRead = function (string $read): void {
            if ($read === 'findForProduct') {
                $this->commitConcurrently(9, 7, 0);
            }
        };

        $res = $this->send($admin, 'PUT', '/v3/admin/products/7/collections', ['collection_ids' => [9]]);

        self::assertSame(409, $res->getStatusCode(), (string) $res->getBody());
        $error = $this->jsonBody($res)['error'];
        self::assertSame('CONFLICT_STALE', $error['code']);
        self::assertArrayNotHasKey('details', $error, 'current state unknown after a failed flush');
        self::assertSame(1, $this->rollbacks);
        self::assertSame([5 => 1, 6 => 4, 8 => 0, 9 => 0], $this->membershipOf(7), 'our removals rolled back; the other writer\'s row stays');
    }

    #[Test]
    public function setCollectionsAppliesWhenTheExpectedSetMatchesInAnyOrder(): void
    {
        $admin = $this->makeAdmin();
        $this->bindStore($admin);

        $res = $this->send($admin, 'PUT', '/v3/admin/products/7/collections', [
            'collection_ids'          => [5],
            'expected_collection_ids' => [8, 5, 6, 6],
        ]);

        self::assertSame(200, $res->getStatusCode(), (string) $res->getBody());
        self::assertSame([5 => 1], $this->membershipOf(7));
    }

    #[Test]
    public function setCollectionsWithAStaleExpectedSetReturns409WithTheCurrentSetAndWritesNothing(): void
    {
        $admin = $this->makeAdmin();
        $this->bindStore($admin);

        // The product page loaded 7 in {5, 6}; a collection builder has since
        // added it to Gifts (8). Removing "6" from the stale view must not
        // silently drop it from Gifts as well.
        $res = $this->send($admin, 'PUT', '/v3/admin/products/7/collections', [
            'collection_ids'          => [5],
            'expected_collection_ids' => [5, 6],
        ]);

        self::assertSame(409, $res->getStatusCode(), (string) $res->getBody());
        $error = $this->jsonBody($res)['error'];
        self::assertSame('CONFLICT_STALE', $error['code']);
        self::assertSame([5, 6, 8], $error['details']['current_collection_ids']);
        self::assertSame([5 => 1, 6 => 4, 8 => 0], $this->membershipOf(7));
        self::assertSame([], $this->persisted);
        self::assertSame([], $this->removed);
        self::assertSame(0, $this->rollbacks, 'refused cleanly, not via an exception inside the transaction');
    }

    /** @return iterable<string, array{mixed}> */
    public static function invalidExpectedIds(): iterable
    {
        yield 'string' => ['5,6'];
        yield 'zero'   => [[0]];
        yield 'object' => [['a' => 5]];
    }

    #[Test]
    #[DataProvider('invalidExpectedIds')]
    public function setCollectionsRejectsAMalformedExpectedSetWith422(mixed $expected): void
    {
        $admin = $this->makeAdmin();
        $this->bindStore($admin);

        $res = $this->send($admin, 'PUT', '/v3/admin/products/7/collections', [
            'collection_ids'          => [5],
            'expected_collection_ids' => $expected,
        ]);

        self::assertSame(422, $res->getStatusCode(), (string) $res->getBody());
        self::assertArrayHasKey('expected_collection_ids', $this->jsonBody($res)['error']['details']['fields']);
        self::assertSame(0, $this->transactions);
    }

    // ── collection-side PUT /collections/{id}/products ─────────────

    #[Test]
    public function setProductsDiffsThroughTheUnitOfWorkSoEveryChangeIsAudited(): void
    {
        $admin = $this->makeAdmin();
        $this->bindStore($admin);
        $keptRow = $this->rowsOfCollection(5)[1]; // product 7 @ 1

        // Summer is [3@0, 7@1]; re-set to [7, 4]: drop 3, move 7 to 0, add 4 @ 1.
        $res = $this->send($admin, 'PUT', '/v3/admin/collections/5/products', ['product_ids' => [7, 4]]);

        self::assertSame(200, $res->getStatusCode(), (string) $res->getBody());
        self::assertSame([7, 4], $this->membersOf(5));
        // Entity-level changes only (EntityAuditListener sees each one): one
        // delete, one insert, and the kept row UPDATEd in place (same id), not
        // a bulk DELETE + re-INSERT of every member.
        self::assertSame([3], array_map(static fn ($r): int => (int) $r->getProduct()->getId(), $this->removed));
        self::assertSame([4], array_map(static fn ($r): int => (int) $r->getProduct()->getId(), $this->persisted));
        self::assertSame($keptRow, $this->rowsOfCollection(5)[0]);
        self::assertSame(0, $keptRow->getSortOrder());
        self::assertSame([5 => 0, 6 => 4, 8 => 0], $this->membershipOf(7), 'moved to 0 in Summer; its other collections untouched');
        $this->assertLockedBeforeRead(['collection:5'], 'read:findForCollection');
        $this->assertSingleTransaction();
    }

    #[Test]
    public function setProductsWithAnUnchangedListWritesNothing(): void
    {
        $admin = $this->makeAdmin();
        $this->bindStore($admin);

        $res = $this->send($admin, 'PUT', '/v3/admin/collections/5/products', ['product_ids' => [3, 7]]);

        self::assertSame(200, $res->getStatusCode());
        self::assertSame([], $this->persisted);
        self::assertSame([], $this->removed);
        self::assertSame([3, 7], $this->membersOf(5));
    }

    #[Test]
    public function setProductsSeesARowCommittedWhileItWaitedForTheLock(): void
    {
        $admin = $this->makeAdmin();
        $this->bindStore($admin);

        // Product 4 is added to Summer from its product page while this save
        // waits for Summer's lock: the re-set keeps that row (re-positioned)
        // instead of inserting a duplicate pair.
        $this->onLock = function (string $key): void {
            if ($key === 'collection:5') {
                $this->commitConcurrently(5, 4, 2);
            }
        };

        $res = $this->send($admin, 'PUT', '/v3/admin/collections/5/products', ['product_ids' => [4, 3]]);

        self::assertSame(200, $res->getStatusCode(), (string) $res->getBody());
        self::assertSame([4, 3], $this->membersOf(5));
        self::assertSame([], $this->persisted, 'no INSERT: the committed (5, 4) row was reused');
        self::assertSame([7], array_map(static fn ($r): int => (int) $r->getProduct()->getId(), $this->removed));
    }

    #[Test]
    public function setProductsRefusesToOverwriteAMembershipAddedFromTheProductPageSinceItLoaded(): void
    {
        $admin = $this->makeAdmin();
        $this->bindStore($admin);

        // 1. The builder for Summer loads [3, 7].
        $loaded = array_column($this->jsonBody($this->send($admin, 'GET', '/v3/admin/collections/5/products'))['data'], 'id');
        self::assertSame([3, 7], $loaded);

        // 2. In another tab product 4 is added to Summer from its product page.
        $d = $this->send($admin, 'PUT', '/v3/admin/products/4/collections', ['collection_ids' => [5]]);
        self::assertSame(200, $d->getStatusCode(), (string) $d->getBody());

        // 3. The builder saves a reorder of what IT loaded. Without the
        //    precondition this full re-set would silently drop product 4.
        $res = $this->send($admin, 'PUT', '/v3/admin/collections/5/products', [
            'product_ids'          => [7, 3],
            'expected_product_ids' => $loaded,
        ]);

        self::assertSame(409, $res->getStatusCode(), (string) $res->getBody());
        $error = $this->jsonBody($res)['error'];
        self::assertSame('CONFLICT_STALE', $error['code']);
        self::assertSame([3, 7, 4], $error['details']['current_product_ids']);
        self::assertSame([3, 7, 4], $this->membersOf(5), 'product 4 is still in Summer, order untouched');
    }

    #[Test]
    public function setProductsAppliesWhenTheExpectedListMatches(): void
    {
        $admin = $this->makeAdmin();
        $this->bindStore($admin);

        $res = $this->send($admin, 'PUT', '/v3/admin/collections/5/products', [
            'product_ids'          => [7, 3],
            'expected_product_ids' => [3, 7],
        ]);

        self::assertSame(200, $res->getStatusCode(), (string) $res->getBody());
        self::assertSame([7, 3], $this->membersOf(5));
    }

    #[Test]
    public function setProductsTreatsAReorderedStoredListAsStale(): void
    {
        $admin = $this->makeAdmin();
        $this->bindStore($admin);

        // Same members, different order (another tab reordered): still stale,
        // or this save would silently undo that reorder (and the cover image).
        $res = $this->send($admin, 'PUT', '/v3/admin/collections/5/products', [
            'product_ids'          => [3, 7, 4],
            'expected_product_ids' => [7, 3],
        ]);

        self::assertSame(409, $res->getStatusCode());
        self::assertSame([3, 7], $this->jsonBody($res)['error']['details']['current_product_ids']);
        self::assertSame([3, 7], $this->membersOf(5));
    }

    #[Test]
    public function setProductsRejectsAMalformedExpectedListWith422(): void
    {
        $admin = $this->makeAdmin();
        $this->bindStore($admin);

        $res = $this->send($admin, 'PUT', '/v3/admin/collections/5/products', [
            'product_ids'          => [7],
            'expected_product_ids' => [-1],
        ]);

        self::assertSame(422, $res->getStatusCode());
        self::assertArrayHasKey('expected_product_ids', $this->jsonBody($res)['error']['details']['fields']);
        self::assertSame(0, $this->transactions);
    }

    // ── collection-side curation keeps multi-collection membership ──

    #[Test]
    public function curatingAProductIntoASecondCollectionKeepsItInTheFirst(): void
    {
        $admin = $this->makeAdmin();
        $this->bindStore($admin);

        // Product 4 starts in no collection. Curate it into Summer, then Eid,
        // via the EXISTING collection-side endpoint.
        $r1 = $this->send($admin, 'PUT', '/v3/admin/collections/5/products', ['product_ids' => [4, 3, 7]]);
        $r2 = $this->send($admin, 'PUT', '/v3/admin/collections/6/products', ['product_ids' => [7, 4]]);

        self::assertSame(200, $r1->getStatusCode(), (string) $r1->getBody());
        self::assertSame(200, $r2->getStatusCode(), (string) $r2->getBody());
        self::assertSame([5 => 0, 6 => 1], $this->membershipOf(4), 'in BOTH collections');
        self::assertSame([4, 3, 7], $this->membersOf(5), 'curating Eid did not touch Summer');
        self::assertSame(['collection:5', 'collection:6'], $this->locksTaken(), 'each PUT locked only its own collection');
        self::assertSame(2, $this->transactions, 'each full re-set is atomic');
        self::assertSame(0, $this->flushesOutsideTransaction);
        self::assertSame([], $this->removed, 'no row of either collection was deleted');

        $res = $this->send($admin, 'GET', '/v3/admin/collections/memberships?product_ids=4');
        self::assertSame([6, 5], array_column($this->jsonBody($res)['data']['4'], 'id'));
    }
}
