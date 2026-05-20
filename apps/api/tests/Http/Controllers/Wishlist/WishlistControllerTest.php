<?php

declare(strict_types=1);

namespace Bayti\Api\Tests\Http\Controllers\Wishlist;

use Bayti\Api\Domain\Catalog\Product;
use Bayti\Api\Domain\Catalog\Vendor;
use Bayti\Api\Domain\User\User;
use Bayti\Api\Domain\User\UserRepository;
use Bayti\Api\Domain\Wishlist\Wishlist;
use Bayti\Api\Domain\Wishlist\WishlistRepository;
use Bayti\Api\Http\Controllers\Wishlist\AddWishlistItemController;
use Bayti\Api\Http\Controllers\Wishlist\Dto\AddWishlistItemInput;
use Bayti\Api\Http\Controllers\Wishlist\ListWishlistController;
use Bayti\Api\Http\Controllers\Wishlist\RemoveWishlistItemController;
use Bayti\Api\Infrastructure\Auth\JwtService;
use Bayti\Api\Tests\Http\HttpTestCase;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;

#[CoversClass(ListWishlistController::class)]
#[CoversClass(AddWishlistItemController::class)]
#[CoversClass(RemoveWishlistItemController::class)]
#[CoversClass(AddWishlistItemInput::class)]
#[CoversClass(Wishlist::class)]
#[CoversClass(WishlistRepository::class)]
final class WishlistControllerTest extends HttpTestCase
{
    private function makeProduct(int $id, string $slug = 'a-product'): Product
    {
        $vendor = (new \ReflectionClass(Vendor::class))->newInstanceWithoutConstructor();
        foreach (['id' => 5, 'slug' => 'a-vendor', 'name' => 'A Vendor'] as $prop => $val) {
            $ref = new \ReflectionProperty(Vendor::class, $prop);
            $ref->setAccessible(true);
            $ref->setValue($vendor, $val);
        }

        $product = new Product($vendor, $slug, 'A Product');
        $idRef = new \ReflectionProperty(Product::class, 'id');
        $idRef->setAccessible(true);
        $idRef->setValue($product, $id);
        $product->setPrice('100.00');
        $product->setStatus('active');
        return $product;
    }

    private function authHeader(User $user): array
    {
        $jwt = $this->app->getContainer()->get(JwtService::class);
        $pair = $jwt->issueTokenPair($user);
        return ['Authorization' => 'Bearer ' . $pair->accessToken];
    }

    // ---------------------------------------------------------------
    // GET /v3/me/wishlist
    // ---------------------------------------------------------------

    #[Test]
    public function getReturnsEmptyEnvelopeWhenNothingSaved(): void
    {
        $user = $this->makeUser(id: 300);
        $userRepo = $this->createMock(UserRepository::class);
        $userRepo->method('findById')->willReturn($user);

        $wishlistRepo = $this->createMock(WishlistRepository::class);
        $wishlistRepo->method('findForUserPaginated')->willReturn([]);
        $wishlistRepo->method('countForUser')->willReturn(0);

        $em = $this->stubEm(function ($em) use ($userRepo, $wishlistRepo) {
            $em->method('getRepository')->willReturnMap([
                [User::class, $userRepo],
                [Wishlist::class, $wishlistRepo],
            ]);
        });
        $this->bind(EntityManagerInterface::class, $em);

        $response = $this->handle(
            $this->jsonRequest('GET', '/v3/me/wishlist', [], $this->authHeader($user))
        );

        self::assertSame(200, $response->getStatusCode());
        $body = $this->jsonBody($response);
        self::assertSame([], $body['data']);
        self::assertSame(0, $body['meta']['total']);
        self::assertFalse($body['meta']['has_more']);
    }

    #[Test]
    public function getReturnsSavedProductsInListShape(): void
    {
        $user = $this->makeUser(id: 301);
        $product = $this->makeProduct(900, 'saved-product');
        $entry = new Wishlist($user, $product);

        $userRepo = $this->createMock(UserRepository::class);
        $userRepo->method('findById')->willReturn($user);

        $wishlistRepo = $this->createMock(WishlistRepository::class);
        $wishlistRepo->method('findForUserPaginated')->willReturn([$entry]);
        $wishlistRepo->method('countForUser')->willReturn(1);

        $em = $this->stubEm(function ($em) use ($userRepo, $wishlistRepo) {
            $em->method('getRepository')->willReturnMap([
                [User::class, $userRepo],
                [Wishlist::class, $wishlistRepo],
            ]);
        });
        $this->bind(EntityManagerInterface::class, $em);

        $response = $this->handle(
            $this->jsonRequest('GET', '/v3/me/wishlist', [], $this->authHeader($user))
        );

        self::assertSame(200, $response->getStatusCode());
        $body = $this->jsonBody($response);
        self::assertCount(1, $body['data']);
        self::assertSame(900, $body['data'][0]['id']);
        self::assertSame(1, $body['meta']['total']);
    }

    #[Test]
    public function getRequiresAuth(): void
    {
        $response = $this->handle($this->jsonRequest('GET', '/v3/me/wishlist', []));
        self::assertSame(401, $response->getStatusCode());
    }

    #[Test]
    public function getPassesLabelFilterToTheRepository(): void
    {
        $user = $this->makeUser(id: 320);
        $userRepo = $this->createMock(UserRepository::class);
        $userRepo->method('findById')->willReturn($user);

        $wishlistRepo = $this->createMock(WishlistRepository::class);
        // label_id=7 → the repo must receive 7 as the label filter.
        $wishlistRepo->expects(self::once())
            ->method('findForUserPaginated')
            ->with($user, self::anything(), self::anything(), 7)
            ->willReturn([]);
        $wishlistRepo->method('countForUser')->willReturn(0);

        $em = $this->stubEm(function ($em) use ($userRepo, $wishlistRepo) {
            $em->method('getRepository')->willReturnMap([
                [User::class, $userRepo],
                [Wishlist::class, $wishlistRepo],
            ]);
        });
        $this->bind(EntityManagerInterface::class, $em);

        $response = $this->handle(
            $this->jsonRequest('GET', '/v3/me/wishlist?label_id=7', [], $this->authHeader($user))
        );
        self::assertSame(200, $response->getStatusCode());
    }

    #[Test]
    public function getPassesUncategorizedFilterForLabelNone(): void
    {
        $user = $this->makeUser(id: 321);
        $userRepo = $this->createMock(UserRepository::class);
        $userRepo->method('findById')->willReturn($user);

        $wishlistRepo = $this->createMock(WishlistRepository::class);
        // label_id=none → null (uncategorized only).
        $wishlistRepo->expects(self::once())
            ->method('findForUserPaginated')
            ->with($user, self::anything(), self::anything(), null)
            ->willReturn([]);
        $wishlistRepo->method('countForUser')->willReturn(0);

        $em = $this->stubEm(function ($em) use ($userRepo, $wishlistRepo) {
            $em->method('getRepository')->willReturnMap([
                [User::class, $userRepo],
                [Wishlist::class, $wishlistRepo],
            ]);
        });
        $this->bind(EntityManagerInterface::class, $em);

        $response = $this->handle(
            $this->jsonRequest('GET', '/v3/me/wishlist?label_id=none', [], $this->authHeader($user))
        );
        self::assertSame(200, $response->getStatusCode());
    }

    // ---------------------------------------------------------------
    // POST /v3/me/wishlist
    // ---------------------------------------------------------------

    #[Test]
    public function postSavesNewProductReturns201(): void
    {
        $user = $this->makeUser(id: 302);
        $product = $this->makeProduct(901);

        $userRepo = $this->createMock(UserRepository::class);
        $userRepo->method('findById')->willReturn($user);

        $wishlistRepo = $this->createMock(WishlistRepository::class);
        $wishlistRepo->method('findOneForUserAndProduct')->willReturn(null);
        $wishlistRepo->expects(self::once())->method('save');

        $em = $this->stubEm(function ($em) use ($userRepo, $wishlistRepo, $product) {
            $em->method('getRepository')->willReturnMap([
                [User::class, $userRepo],
                [Wishlist::class, $wishlistRepo],
            ]);
            $em->method('find')->with(Product::class, 901)->willReturn($product);
        });
        $this->bind(EntityManagerInterface::class, $em);

        $response = $this->handle(
            $this->jsonRequest('POST', '/v3/me/wishlist', ['product_id' => 901], $this->authHeader($user))
        );

        self::assertSame(201, $response->getStatusCode());
        $body = $this->jsonBody($response);
        self::assertSame(901, $body['data']['id']);
    }

    #[Test]
    public function postIsIdempotentReturns200WhenAlreadySaved(): void
    {
        $user = $this->makeUser(id: 303);
        $product = $this->makeProduct(902);
        $existing = new Wishlist($user, $product);

        $userRepo = $this->createMock(UserRepository::class);
        $userRepo->method('findById')->willReturn($user);

        $wishlistRepo = $this->createMock(WishlistRepository::class);
        $wishlistRepo->method('findOneForUserAndProduct')->willReturn($existing);
        // Must NOT save a duplicate.
        $wishlistRepo->expects(self::never())->method('save');

        $em = $this->stubEm(function ($em) use ($userRepo, $wishlistRepo, $product) {
            $em->method('getRepository')->willReturnMap([
                [User::class, $userRepo],
                [Wishlist::class, $wishlistRepo],
            ]);
            $em->method('find')->with(Product::class, 902)->willReturn($product);
        });
        $this->bind(EntityManagerInterface::class, $em);

        $response = $this->handle(
            $this->jsonRequest('POST', '/v3/me/wishlist', ['product_id' => 902], $this->authHeader($user))
        );

        self::assertSame(200, $response->getStatusCode());
        $body = $this->jsonBody($response);
        self::assertSame(902, $body['data']['id']);
    }

    #[Test]
    public function postReturns404WhenProductMissing(): void
    {
        $user = $this->makeUser(id: 304);

        $userRepo = $this->createMock(UserRepository::class);
        $userRepo->method('findById')->willReturn($user);

        $wishlistRepo = $this->createMock(WishlistRepository::class);
        $wishlistRepo->expects(self::never())->method('save');

        $em = $this->stubEm(function ($em) use ($userRepo, $wishlistRepo) {
            $em->method('getRepository')->willReturnMap([
                [User::class, $userRepo],
                [Wishlist::class, $wishlistRepo],
            ]);
            $em->method('find')->with(Product::class, 999)->willReturn(null);
        });
        $this->bind(EntityManagerInterface::class, $em);

        $response = $this->handle(
            $this->jsonRequest('POST', '/v3/me/wishlist', ['product_id' => 999], $this->authHeader($user))
        );

        self::assertSame(404, $response->getStatusCode());
    }

    #[Test]
    public function postReturns422WhenProductIdMissing(): void
    {
        $user = $this->makeUser(id: 305);
        $userRepo = $this->createMock(UserRepository::class);
        $userRepo->method('findById')->willReturn($user);

        $em = $this->stubEm(function ($em) use ($userRepo) {
            $em->method('getRepository')->willReturnMap([
                [User::class, $userRepo],
            ]);
        });
        $this->bind(EntityManagerInterface::class, $em);

        $response = $this->handle(
            $this->jsonRequest('POST', '/v3/me/wishlist', [], $this->authHeader($user))
        );

        self::assertSame(422, $response->getStatusCode());
        $body = $this->jsonBody($response);
        self::assertSame('VALIDATION_FAILED', $body['error']['code']);
    }

    #[Test]
    public function postRequiresAuth(): void
    {
        $response = $this->handle(
            $this->jsonRequest('POST', '/v3/me/wishlist', ['product_id' => 1])
        );
        self::assertSame(401, $response->getStatusCode());
    }

    // ---------------------------------------------------------------
    // DELETE /v3/me/wishlist/{productId}
    // ---------------------------------------------------------------

    #[Test]
    public function deleteRemovesSavedProductReturns204(): void
    {
        $user = $this->makeUser(id: 306);
        $product = $this->makeProduct(903);
        $existing = new Wishlist($user, $product);

        $userRepo = $this->createMock(UserRepository::class);
        $userRepo->method('findById')->willReturn($user);

        $wishlistRepo = $this->createMock(WishlistRepository::class);
        $wishlistRepo->method('findOneForUserAndProduct')->willReturn($existing);
        $wishlistRepo->expects(self::once())->method('remove')->with($existing);

        $em = $this->stubEm(function ($em) use ($userRepo, $wishlistRepo, $product) {
            $em->method('getRepository')->willReturnMap([
                [User::class, $userRepo],
                [Wishlist::class, $wishlistRepo],
            ]);
            $em->method('find')->with(Product::class, 903)->willReturn($product);
        });
        $this->bind(EntityManagerInterface::class, $em);

        $response = $this->handle(
            $this->jsonRequest('DELETE', '/v3/me/wishlist/903', [], $this->authHeader($user))
        );

        self::assertSame(204, $response->getStatusCode());
    }

    #[Test]
    public function deleteIsIdempotentReturns204WhenNotSaved(): void
    {
        $user = $this->makeUser(id: 307);
        $product = $this->makeProduct(904);

        $userRepo = $this->createMock(UserRepository::class);
        $userRepo->method('findById')->willReturn($user);

        $wishlistRepo = $this->createMock(WishlistRepository::class);
        $wishlistRepo->method('findOneForUserAndProduct')->willReturn(null);
        $wishlistRepo->expects(self::never())->method('remove');

        $em = $this->stubEm(function ($em) use ($userRepo, $wishlistRepo, $product) {
            $em->method('getRepository')->willReturnMap([
                [User::class, $userRepo],
                [Wishlist::class, $wishlistRepo],
            ]);
            $em->method('find')->with(Product::class, 904)->willReturn($product);
        });
        $this->bind(EntityManagerInterface::class, $em);

        $response = $this->handle(
            $this->jsonRequest('DELETE', '/v3/me/wishlist/904', [], $this->authHeader($user))
        );

        self::assertSame(204, $response->getStatusCode());
    }

    #[Test]
    public function deleteRequiresAuth(): void
    {
        $response = $this->handle($this->jsonRequest('DELETE', '/v3/me/wishlist/1', []));
        self::assertSame(401, $response->getStatusCode());
    }
}
