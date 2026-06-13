<?php

declare(strict_types=1);

namespace Bayti\Api\Tests\Http\Controllers\Vendor;

use Bayti\Api\Domain\Catalog\Vendor;
use Bayti\Api\Domain\Catalog\VendorRepository;
use Bayti\Api\Domain\Promo\PromoCode;
use Bayti\Api\Domain\Promo\PromoCodeRepository;
use Bayti\Api\Domain\User\User;
use Bayti\Api\Domain\User\UserRepository;
use Bayti\Api\Http\Controllers\Vendor\Coupon\VendorCouponAnalyticsController;
use Bayti\Api\Http\Serializers\PromoCodeSerializer;
use Bayti\Api\Infrastructure\Auth\JwtService;
use Bayti\Api\Tests\Http\HttpTestCase;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use Psr\Http\Message\ResponseInterface;

/**
 * HTTP tests for the vendor coupon detail + analytics endpoints
 * (GET /v3/vendor/coupons/{id} and /{id}/analytics).
 *
 * The analytics queries run through the DBAL Connection, so the
 * Connection is mocked to return canned aggregate rows; the test asserts
 * the controller maps them into the shapes the portal screen expects and
 * scopes strictly to the authenticated vendor's own coupon.
 */
#[CoversClass(VendorCouponAnalyticsController::class)]
final class VendorCouponAnalyticsControllerTest extends HttpTestCase
{
    private function makeVendorUser(int $id): User
    {
        $u = $this->makeUser(id: $id);
        $u->setRoles(vendor: true);
        return $u;
    }

    private function makeVendor(int $id): Vendor
    {
        $v = new Vendor("vendor-{$id}", "Vendor {$id}", "vendor{$id}@example.com");
        $v->approve();
        $rp = new \ReflectionProperty($v, 'id');
        $rp->setAccessible(true);
        $rp->setValue($v, $id);
        return $v;
    }

    private function makeCoupon(int $id, int $vendorId): PromoCode
    {
        $c = new PromoCode('SAVE10', PromoCode::DISCOUNT_TYPE_PERCENTAGE, '10.00');
        $c->setVendorId($vendorId);
        $rp = new \ReflectionProperty($c, 'id');
        $rp->setAccessible(true);
        $rp->setValue($c, $id);
        return $c;
    }

    /**
     * @param array<string, mixed>|null $coupon  null → coupon not found
     * @param array<string, mixed>      $canned   fetchAssociative/fetchOne returns
     */
    private function bindDeps(User $user, Vendor $vendor, ?PromoCode $coupon, array $canned = []): void
    {
        $userRepo = $this->createMock(UserRepository::class);
        $userRepo->method('findById')->willReturn($user);

        $vendorRepo = $this->createMock(VendorRepository::class);
        $vendorRepo->method('findByOwnerUser')->willReturn([$vendor]);
        $vendorRepo->method('findIdsByOwnerUser')->willReturn([(int) $vendor->getId()]);
        $vendorRepo->method('existsApprovedForOwnerUser')->willReturn(true);
        $vendorRepo->method('find')->willReturn($vendor);

        $promoRepo = $this->createMock(PromoCodeRepository::class);
        $promoRepo->method('findByIdAndVendor')->willReturn($coupon);

        $conn = $this->createMock(Connection::class);
        $conn->method('fetchAssociative')->willReturn($canned['assoc'] ?? [
            'total_uses' => 7, 'total_discount_given' => '120.50', 'unique_customers' => 5,
        ]);
        $conn->method('fetchOne')->willReturn($canned['one'] ?? '3400.00');
        $conn->method('fetchAllAssociative')->willReturn($canned['all'] ?? [
            ['day' => '2026-06-01', 'uses' => '3', 'discount' => '45.00'],
            ['day' => '2026-06-02', 'uses' => '4', 'discount' => '75.50'],
        ]);

        $em = $this->stubEm(function ($em) use ($userRepo, $vendorRepo, $promoRepo, $conn): void {
            $em->method('getRepository')->willReturnMap([
                [User::class, $userRepo],
                [Vendor::class, $vendorRepo],
                [PromoCode::class, $promoRepo],
            ]);
            $em->method('getConnection')->willReturn($conn);
        });
        $this->bind(EntityManagerInterface::class, $em);
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
    public function detailReturnsTheCoupon(): void
    {
        $user = $this->makeVendorUser(100);
        $vendor = $this->makeVendor(101);
        $this->bindDeps($user, $vendor, $this->makeCoupon(5, 101));

        $res = $this->get($user, '/v3/vendor/coupons/5');
        self::assertSame(200, $res->getStatusCode(), (string) $res->getBody());
        self::assertSame('SAVE10', $this->jsonBody($res)['data']['code']);
    }

    #[Test]
    public function couponStatsIsTheDefaultPeriod(): void
    {
        $user = $this->makeVendorUser(100);
        $vendor = $this->makeVendor(101);
        $this->bindDeps($user, $vendor, $this->makeCoupon(5, 101));

        $res = $this->get($user, '/v3/vendor/coupons/5/analytics');
        self::assertSame(200, $res->getStatusCode());
        $data = $this->jsonBody($res)['data'];
        self::assertSame(7, $data['total_uses']);
        self::assertSame(120.5, $data['total_discount_given']);
        self::assertSame(5, $data['unique_customers']);
        self::assertSame(3400.0, $data['total_revenue_generated']);
    }

    #[Test]
    public function overviewPeriodReturnsStoreKpis(): void
    {
        $user = $this->makeVendorUser(100);
        $vendor = $this->makeVendor(101);
        // fetchOne is used for both active_coupons and revenue; return a count then revenue.
        $this->bindDeps($user, $vendor, $this->makeCoupon(5, 101), ['one' => '3400.00']);

        $res = $this->get($user, '/v3/vendor/coupons/5/analytics?period=overview');
        self::assertSame(200, $res->getStatusCode());
        $data = $this->jsonBody($res)['data'];
        self::assertArrayHasKey('active_coupons', $data);
        self::assertArrayHasKey('total_redemptions', $data);
        self::assertArrayHasKey('total_discount_given', $data);
        self::assertArrayHasKey('total_revenue_with_coupons', $data);
    }

    #[Test]
    public function usageOverTimeReturnsDailySeries(): void
    {
        $user = $this->makeVendorUser(100);
        $vendor = $this->makeVendor(101);
        $this->bindDeps($user, $vendor, $this->makeCoupon(5, 101));

        $res = $this->get($user, '/v3/vendor/coupons/5/analytics?period=usage_over_time&days_back=30');
        self::assertSame(200, $res->getStatusCode());
        $series = $this->jsonBody($res)['data'];
        self::assertCount(2, $series);
        self::assertSame('2026-06-01', $series[0]['day']);
        self::assertSame(3, $series[0]['uses']);
        self::assertSame(45.0, $series[0]['discount']);
    }

    #[Test]
    public function notFoundWhenCouponNotOwned(): void
    {
        $user = $this->makeVendorUser(100);
        $vendor = $this->makeVendor(101);
        $this->bindDeps($user, $vendor, null); // findByIdAndVendor → null

        $res = $this->get($user, '/v3/vendor/coupons/999/analytics');
        self::assertSame(404, $res->getStatusCode());
    }
}
