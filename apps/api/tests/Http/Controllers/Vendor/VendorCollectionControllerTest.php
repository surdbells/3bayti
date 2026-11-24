<?php

declare(strict_types=1);

namespace Bayti\Api\Tests\Http\Controllers\Vendor;

use Bayti\Api\Domain\Catalog\CollectionCurationService;
use Bayti\Api\Domain\Catalog\Product;
use Bayti\Api\Domain\Catalog\Vendor;
use Bayti\Api\Domain\User\User;
use Bayti\Api\Http\Controllers\Vendor\Collection\VendorCollectionController;
use Bayti\Api\Tests\Http\Controllers\Admin\Collection\InMemoryCollectionStore;
use Bayti\Api\Tests\Http\HttpTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;

/**
 * Vendors submit their OWN products to admin-curated collections:
 *   GET /v3/vendor/collections
 *   GET /v3/vendor/collections/{id}/products
 *   PUT /v3/vendor/collections/{id}/products
 *   GET /v3/vendor/products/{id}/collections
 *   PUT /v3/vendor/products/{id}/collections
 *
 * Fixture: the signed-in vendor owns store 1 (Almas); store 2 (Noor) is
 * another vendor.
 *   Collections: Eid (6, display 0, active), Summer (5, display 1, active),
 *                Empty (9, display 2, active), Gifts (8, INACTIVE).
 *   Almas products: 10, 11, 12, 13 (soft-deleted). Noor products: 20, 21.
 *   Summer = [20@0 (Noor, card image), 10@1 (Almas), 21@2 (Noor)]
 *   Eid    = [21@0 (Noor)]
 *   Gifts  = [10@0 (Almas, inactive collection)]
 */
#[CoversClass(VendorCollectionController::class)]
#[CoversClass(CollectionCurationService::class)]
final class VendorCollectionControllerTest extends HttpTestCase
{
    use InMemoryCollectionStore;

    private Vendor $noor;

    protected function setUp(): void
    {
        parent::setUp();
        $this->resetStore();

        $this->addCollection(5, 'Summer', 1);
        $this->addCollection(6, 'Eid', 0);
        $this->addCollection(8, 'Gifts', null, active: false);
        $this->addCollection(9, 'Empty', 2);

        $this->noor = $this->makeStore(2, 'noor');

        $this->addProduct(10, 'Ten');
        $this->addProduct(11, 'Eleven');
        $this->addProduct(12, 'Twelve');
        $this->addProduct(13, 'Thirteen')->softDelete();
        $this->addProduct(20, 'Noor Twenty', $this->noor);
        $this->addProduct(21, 'Noor Twenty-one', $this->noor);

        $this->addMember(5, 20, 0);
        $this->addMember(5, 10, 1);
        $this->addMember(5, 21, 2);
        $this->addMember(6, 21, 0);
        $this->addMember(8, 10, 0);
    }

    private function vendorUser(): User
    {
        $u = $this->makeUser(id: 50);
        $u->setRoles(vendor: true);
        return $u;
    }

    /** @return array<int, int> product id => sort_order in a collection */
    private function positions(int $collectionId): array
    {
        $out = [];
        foreach ($this->rowsOfCollection($collectionId) as $r) {
            $out[(int) $r->getProduct()->getId()] = $r->getSortOrder();
        }
        return $out;
    }

    // ── GET /vendor/collections ─────────────────────────────────────

    #[Test]
    public function listShowsOnlyActiveCollectionsWithTheVendorsOwnCount(): void
    {
        $vendor = $this->vendorUser();
        $this->bindStore($vendor);

        $res = $this->send($vendor, 'GET', '/v3/vendor/collections?limit=100');

        self::assertSame(200, $res->getStatusCode(), (string) $res->getBody());
        $body = $this->jsonBody($res);
        self::assertSame([6, 5, 9], array_column($body['data'], 'id'), 'active only, storefront order');
        self::assertSame(3, $body['meta']['total']);
        self::assertSame(
            ['id' => 5, 'name' => 'Summer', 'slug' => 'summer', 'description' => null, 'cover_image_url' => null, 'my_product_count' => 1],
            $body['data'][1],
            'only the vendor\'s own members are counted; no admin-only fields',
        );
        self::assertSame(0, $body['data'][0]['my_product_count'], 'Eid holds only Noor products');
        self::assertSame(1, $this->repoCalls['join.countsByCollectionForVendors'] ?? 0, 'one grouped count query');
    }

    // ── GET /vendor/collections/{id}/products ───────────────────────

    #[Test]
    public function listProductsReturnsOnlyTheVendorsOwnMembers(): void
    {
        $vendor = $this->vendorUser();
        $this->bindStore($vendor);

        $res = $this->send($vendor, 'GET', '/v3/vendor/collections/5/products');

        self::assertSame(200, $res->getStatusCode(), (string) $res->getBody());
        $body = $this->jsonBody($res);
        self::assertSame([10], array_column($body['data'], 'id'), 'Noor products 20 / 21 are invisible');
        self::assertSame(1, $body['meta']['total']);
        self::assertSame(1, $body['data'][0]['vendor_id']);
    }

    #[Test]
    public function inactiveOrUnknownCollectionsAre404(): void
    {
        $vendor = $this->vendorUser();
        $this->bindStore($vendor);

        self::assertSame(404, $this->send($vendor, 'GET', '/v3/vendor/collections/8/products')->getStatusCode());
        self::assertSame(404, $this->send($vendor, 'GET', '/v3/vendor/collections/999/products')->getStatusCode());
        self::assertSame(404, $this->send($vendor, 'PUT', '/v3/vendor/collections/8/products', ['product_ids' => [11]])->getStatusCode());
        self::assertSame([10 => 0], $this->positions(8), 'nothing written to the inactive collection');
        self::assertSame(0, $this->transactions);
    }

    // ── PUT /vendor/collections/{id}/products ───────────────────────

    #[Test]
    public function addingAppendsAtTheEndAndNeverTouchesOtherStoresRows(): void
    {
        $vendor = $this->vendorUser();
        $this->bindStore($vendor);

        $res = $this->send($vendor, 'PUT', '/v3/vendor/collections/5/products', ['product_ids' => [12, 10, 11]]);

        self::assertSame(200, $res->getStatusCode(), (string) $res->getBody());
        // 10 keeps its slot; 12 then 11 are appended after the LAST row (21@2),
        // in request order; Noor's card image (20@0) and 21@2 are untouched.
        self::assertSame([20 => 0, 10 => 1, 21 => 2, 12 => 3, 11 => 4], $this->positions(5));
        self::assertSame([10, 12, 11], array_column($this->jsonBody($res)['data'], 'id'), 'own members, curation order');
        self::assertCount(2, $this->persisted);
        self::assertSame([], $this->removed);
        $this->assertSingleTransaction();
        $this->assertLockedBeforeRead(['collection:5'], 'read:findForCollection');
    }

    #[Test]
    public function addingToAnEmptyCollectionStartsAtZero(): void
    {
        $vendor = $this->vendorUser();
        $this->bindStore($vendor);

        $res = $this->send($vendor, 'PUT', '/v3/vendor/collections/9/products', ['product_ids' => [11, 12]]);

        self::assertSame(200, $res->getStatusCode(), (string) $res->getBody());
        self::assertSame([11 => 0, 12 => 1], $this->positions(9));
    }

    #[Test]
    public function removingDropsOnlyTheVendorsRowsAndLeavesOthersInPlace(): void
    {
        $vendor = $this->vendorUser();
        $this->bindStore($vendor);

        $res = $this->send($vendor, 'PUT', '/v3/vendor/collections/5/products', ['product_ids' => []]);

        self::assertSame(200, $res->getStatusCode(), (string) $res->getBody());
        self::assertSame([], $this->jsonBody($res)['data']);
        // Gap at 1 left as is: no renumbering of other stores' rows.
        self::assertSame([20 => 0, 21 => 2], $this->positions(5));
        self::assertCount(1, $this->removed);
        self::assertSame([], $this->persisted);
        self::assertSame([10 => 0], $this->positions(8), 'other collections untouched');
    }

    #[Test]
    public function removingTheCardImageProductKeepsTheRestInOrder(): void
    {
        // Almas product 11 fronts Empty; Noor's 20 follows.
        $this->addMember(9, 11, 0);
        $this->addMember(9, 20, 1);
        $vendor = $this->vendorUser();
        $this->bindStore($vendor);

        $res = $this->send($vendor, 'PUT', '/v3/vendor/collections/9/products', ['product_ids' => []]);

        self::assertSame(200, $res->getStatusCode(), (string) $res->getBody());
        self::assertSame([20 => 1], $this->positions(9));
    }

    #[Test]
    public function aRepeatedPutWritesNothing(): void
    {
        $vendor = $this->vendorUser();
        $this->bindStore($vendor);

        $first = $this->send($vendor, 'PUT', '/v3/vendor/collections/5/products', ['product_ids' => [10, 11]]);
        self::assertSame(200, $first->getStatusCode(), (string) $first->getBody());
        $after = $this->positions(5);
        $this->persisted = [];
        $this->removed = [];

        $second = $this->send($vendor, 'PUT', '/v3/vendor/collections/5/products', ['product_ids' => [11, 10, 10]]);

        self::assertSame(200, $second->getStatusCode(), (string) $second->getBody());
        self::assertSame($after, $this->positions(5), 'positions unchanged (set semantics, duplicates collapse)');
        self::assertSame([], $this->persisted);
        self::assertSame([], $this->removed);
    }

    #[Test]
    public function foreignSoftDeletedOrUnknownProductsAre422AndNothingIsWritten(): void
    {
        $vendor = $this->vendorUser();
        $this->bindStore($vendor);

        foreach ([[11, 20], [13], [999]] as $ids) {
            $res = $this->send($vendor, 'PUT', '/v3/vendor/collections/5/products', ['product_ids' => $ids]);
            self::assertSame(422, $res->getStatusCode(), (string) $res->getBody());
            $msg = $this->jsonBody($res)['error']['details']['fields']['product_ids'][0];
            self::assertStringStartsWith('Unknown product id(s): ', $msg);
        }
        self::assertSame([20 => 0, 10 => 1, 21 => 2], $this->positions(5));
        self::assertSame(0, $this->transactions);
    }

    #[Test]
    public function malformedProductIdsAre422(): void
    {
        $vendor = $this->vendorUser();
        $this->bindStore($vendor);

        foreach ([['product_ids' => 'x'], [], ['product_ids' => [0]], ['product_ids' => [10], 'expected_product_ids' => 'x']] as $body) {
            $res = $this->send($vendor, 'PUT', '/v3/vendor/collections/5/products', $body);
            self::assertSame(422, $res->getStatusCode(), (string) $res->getBody());
        }
    }

    #[Test]
    public function aStaleExpectedSetIs409WithTheCurrentOwnIds(): void
    {
        $vendor = $this->vendorUser();
        $this->bindStore($vendor);

        $res = $this->send($vendor, 'PUT', '/v3/vendor/collections/5/products', [
            'product_ids' => [11],
            'expected_product_ids' => [],
        ]);

        self::assertSame(409, $res->getStatusCode(), (string) $res->getBody());
        $error = $this->jsonBody($res)['error'];
        self::assertSame('CONFLICT_STALE', $error['code']);
        self::assertSame([10], $error['details']['current_product_ids'], 'only the vendor\'s own ids are revealed');
        self::assertSame([20 => 0, 10 => 1, 21 => 2], $this->positions(5));

        // The matching expectation (own ids only, any order) goes through.
        $ok = $this->send($vendor, 'PUT', '/v3/vendor/collections/5/products', [
            'product_ids' => [11],
            'expected_product_ids' => [10],
        ]);
        self::assertSame(200, $ok->getStatusCode(), (string) $ok->getBody());
        self::assertSame([20 => 0, 21 => 2, 11 => 3], $this->positions(5));
    }

    #[Test]
    public function aSoftDeletedOwnMemberIsNeitherComparedNorRemoved(): void
    {
        $this->addMember(9, 13, 0); // soft-deleted Almas product still in Empty
        $vendor = $this->vendorUser();
        $this->bindStore($vendor);

        $res = $this->send($vendor, 'PUT', '/v3/vendor/collections/9/products', [
            'product_ids' => [11],
            'expected_product_ids' => [],
        ]);

        self::assertSame(200, $res->getStatusCode(), (string) $res->getBody());
        self::assertSame([13 => 0, 11 => 1], $this->positions(9));
    }

    #[Test]
    public function aMultiStoreOwnerManagesEveryOwnedStoresProducts(): void
    {
        $this->ownedVendorIds = [1, 2];
        $vendor = $this->vendorUser();
        $this->bindStore($vendor);

        $res = $this->send($vendor, 'GET', '/v3/vendor/collections/5/products');
        self::assertSame([20, 10, 21], array_column($this->jsonBody($res)['data'], 'id'));
    }

    // ── GET/PUT /vendor/products/{id}/collections ───────────────────

    #[Test]
    public function productCollectionsListOnlyActiveOnes(): void
    {
        $vendor = $this->vendorUser();
        $this->bindStore($vendor);

        $res = $this->send($vendor, 'GET', '/v3/vendor/products/10/collections');

        self::assertSame(200, $res->getStatusCode(), (string) $res->getBody());
        self::assertSame(
            [['id' => 5, 'name' => 'Summer', 'slug' => 'summer', 'sort_order' => 1]],
            $this->jsonBody($res)['data'],
            'inactive Gifts is hidden',
        );
    }

    #[Test]
    public function foreignSoftDeletedOrUnknownProductsAre404OnTheProductSide(): void
    {
        $vendor = $this->vendorUser();
        $this->bindStore($vendor);

        foreach ([20, 13, 999] as $pid) {
            self::assertSame(404, $this->send($vendor, 'GET', "/v3/vendor/products/{$pid}/collections")->getStatusCode());
            self::assertSame(404, $this->send($vendor, 'PUT', "/v3/vendor/products/{$pid}/collections", ['collection_ids' => [9]])->getStatusCode());
        }
        self::assertSame([], $this->positions(9));
        self::assertSame(0, $this->transactions);
    }

    #[Test]
    public function settingProductCollectionsAppendsAtTheEndAndKeepsInactiveMemberships(): void
    {
        $vendor = $this->vendorUser();
        $this->bindStore($vendor);

        $res = $this->send($vendor, 'PUT', '/v3/vendor/products/10/collections', ['collection_ids' => [6]]);

        self::assertSame(200, $res->getStatusCode(), (string) $res->getBody());
        self::assertSame([6 => 1, 8 => 0], $this->membershipOf(10), 'out of Summer, appended to Eid, inactive Gifts kept');
        self::assertSame([20 => 0, 21 => 2], $this->positions(5), 'Noor rows untouched');
        self::assertSame([21 => 0, 10 => 1], $this->positions(6));
        self::assertSame([['id' => 6, 'name' => 'Eid', 'slug' => 'eid', 'sort_order' => 1]], $this->jsonBody($res)['data']);
        $this->assertSingleTransaction();
        $this->assertLockedBeforeRead(['product:10', 'collection:5', 'collection:6'], 'read:findForProduct');
    }

    #[Test]
    public function inactiveOrUnknownCollectionIdsAre422(): void
    {
        $vendor = $this->vendorUser();
        $this->bindStore($vendor);

        foreach ([[8], [6, 999]] as $ids) {
            $res = $this->send($vendor, 'PUT', '/v3/vendor/products/11/collections', ['collection_ids' => $ids]);
            self::assertSame(422, $res->getStatusCode(), (string) $res->getBody());
            self::assertStringStartsWith(
                'Unknown collection id(s): ',
                $this->jsonBody($res)['error']['details']['fields']['collection_ids'][0],
            );
        }
        self::assertSame([], $this->membershipOf(11));
    }

    #[Test]
    public function aStaleExpectedCollectionSetIs409WithTheActiveIdsOnly(): void
    {
        $vendor = $this->vendorUser();
        $this->bindStore($vendor);

        $res = $this->send($vendor, 'PUT', '/v3/vendor/products/10/collections', [
            'collection_ids' => [6],
            'expected_collection_ids' => [],
        ]);

        self::assertSame(409, $res->getStatusCode(), (string) $res->getBody());
        self::assertSame([5], $this->jsonBody($res)['error']['details']['current_collection_ids']);
        self::assertSame([5 => 1, 8 => 0], $this->membershipOf(10));
    }

    #[Test]
    public function aRepeatedProductPutWritesNothing(): void
    {
        $vendor = $this->vendorUser();
        $this->bindStore($vendor);

        $res = $this->send($vendor, 'PUT', '/v3/vendor/products/10/collections', [
            'collection_ids' => [5],
            'expected_collection_ids' => [5],
        ]);

        self::assertSame(200, $res->getStatusCode(), (string) $res->getBody());
        self::assertSame([], $this->persisted);
        self::assertSame([], $this->removed);
        self::assertSame([5 => 1, 8 => 0], $this->membershipOf(10));
    }

    // ── access ──────────────────────────────────────────────────────

    #[Test]
    public function aVendorWithoutAnApprovedStoreIsForbidden(): void
    {
        $this->ownedVendorIds = [];
        $vendor = $this->vendorUser();
        $this->bindStore($vendor);

        self::assertSame(403, $this->send($vendor, 'GET', '/v3/vendor/collections')->getStatusCode());
        self::assertSame(403, $this->send($vendor, 'PUT', '/v3/vendor/collections/5/products', ['product_ids' => []])->getStatusCode());
        self::assertSame([20 => 0, 10 => 1, 21 => 2], $this->positions(5));
    }

    #[Test]
    public function aCustomerIsForbidden(): void
    {
        $customer = $this->makeUser(id: 51);
        $this->bindStore($customer);

        self::assertSame(403, $this->send($customer, 'GET', '/v3/vendor/products/10/collections')->getStatusCode());
        self::assertSame(403, $this->send($customer, 'PUT', '/v3/vendor/products/10/collections', ['collection_ids' => [6]])->getStatusCode());
        self::assertSame([5 => 1, 8 => 0], $this->membershipOf(10));
    }

    #[Test]
    public function adminStillSeesVendorAddedProductsAsOrdinaryMembers(): void
    {
        // One account that is both a store owner and an admin, so the same
        // bound user can hit both groups.
        $user = $this->makeAdmin();
        $user->setRoles(vendor: true);
        $this->bindStore($user);
        $put = $this->send($user, 'PUT', '/v3/vendor/collections/5/products', ['product_ids' => [10, 11]]);
        self::assertSame(200, $put->getStatusCode(), (string) $put->getBody());

        $res = $this->send($user, 'GET', '/v3/admin/collections/5/products');

        self::assertSame(200, $res->getStatusCode(), (string) $res->getBody());
        self::assertSame([20, 10, 21, 11], array_column($this->jsonBody($res)['data'], 'id'));
    }
}
