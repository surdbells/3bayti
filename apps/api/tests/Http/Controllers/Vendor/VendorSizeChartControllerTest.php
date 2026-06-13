<?php

declare(strict_types=1);

namespace Bayti\Api\Tests\Http\Controllers\Vendor;

use Bayti\Api\Domain\Catalog\Vendor;
use Bayti\Api\Domain\Catalog\VendorRepository;
use Bayti\Api\Domain\Catalog\VendorSizeChart;
use Bayti\Api\Domain\Catalog\VendorSizeChartRepository;
use Bayti\Api\Domain\User\User;
use Bayti\Api\Domain\User\UserRepository;
use Bayti\Api\Http\Controllers\Vendor\VendorSizeChartController;
use Bayti\Api\Infrastructure\Auth\JwtService;
use Bayti\Api\Tests\Http\HttpTestCase;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use Psr\Http\Message\ResponseInterface;

/**
 * HTTP tests for the vendor size-chart CRUD
 * (GET/POST/PUT/DELETE /v3/vendor/measurements).
 *
 * Covers: list, create (+ shape), duplicate-size conflict, missing-size
 * 400, not-found on update, and the no-vendor 403. The size chart is
 * scoped to the authenticated vendor via owner resolution.
 */
#[CoversClass(VendorSizeChartController::class)]
final class VendorSizeChartControllerTest extends HttpTestCase
{
    /** @var array<int, VendorSizeChart> in-memory store keyed by id */
    private array $store = [];
    private int $nextId = 1;

    protected function setUp(): void
    {
        parent::setUp();
        $this->store = [];
        $this->nextId = 1;
    }

    private function makeVendorUser(int $id): User
    {
        $u = $this->makeUser(id: $id);
        $u->setRoles(vendor: true);
        return $u;
    }

    private function makeVendor(int $id): Vendor
    {
        $v = new Vendor("vendor-{$id}", "Vendor {$id}", "vendor{$id}@example.com");
        // VendorAuthMiddleware gates on approved status.
        $v->approve();
        $this->setProp($v, 'id', $id);
        return $v;
    }

    private function setProp(object $o, string $prop, mixed $val): void
    {
        $rp = new \ReflectionProperty($o, $prop);
        $rp->setAccessible(true);
        $rp->setValue($o, $val);
    }

    /**
     * Binds an EM whose VendorSizeChartRepository is backed by $this->store,
     * and a VendorRepository resolving the given owned vendor.
     */
    private function bindDeps(User $user, Vendor $vendor): void
    {
        $userRepo = $this->createMock(UserRepository::class);
        $userRepo->method('findById')->willReturn($user);

        $vendorRepo = $this->createMock(VendorRepository::class);
        $vendorRepo->method('findIdsByOwnerUser')->willReturn([(int) $vendor->getId()]);
        $vendorRepo->method('existsApprovedForOwnerUser')->willReturn(true);
        $vendorRepo->method('find')->willReturnCallback(
            fn (int $id) => $id === (int) $vendor->getId() ? $vendor : null,
        );

        $chartRepo = $this->createMock(VendorSizeChartRepository::class);
        $chartRepo->method('findForVendor')->willReturnCallback(
            fn (int $vid) => array_values(array_filter(
                $this->store,
                fn (VendorSizeChart $c) => (int) $c->getVendor()->getId() === $vid,
            )),
        );
        $chartRepo->method('findOneForVendor')->willReturnCallback(
            fn (int $id, int $vid) => (isset($this->store[$id])
                && (int) $this->store[$id]->getVendor()->getId() === $vid)
                ? $this->store[$id] : null,
        );
        $chartRepo->method('sizeExistsForVendor')->willReturnCallback(
            function (int $vid, string $size, ?int $exclude = null): bool {
                foreach ($this->store as $cid => $c) {
                    if ((int) $c->getVendor()->getId() === $vid
                        && strtolower($c->getSize()) === strtolower($size)
                        && $cid !== $exclude) {
                        return true;
                    }
                }
                return false;
            },
        );

        $em = $this->stubEm(function ($em) use ($userRepo, $vendorRepo, $chartRepo): void {
            $em->method('getRepository')->willReturnMap([
                [User::class, $userRepo],
                [Vendor::class, $vendorRepo],
                [VendorSizeChart::class, $chartRepo],
            ]);
            // persist() assigns an id and stores; remove() drops it.
            $em->method('persist')->willReturnCallback(function (object $e): void {
                if ($e instanceof VendorSizeChart && $e->getId() === null) {
                    $this->setProp($e, 'id', $this->nextId);
                    $this->store[$this->nextId] = $e;
                    $this->nextId++;
                }
            });
            $em->method('remove')->willReturnCallback(function (object $e): void {
                if ($e instanceof VendorSizeChart && $e->getId() !== null) {
                    unset($this->store[(int) $e->getId()]);
                }
            });
        });
        $this->bind(EntityManagerInterface::class, $em);
    }

    private function request(User $user, string $method, string $uri, array $body = []): ResponseInterface
    {
        $jwt = $this->app->getContainer()->get(JwtService::class);
        $pair = $jwt->issueTokenPair($user);
        return $this->handle($this->jsonRequest($method, $uri, $body, [
            'Authorization' => 'Bearer ' . $pair->accessToken,
        ]));
    }

    #[Test]
    public function createThenListReturnsTheChartRow(): void
    {
        $user = $this->makeVendorUser(100);
        $vendor = $this->makeVendor(101);
        $this->bindDeps($user, $vendor);

        $create = $this->request($user, 'POST', '/v3/vendor/measurements', [
            'values' => ['size' => 'M', 'bust' => 92, 'waist' => 76, 'hip' => 98],
        ]);
        self::assertSame(201, $create->getStatusCode(), (string) $create->getBody());
        $created = $this->jsonBody($create)['data'];
        self::assertSame('M', $created['size']);
        self::assertSame(92.0, $created['values']['bust']);

        $list = $this->request($user, 'GET', '/v3/vendor/measurements');
        self::assertSame(200, $list->getStatusCode());
        $rows = $this->jsonBody($list)['data'];
        self::assertCount(1, $rows);
        self::assertSame('M', $rows[0]['size']);
    }

    #[Test]
    public function duplicateSizeReturns409(): void
    {
        $user = $this->makeVendorUser(100);
        $vendor = $this->makeVendor(101);
        $this->bindDeps($user, $vendor);

        $this->request($user, 'POST', '/v3/vendor/measurements', ['values' => ['size' => 'L', 'bust' => 100]]);
        $dup = $this->request($user, 'POST', '/v3/vendor/measurements', ['values' => ['size' => 'L', 'bust' => 101]]);

        self::assertSame(409, $dup->getStatusCode());
        self::assertSame('CONFLICT_DUPLICATE', $this->jsonBody($dup)['error']['code']);
    }

    #[Test]
    public function missingSizeReturns400(): void
    {
        $user = $this->makeVendorUser(100);
        $vendor = $this->makeVendor(101);
        $this->bindDeps($user, $vendor);

        $res = $this->request($user, 'POST', '/v3/vendor/measurements', ['values' => ['bust' => 90]]);
        self::assertSame(400, $res->getStatusCode());
    }

    #[Test]
    public function updateMissingRowReturns404(): void
    {
        $user = $this->makeVendorUser(100);
        $vendor = $this->makeVendor(101);
        $this->bindDeps($user, $vendor);

        $res = $this->request($user, 'PUT', '/v3/vendor/measurements/999', ['values' => ['size' => 'S', 'bust' => 80]]);
        self::assertSame(404, $res->getStatusCode());
    }

    #[Test]
    public function updateChangesValues(): void
    {
        $user = $this->makeVendorUser(100);
        $vendor = $this->makeVendor(101);
        $this->bindDeps($user, $vendor);

        $created = $this->jsonBody(
            $this->request($user, 'POST', '/v3/vendor/measurements', ['values' => ['size' => 'M', 'bust' => 90]]),
        )['data'];

        $upd = $this->request($user, 'PUT', "/v3/vendor/measurements/{$created['id']}", [
            'values' => ['size' => 'M', 'bust' => 95, 'waist' => 78],
        ]);
        self::assertSame(200, $upd->getStatusCode());
        self::assertSame(95.0, $this->jsonBody($upd)['data']['values']['bust']);
    }

    #[Test]
    public function deleteRemovesRow(): void
    {
        $user = $this->makeVendorUser(100);
        $vendor = $this->makeVendor(101);
        $this->bindDeps($user, $vendor);

        $created = $this->jsonBody(
            $this->request($user, 'POST', '/v3/vendor/measurements', ['values' => ['size' => 'XL', 'bust' => 110]]),
        )['data'];

        $del = $this->request($user, 'DELETE', "/v3/vendor/measurements/{$created['id']}");
        self::assertSame(200, $del->getStatusCode());
        self::assertTrue($this->jsonBody($del)['data']['deleted']);

        $rows = $this->jsonBody($this->request($user, 'GET', '/v3/vendor/measurements'))['data'];
        self::assertCount(0, $rows);
    }
}
