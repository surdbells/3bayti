<?php

declare(strict_types=1);

namespace Bayti\Api\Tests\Http\Controllers\Following;

use Bayti\Api\Domain\Catalog\Vendor;
use Bayti\Api\Domain\Following\VendorFollow;
use Bayti\Api\Domain\Following\VendorFollowRepository;
use Bayti\Api\Domain\User\User;
use Bayti\Api\Http\Controllers\Following\ListFollowingController;
use Bayti\Api\Http\Errors\HttpException;
use Bayti\Api\Http\Middleware\AuthMiddleware;
use Bayti\Api\Http\Serializers\VendorSerializer;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Slim\Psr7\Factory\ResponseFactory;
use Slim\Psr7\Factory\ServerRequestFactory;

#[CoversClass(ListFollowingController::class)]
#[CoversClass(VendorSerializer::class)]
final class ListFollowingControllerTest extends TestCase
{
    #[Test]
    public function returnsFollowedStoresWithIsFollowingTrueAndMeta(): void
    {
        $user = new User('u@example.com', '+971500000000', password_hash('p', PASSWORD_BCRYPT), 'AE');
        $rows = [$this->makeFollow(1, 'atelier'), $this->makeFollow(2, 'maison')];

        $repo = $this->createMock(VendorFollowRepository::class);
        $repo->method('findForUserPaginated')->willReturn($rows);
        $repo->method('countForUser')->willReturn(2);

        $em = $this->createMock(EntityManagerInterface::class);
        $em->method('getRepository')->willReturn($repo);

        $response = (new ListFollowingController(new ResponseFactory(), $em, new VendorSerializer()))(
            $this->request($user),
        );

        self::assertSame(200, $response->getStatusCode());
        $body = json_decode((string) $response->getBody(), true);
        self::assertCount(2, $body['data']);
        self::assertSame('atelier', $body['data'][0]['slug']);
        self::assertTrue($body['data'][0]['is_following']);
        self::assertSame(2, $body['meta']['total']);
    }

    #[Test]
    public function requiresAuthentication(): void
    {
        $em = $this->createMock(EntityManagerInterface::class);

        $this->expectException(HttpException::class);
        $this->expectExceptionMessage('Authentication required');
        (new ListFollowingController(new ResponseFactory(), $em, new VendorSerializer()))(
            (new ServerRequestFactory())->createServerRequest('GET', '/v3/me/following'),
        );
    }

    #[Test]
    public function publicShapeOmitsIsFollowingWhenNullAndIncludesItWhenBool(): void
    {
        $serializer = new VendorSerializer();
        $vendor = $this->makeVendor(9, 'store-nine');

        self::assertArrayNotHasKey('is_following', $serializer->publicShape($vendor));
        self::assertTrue($serializer->publicShape($vendor, true)['is_following']);
        self::assertFalse($serializer->publicShape($vendor, false)['is_following']);
    }

    // -----------------------------------------------------------------

    private function makeFollow(int $vendorId, string $slug): VendorFollow
    {
        $follow = $this->createMock(VendorFollow::class);
        $follow->method('getVendor')->willReturn($this->makeVendor($vendorId, $slug));
        return $follow;
    }

    private function makeVendor(int $id, string $slug): Vendor
    {
        $vendor = $this->createMock(Vendor::class);
        $vendor->method('getId')->willReturn($id);
        $vendor->method('getSlug')->willReturn($slug);
        $vendor->method('getName')->willReturn(ucfirst($slug));
        $vendor->method('getDescription')->willReturn(null);
        $vendor->method('getLogoUrl')->willReturn(null);
        $vendor->method('getCoverImageUrl')->willReturn(null);
        $vendor->method('isVerified')->willReturn(false);
        return $vendor;
    }

    private function request(User $user): \Psr\Http\Message\ServerRequestInterface
    {
        return (new ServerRequestFactory())
            ->createServerRequest('GET', '/v3/me/following')
            ->withAttribute(AuthMiddleware::ATTR_USER, $user);
    }
}
