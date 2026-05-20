<?php

declare(strict_types=1);

namespace Bayti\Api\Tests\Http\Controllers\Wishlist;

use Bayti\Api\Domain\Catalog\Product;
use Bayti\Api\Domain\Catalog\Vendor;
use Bayti\Api\Domain\User\User;
use Bayti\Api\Domain\User\UserRepository;
use Bayti\Api\Domain\Wishlist\Wishlist;
use Bayti\Api\Domain\Wishlist\WishlistLabel;
use Bayti\Api\Domain\Wishlist\WishlistLabelRepository;
use Bayti\Api\Domain\Wishlist\WishlistRepository;
use Bayti\Api\Http\Controllers\Wishlist\CreateWishlistLabelController;
use Bayti\Api\Http\Controllers\Wishlist\DeleteWishlistLabelController;
use Bayti\Api\Http\Controllers\Wishlist\Dto\WishlistLabelInput;
use Bayti\Api\Http\Controllers\Wishlist\ListWishlistLabelsController;
use Bayti\Api\Http\Controllers\Wishlist\MoveWishlistItemController;
use Bayti\Api\Http\Controllers\Wishlist\RenameWishlistLabelController;
use Bayti\Api\Http\Serializers\WishlistLabelSerializer;
use Bayti\Api\Infrastructure\Auth\JwtService;
use Bayti\Api\Tests\Http\HttpTestCase;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;

#[CoversClass(ListWishlistLabelsController::class)]
#[CoversClass(CreateWishlistLabelController::class)]
#[CoversClass(RenameWishlistLabelController::class)]
#[CoversClass(DeleteWishlistLabelController::class)]
#[CoversClass(MoveWishlistItemController::class)]
#[CoversClass(WishlistLabelInput::class)]
#[CoversClass(WishlistLabel::class)]
#[CoversClass(WishlistLabelRepository::class)]
#[CoversClass(WishlistLabelSerializer::class)]
final class WishlistLabelControllerTest extends HttpTestCase
{
    private function makeLabel(User $user, int $id, string $name): WishlistLabel
    {
        $label = new WishlistLabel($user, $name);
        $ref = new \ReflectionProperty(WishlistLabel::class, 'id');
        $ref->setAccessible(true);
        $ref->setValue($label, $id);
        return $label;
    }

    private function makeProduct(int $id): Product
    {
        $vendor = (new \ReflectionClass(Vendor::class))->newInstanceWithoutConstructor();
        foreach (['id' => 5, 'slug' => 'a-vendor', 'name' => 'A Vendor'] as $prop => $val) {
            $ref = new \ReflectionProperty(Vendor::class, $prop);
            $ref->setAccessible(true);
            $ref->setValue($vendor, $val);
        }
        $product = new Product($vendor, 'a-product', 'A Product');
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
    // GET /v3/me/wishlist/labels
    // ---------------------------------------------------------------

    #[Test]
    public function listReturnsLabelsWithCounts(): void
    {
        $user = $this->makeUser(id: 400);
        $labels = [$this->makeLabel($user, 1, 'Eid'), $this->makeLabel($user, 2, 'Work')];

        $userRepo = $this->createMock(UserRepository::class);
        $userRepo->method('findById')->willReturn($user);

        $labelRepo = $this->createMock(WishlistLabelRepository::class);
        $labelRepo->method('findForUser')->willReturn($labels);

        $wishlistRepo = $this->createMock(WishlistRepository::class);
        $wishlistRepo->method('countsByLabelForUser')->willReturn([1 => 3, 2 => 0]);

        $em = $this->stubEm(function ($em) use ($userRepo, $labelRepo, $wishlistRepo) {
            $em->method('getRepository')->willReturnMap([
                [User::class, $userRepo],
                [WishlistLabel::class, $labelRepo],
                [Wishlist::class, $wishlistRepo],
            ]);
        });
        $this->bind(EntityManagerInterface::class, $em);

        $response = $this->handle($this->jsonRequest('GET', '/v3/me/wishlist/labels', [], $this->authHeader($user)));

        self::assertSame(200, $response->getStatusCode());
        $body = $this->jsonBody($response);
        self::assertCount(2, $body['data']);
        self::assertSame('Eid', $body['data'][0]['name']);
        self::assertSame(3, $body['data'][0]['count']);
        self::assertSame(0, $body['data'][1]['count']);
    }

    #[Test]
    public function listRequiresAuth(): void
    {
        $response = $this->handle($this->jsonRequest('GET', '/v3/me/wishlist/labels', []));
        self::assertSame(401, $response->getStatusCode());
    }

    // ---------------------------------------------------------------
    // POST /v3/me/wishlist/labels
    // ---------------------------------------------------------------

    #[Test]
    public function createNewLabelReturns201(): void
    {
        $user = $this->makeUser(id: 401);
        $userRepo = $this->createMock(UserRepository::class);
        $userRepo->method('findById')->willReturn($user);

        $labelRepo = $this->createMock(WishlistLabelRepository::class);
        $labelRepo->method('findOneForUserByName')->willReturn(null);
        $labelRepo->expects(self::once())->method('save');

        $em = $this->stubEm(function ($em) use ($userRepo, $labelRepo) {
            $em->method('getRepository')->willReturnMap([
                [User::class, $userRepo],
                [WishlistLabel::class, $labelRepo],
            ]);
        });
        $this->bind(EntityManagerInterface::class, $em);

        $response = $this->handle($this->jsonRequest('POST', '/v3/me/wishlist/labels', ['name' => 'Summer'], $this->authHeader($user)));

        self::assertSame(201, $response->getStatusCode());
        $body = $this->jsonBody($response);
        self::assertSame('Summer', $body['data']['name']);
    }

    #[Test]
    public function createIsIdempotentOnNameReturns200(): void
    {
        $user = $this->makeUser(id: 402);
        $existing = $this->makeLabel($user, 9, 'Summer');

        $userRepo = $this->createMock(UserRepository::class);
        $userRepo->method('findById')->willReturn($user);

        $labelRepo = $this->createMock(WishlistLabelRepository::class);
        $labelRepo->method('findOneForUserByName')->willReturn($existing);
        $labelRepo->expects(self::never())->method('save');

        $em = $this->stubEm(function ($em) use ($userRepo, $labelRepo) {
            $em->method('getRepository')->willReturnMap([
                [User::class, $userRepo],
                [WishlistLabel::class, $labelRepo],
            ]);
        });
        $this->bind(EntityManagerInterface::class, $em);

        $response = $this->handle($this->jsonRequest('POST', '/v3/me/wishlist/labels', ['name' => 'Summer'], $this->authHeader($user)));

        self::assertSame(200, $response->getStatusCode());
        self::assertSame(9, $this->jsonBody($response)['data']['id']);
    }

    #[Test]
    public function createReturns422OnBlankName(): void
    {
        $user = $this->makeUser(id: 403);
        $userRepo = $this->createMock(UserRepository::class);
        $userRepo->method('findById')->willReturn($user);

        $em = $this->stubEm(function ($em) use ($userRepo) {
            $em->method('getRepository')->willReturnMap([[User::class, $userRepo]]);
        });
        $this->bind(EntityManagerInterface::class, $em);

        $response = $this->handle($this->jsonRequest('POST', '/v3/me/wishlist/labels', ['name' => '  '], $this->authHeader($user)));

        self::assertSame(422, $response->getStatusCode());
    }

    // ---------------------------------------------------------------
    // PATCH /v3/me/wishlist/labels/{id}
    // ---------------------------------------------------------------

    #[Test]
    public function renameLabelReturns200(): void
    {
        $user = $this->makeUser(id: 404);
        $label = $this->makeLabel($user, 12, 'Old');

        $userRepo = $this->createMock(UserRepository::class);
        $userRepo->method('findById')->willReturn($user);

        $labelRepo = $this->createMock(WishlistLabelRepository::class);
        $labelRepo->method('findOneForUser')->willReturn($label);
        $labelRepo->method('findOneForUserByName')->willReturn(null);
        $labelRepo->expects(self::once())->method('save');

        $em = $this->stubEm(function ($em) use ($userRepo, $labelRepo) {
            $em->method('getRepository')->willReturnMap([
                [User::class, $userRepo],
                [WishlistLabel::class, $labelRepo],
            ]);
        });
        $this->bind(EntityManagerInterface::class, $em);

        $response = $this->handle($this->jsonRequest('PATCH', '/v3/me/wishlist/labels/12', ['name' => 'New'], $this->authHeader($user)));

        self::assertSame(200, $response->getStatusCode());
        self::assertSame('New', $this->jsonBody($response)['data']['name']);
    }

    #[Test]
    public function renameReturns404WhenLabelMissing(): void
    {
        $user = $this->makeUser(id: 405);
        $userRepo = $this->createMock(UserRepository::class);
        $userRepo->method('findById')->willReturn($user);

        $labelRepo = $this->createMock(WishlistLabelRepository::class);
        $labelRepo->method('findOneForUser')->willReturn(null);

        $em = $this->stubEm(function ($em) use ($userRepo, $labelRepo) {
            $em->method('getRepository')->willReturnMap([
                [User::class, $userRepo],
                [WishlistLabel::class, $labelRepo],
            ]);
        });
        $this->bind(EntityManagerInterface::class, $em);

        $response = $this->handle($this->jsonRequest('PATCH', '/v3/me/wishlist/labels/999', ['name' => 'New'], $this->authHeader($user)));
        self::assertSame(404, $response->getStatusCode());
    }

    #[Test]
    public function renameReturns409OnNameClash(): void
    {
        $user = $this->makeUser(id: 406);
        $label = $this->makeLabel($user, 12, 'Old');
        $other = $this->makeLabel($user, 13, 'Taken');

        $userRepo = $this->createMock(UserRepository::class);
        $userRepo->method('findById')->willReturn($user);

        $labelRepo = $this->createMock(WishlistLabelRepository::class);
        $labelRepo->method('findOneForUser')->willReturn($label);
        $labelRepo->method('findOneForUserByName')->willReturn($other);
        $labelRepo->expects(self::never())->method('save');

        $em = $this->stubEm(function ($em) use ($userRepo, $labelRepo) {
            $em->method('getRepository')->willReturnMap([
                [User::class, $userRepo],
                [WishlistLabel::class, $labelRepo],
            ]);
        });
        $this->bind(EntityManagerInterface::class, $em);

        $response = $this->handle($this->jsonRequest('PATCH', '/v3/me/wishlist/labels/12', ['name' => 'Taken'], $this->authHeader($user)));
        self::assertSame(409, $response->getStatusCode());
    }

    // ---------------------------------------------------------------
    // DELETE /v3/me/wishlist/labels/{id}
    // ---------------------------------------------------------------

    #[Test]
    public function deleteLabelReturns204(): void
    {
        $user = $this->makeUser(id: 407);
        $label = $this->makeLabel($user, 20, 'Gone');

        $userRepo = $this->createMock(UserRepository::class);
        $userRepo->method('findById')->willReturn($user);

        $labelRepo = $this->createMock(WishlistLabelRepository::class);
        $labelRepo->method('findOneForUser')->willReturn($label);
        $labelRepo->expects(self::once())->method('remove')->with($label);

        $em = $this->stubEm(function ($em) use ($userRepo, $labelRepo) {
            $em->method('getRepository')->willReturnMap([
                [User::class, $userRepo],
                [WishlistLabel::class, $labelRepo],
            ]);
        });
        $this->bind(EntityManagerInterface::class, $em);

        $response = $this->handle($this->jsonRequest('DELETE', '/v3/me/wishlist/labels/20', [], $this->authHeader($user)));
        self::assertSame(204, $response->getStatusCode());
    }

    #[Test]
    public function deleteIsIdempotentReturns204WhenMissing(): void
    {
        $user = $this->makeUser(id: 408);
        $userRepo = $this->createMock(UserRepository::class);
        $userRepo->method('findById')->willReturn($user);

        $labelRepo = $this->createMock(WishlistLabelRepository::class);
        $labelRepo->method('findOneForUser')->willReturn(null);
        $labelRepo->expects(self::never())->method('remove');

        $em = $this->stubEm(function ($em) use ($userRepo, $labelRepo) {
            $em->method('getRepository')->willReturnMap([
                [User::class, $userRepo],
                [WishlistLabel::class, $labelRepo],
            ]);
        });
        $this->bind(EntityManagerInterface::class, $em);

        $response = $this->handle($this->jsonRequest('DELETE', '/v3/me/wishlist/labels/999', [], $this->authHeader($user)));
        self::assertSame(204, $response->getStatusCode());
    }

    // ---------------------------------------------------------------
    // PATCH /v3/me/wishlist/{productId} (move between labels)
    // ---------------------------------------------------------------

    #[Test]
    public function moveItemToLabelReturns204(): void
    {
        $user = $this->makeUser(id: 409);
        $product = $this->makeProduct(700);
        $label = $this->makeLabel($user, 30, 'Eid');
        $entry = new Wishlist($user, $product);

        $userRepo = $this->createMock(UserRepository::class);
        $userRepo->method('findById')->willReturn($user);

        $wishlistRepo = $this->createMock(WishlistRepository::class);
        $wishlistRepo->method('findOneForUserAndProduct')->willReturn($entry);
        $wishlistRepo->expects(self::once())->method('save')->with($entry);

        $labelRepo = $this->createMock(WishlistLabelRepository::class);
        $labelRepo->method('findOneForUser')->willReturn($label);

        $em = $this->stubEm(function ($em) use ($userRepo, $wishlistRepo, $labelRepo, $product) {
            $em->method('getRepository')->willReturnMap([
                [User::class, $userRepo],
                [Wishlist::class, $wishlistRepo],
                [WishlistLabel::class, $labelRepo],
            ]);
            $em->method('find')->with(Product::class, 700)->willReturn($product);
        });
        $this->bind(EntityManagerInterface::class, $em);

        $response = $this->handle($this->jsonRequest('PATCH', '/v3/me/wishlist/700', ['label_id' => 30], $this->authHeader($user)));

        self::assertSame(204, $response->getStatusCode());
        self::assertSame($label, $entry->getLabel());
    }

    #[Test]
    public function moveItemToUncategorizedClearsLabel(): void
    {
        $user = $this->makeUser(id: 410);
        $product = $this->makeProduct(701);
        $entry = new Wishlist($user, $product);
        $entry->setLabel($this->makeLabel($user, 31, 'Old'));

        $userRepo = $this->createMock(UserRepository::class);
        $userRepo->method('findById')->willReturn($user);

        $wishlistRepo = $this->createMock(WishlistRepository::class);
        $wishlistRepo->method('findOneForUserAndProduct')->willReturn($entry);
        $wishlistRepo->expects(self::once())->method('save');

        $em = $this->stubEm(function ($em) use ($userRepo, $wishlistRepo, $product) {
            $em->method('getRepository')->willReturnMap([
                [User::class, $userRepo],
                [Wishlist::class, $wishlistRepo],
            ]);
            $em->method('find')->with(Product::class, 701)->willReturn($product);
        });
        $this->bind(EntityManagerInterface::class, $em);

        $response = $this->handle($this->jsonRequest('PATCH', '/v3/me/wishlist/701', ['label_id' => 0], $this->authHeader($user)));

        self::assertSame(204, $response->getStatusCode());
        self::assertNull($entry->getLabel());
    }

    #[Test]
    public function moveItemReturns404WhenNotOnWishlist(): void
    {
        $user = $this->makeUser(id: 411);
        $product = $this->makeProduct(702);

        $userRepo = $this->createMock(UserRepository::class);
        $userRepo->method('findById')->willReturn($user);

        $wishlistRepo = $this->createMock(WishlistRepository::class);
        $wishlistRepo->method('findOneForUserAndProduct')->willReturn(null);

        $em = $this->stubEm(function ($em) use ($userRepo, $wishlistRepo, $product) {
            $em->method('getRepository')->willReturnMap([
                [User::class, $userRepo],
                [Wishlist::class, $wishlistRepo],
            ]);
            $em->method('find')->with(Product::class, 702)->willReturn($product);
        });
        $this->bind(EntityManagerInterface::class, $em);

        $response = $this->handle($this->jsonRequest('PATCH', '/v3/me/wishlist/702', ['label_id' => 5], $this->authHeader($user)));
        self::assertSame(404, $response->getStatusCode());
    }
}
