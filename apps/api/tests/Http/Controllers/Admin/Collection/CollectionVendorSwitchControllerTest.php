<?php

declare(strict_types=1);

namespace Bayti\Api\Tests\Http\Controllers\Admin\Collection;

use Bayti\Api\Http\Controllers\Admin\Collection\CollectionCrudController;
use Bayti\Api\Tests\Http\HttpTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;

/**
 * The per-collection "Open to vendors" switch on admin collection CRUD:
 * "vendor_contributions_enabled" (bool, optional, default true on create) on
 * POST/PUT /v3/admin/collections[/{id}], serialized on list/get.
 */
#[CoversClass(CollectionCrudController::class)]
final class CollectionVendorSwitchControllerTest extends HttpTestCase
{
    use InMemoryCollectionStore;

    protected function setUp(): void
    {
        parent::setUp();
        $this->resetStore();

        $this->addCollection(1, 'Open', 0);
        $this->addCollection(2, 'Closed', 1, openToVendors: false);
    }

    #[Test]
    public function listAndGetSerializeTheFlag(): void
    {
        $admin = $this->makeAdmin();
        $this->bindStore($admin);

        $list = $this->send($admin, 'GET', '/v3/admin/collections?limit=100');
        self::assertSame(200, $list->getStatusCode(), (string) $list->getBody());
        $data = $this->jsonBody($list)['data'];
        self::assertSame([1, 2], array_column($data, 'id'));
        self::assertSame([true, false], array_column($data, 'vendor_contributions_enabled'));

        $get = $this->send($admin, 'GET', '/v3/admin/collections/2');
        self::assertSame(200, $get->getStatusCode(), (string) $get->getBody());
        self::assertFalse($this->jsonBody($get)['data']['vendor_contributions_enabled']);
    }

    #[Test]
    public function createDefaultsToOpen(): void
    {
        $admin = $this->makeAdmin();
        $this->bindStore($admin);

        $res = $this->send($admin, 'POST', '/v3/admin/collections', ['name' => 'Ramadan']);

        self::assertSame(201, $res->getStatusCode(), (string) $res->getBody());
        self::assertTrue($this->jsonBody($res)['data']['vendor_contributions_enabled']);
    }

    #[Test]
    public function createCanStartClosed(): void
    {
        $admin = $this->makeAdmin();
        $this->bindStore($admin);

        $res = $this->send($admin, 'POST', '/v3/admin/collections', [
            'name' => 'Editors picks',
            'vendor_contributions_enabled' => false,
        ]);

        self::assertSame(201, $res->getStatusCode(), (string) $res->getBody());
        self::assertFalse($this->jsonBody($res)['data']['vendor_contributions_enabled']);
    }

    #[Test]
    public function updateTogglesTheFlagAndGetReflectsIt(): void
    {
        $admin = $this->makeAdmin();
        $this->bindStore($admin);

        $close = $this->send($admin, 'PUT', '/v3/admin/collections/1', ['vendor_contributions_enabled' => false]);
        self::assertSame(200, $close->getStatusCode(), (string) $close->getBody());
        self::assertFalse($this->jsonBody($close)['data']['vendor_contributions_enabled']);
        self::assertFalse($this->collections[1]->isVendorContributionsEnabled());
        self::assertTrue($this->collections[1]->isActive(), 'closing does not deactivate');
        self::assertFalse($this->jsonBody($this->send($admin, 'GET', '/v3/admin/collections/1'))['data']['vendor_contributions_enabled']);

        $open = $this->send($admin, 'PUT', '/v3/admin/collections/2', ['vendor_contributions_enabled' => true]);
        self::assertSame(200, $open->getStatusCode(), (string) $open->getBody());
        self::assertTrue($this->collections[2]->isVendorContributionsEnabled());
    }

    #[Test]
    public function updateWithoutTheFieldLeavesItAlone(): void
    {
        $admin = $this->makeAdmin();
        $this->bindStore($admin);

        $res = $this->send($admin, 'PUT', '/v3/admin/collections/2', ['name' => 'Still closed']);

        self::assertSame(200, $res->getStatusCode(), (string) $res->getBody());
        self::assertFalse($this->collections[2]->isVendorContributionsEnabled());
    }

    /** @return iterable<string, array{mixed}> */
    public static function invalidFlags(): iterable
    {
        yield 'word'   => ['yes'];
        yield 'number' => [2];
        yield 'null'   => [null];
        yield 'array'  => [[true]];
    }

    #[Test]
    #[DataProvider('invalidFlags')]
    public function aNonBooleanFlagIs422AndNothingChanges(mixed $flag): void
    {
        $admin = $this->makeAdmin();
        $this->bindStore($admin);

        $res = $this->send($admin, 'PUT', '/v3/admin/collections/1', ['vendor_contributions_enabled' => $flag]);

        self::assertSame(422, $res->getStatusCode(), (string) $res->getBody());
        self::assertArrayHasKey('vendor_contributions_enabled', $this->jsonBody($res)['error']['details']['fields']);
        self::assertTrue($this->collections[1]->isVendorContributionsEnabled());
    }
}
