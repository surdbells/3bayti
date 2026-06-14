<?php

declare(strict_types=1);

namespace Bayti\Api\Tests\Http\Controllers\Vendor;

use Bayti\Api\Domain\Catalog\Vendor;
use Bayti\Api\Domain\Catalog\VendorDashboardCalculator;
use Bayti\Api\Domain\Catalog\VendorRepository;
use Bayti\Api\Domain\User\User;
use Bayti\Api\Domain\User\UserRepository;
use Bayti\Api\Http\Controllers\Vendor\GetVendorDashboardController;
use Bayti\Api\Infrastructure\Auth\JwtService;
use Bayti\Api\Tests\Http\HttpTestCase;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use Psr\Http\Message\ResponseInterface;

/**
 * GET /v3/vendor/dashboard — verifies vendor resolution, that the
 * calculator is invoked with the owned store ids, and the envelope is
 * returned. The aggregate SQL itself is covered in the calculator test.
 */
#[CoversClass(GetVendorDashboardController::class)]
final class GetVendorDashboardControllerTest extends HttpTestCase
{
    /** @var array{0: array<int>, 1: int}|null */
    private ?array $captured = null;

    private function makeVendorUser(int $id): User
    {
        $u = $this->makeUser(id: $id);
        $u->setRoles(vendor: true);
        return $u;
    }

    private function bindDeps(User $user, array $ownedIds): void
    {
        $userRepo = $this->createMock(UserRepository::class);
        $userRepo->method('findById')->willReturn($user);

        $vendorRepo = $this->createMock(VendorRepository::class);
        $vendorRepo->method('findIdsByOwnerUser')->willReturn($ownedIds);
        $vendorRepo->method('existsApprovedForOwnerUser')->willReturn($ownedIds !== []);

        $em = $this->stubEm(function ($em) use ($userRepo, $vendorRepo): void {
            $em->method('getRepository')->willReturnMap([
                [User::class, $userRepo],
                [Vendor::class, $vendorRepo],
            ]);
        });
        $this->bind(EntityManagerInterface::class, $em);

        $calc = $this->createMock(VendorDashboardCalculator::class);
        $calc->method('compute')->willReturnCallback(function (array $ids, int $days): array {
            $this->captured = [$ids, $days];
            return [
                'window' => ['days' => $days],
                'catalog' => ['total_products' => 12, 'active' => 9, 'draft' => 3, 'out_of_stock' => 1, 'low_stock' => 2],
                'sales' => ['revenue' => 5400.0, 'orders' => 4, 'units' => 12, 'aov' => 1350.0],
                'operations' => ['awaiting_acceptance' => 2, 'to_ship' => 1],
                'revenue_series' => [],
                'top_products' => [],
                'recent_orders' => [],
            ];
        });
        $this->bind(VendorDashboardCalculator::class, $calc);
    }

    private function get(User $user, string $uri): ResponseInterface
    {
        $jwt = $this->app->getContainer()->get(JwtService::class);
        $pair = $jwt->issueTokenPair($user);
        return $this->handle($this->jsonRequest('GET', $uri, [], [
            'Authorization' => 'Bearer ' . $pair->accessToken,
        ]));
    }

    #[Test]
    public function returnsDashboardForAllOwnedStores(): void
    {
        $user = $this->makeVendorUser(100);
        $this->bindDeps($user, [5, 9]);

        $res = $this->get($user, '/v3/vendor/dashboard?days=30');
        self::assertSame(200, $res->getStatusCode(), (string) $res->getBody());
        $data = $this->jsonBody($res)['data'];

        self::assertSame(12, $data['catalog']['total_products']);
        self::assertSame(1350.0, $data['sales']['aov']);
        self::assertSame(2, $data['operations']['awaiting_acceptance']);
        self::assertSame([5, 9], $this->captured[0]); // all owned stores
        self::assertSame(30, $this->captured[1]);
    }

    #[Test]
    public function vendorIdScopesToASingleStore(): void
    {
        $user = $this->makeVendorUser(100);
        $this->bindDeps($user, [5, 9]);

        $res = $this->get($user, '/v3/vendor/dashboard?vendor_id=9');
        self::assertSame(200, $res->getStatusCode());
        self::assertSame([9], $this->captured[0]);
    }

    #[Test]
    public function foreignVendorIdIs404(): void
    {
        $user = $this->makeVendorUser(100);
        $this->bindDeps($user, [5, 9]);

        $res = $this->get($user, '/v3/vendor/dashboard?vendor_id=999');
        self::assertSame(404, $res->getStatusCode());
    }

    #[Test]
    public function noStoresIs403(): void
    {
        $user = $this->makeVendorUser(100);
        $this->bindDeps($user, []);

        $res = $this->get($user, '/v3/vendor/dashboard');
        self::assertSame(403, $res->getStatusCode());
    }
}
