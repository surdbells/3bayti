<?php

declare(strict_types=1);

namespace Bayti\Api\Tests\Http\Controllers\Me\Ai;

use Bayti\Api\Ai\Personalization\ForYouRail;
use Bayti\Api\Ai\Personalization\ForYouRailsService;
use Bayti\Api\Ai\Personalization\ForYouRailSet;
use Bayti\Api\Domain\Catalog\Product;
use Bayti\Api\Domain\Catalog\Vendor;
use Bayti\Api\Domain\User\User;
use Bayti\Api\Domain\User\UserRepository;
use Bayti\Api\Http\Controllers\Me\Ai\GetForYouRailsController;
use Bayti\Api\Http\Serializers\ForYouSerializer;
use Bayti\Api\Http\Serializers\ProductSerializer;
use Bayti\Api\Infrastructure\Auth\JwtService;
use Bayti\Api\Tests\Http\HttpTestCase;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use Psr\Http\Message\ResponseInterface;

#[CoversClass(GetForYouRailsController::class)]
#[CoversClass(ForYouSerializer::class)]
final class GetForYouRailsControllerTest extends HttpTestCase
{
    private int $capturedPerRail = 0;

    protected function setUp(): void
    {
        parent::setUp();
        $this->capturedPerRail = 0;
    }

    #[Test]
    public function unauthenticatedRequestReturns401(): void
    {
        $this->bindDeps($this->makeUser(id: 7), new ForYouRailSet(false, []));
        $response = $this->handle($this->jsonRequest('GET', '/v3/me/ai/for-you', []));
        self::assertSame(401, $response->getStatusCode());
    }

    #[Test]
    public function authenticatedRequestReturnsRailsShape(): void
    {
        $user = $this->makeUser(id: 7);
        $vendor = $this->makeVendor();
        $set = new ForYouRailSet(true, [
            new ForYouRail('your_style', [$this->makeProduct(200, $vendor), $this->makeProduct(300, $vendor)]),
            new ForYouRail('because_you_liked', [$this->makeProduct(400, $vendor)], 'Shadow Rose'),
        ]);

        $this->bindDeps($user, $set);
        $response = $this->makeGet($user, '/v3/me/ai/for-you');

        self::assertSame(200, $response->getStatusCode());
        $body = $this->jsonBody($response);
        self::assertTrue($body['profile_ready']);
        self::assertCount(2, $body['rails']);
        self::assertSame('your_style', $body['rails'][0]['key']);
        self::assertSame(200, $body['rails'][0]['products'][0]['id']);
        self::assertArrayNotHasKey('seed_name', $body['rails'][0]);
        self::assertSame('because_you_liked', $body['rails'][1]['key']);
        self::assertSame('Shadow Rose', $body['rails'][1]['seed_name']);
    }

    #[Test]
    public function perRailLimitForwarded(): void
    {
        $user = $this->makeUser(id: 7);
        $this->bindDeps($user, new ForYouRailSet(true, []));

        $this->makeGet($user, '/v3/me/ai/for-you?limit=6');
        self::assertSame(6, $this->capturedPerRail);
    }

    // ===== helpers =====

    private function bindDeps(User $user, ForYouRailSet $set): void
    {
        $userRepo = $this->createMock(UserRepository::class);
        $userRepo->method('findById')->willReturn($user);

        $em = $this->stubEm(function ($em) use ($userRepo): void {
            $em->method('getRepository')->willReturnMap([[User::class, $userRepo]]);
        });
        $this->bind(EntityManagerInterface::class, $em);

        $service = $this->createMock(ForYouRailsService::class);
        $service->method('build')->willReturnCallback(
            function (User $u, int $perRail) use ($set): ForYouRailSet {
                $this->capturedPerRail = $perRail;
                return $set;
            },
        );
        $this->bind(ForYouRailsService::class, $service);
        $this->bind(ForYouSerializer::class, new ForYouSerializer(new ProductSerializer()));
    }

    private function makeGet(User $user, string $uri): ResponseInterface
    {
        $jwt = $this->app->getContainer()->get(JwtService::class);
        $pair = $jwt->issueTokenPair($user);
        return $this->handle($this->jsonRequest('GET', $uri, [], [
            'Authorization' => 'Bearer ' . $pair->accessToken,
        ]));
    }

    private function makeVendor(): Vendor
    {
        $vendor = new Vendor('test-vendor', 'Test Vendor', 'tv@example.com');
        $this->setId($vendor, 5);
        return $vendor;
    }

    private function makeProduct(int $id, Vendor $vendor): Product
    {
        $product = new Product($vendor, "slug-{$id}", "Product {$id}");
        $this->setId($product, $id);
        $product->setPrice('100.00');
        return $product;
    }

    private function setId(object $entity, int $id): void
    {
        $ref = new \ReflectionProperty($entity::class, 'id');
        $ref->setAccessible(true);
        $ref->setValue($entity, $id);
    }
}
