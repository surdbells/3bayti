<?php

declare(strict_types=1);

namespace Bayti\Api\Tests\Http\Controllers\Me;

use Bayti\Api\Domain\Catalog\Product;
use Bayti\Api\Domain\Catalog\RecommendationsService;
use Bayti\Api\Domain\Catalog\Vendor;
use Bayti\Api\Domain\User\User;
use Bayti\Api\Domain\User\UserRepository;
use Bayti\Api\Http\Controllers\Me\GetMeRecommendationsController;
use Bayti\Api\Http\Serializers\ProductSerializer;
use Bayti\Api\Http\Serializers\RecommendationsSerializer;
use Bayti\Api\Infrastructure\Auth\JwtService;
use Bayti\Api\Tests\Http\HttpTestCase;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use Psr\Http\Message\ResponseInterface;

/**
 * HTTP tests for GET /v3/me/recommendations (M3.2.X.12-G).
 *
 * Requires authentication (AuthMiddleware enforced via group).
 * Personalization via the user-specific
 * RecommendationsService::getRecommendationsForUser path.
 */
#[CoversClass(GetMeRecommendationsController::class)]
#[CoversClass(RecommendationsSerializer::class)]
final class GetMeRecommendationsControllerTest extends HttpTestCase
{
    private int $capturedUserId = 0;
    private int $capturedLimit = 0;

    protected function setUp(): void
    {
        parent::setUp();
        $this->capturedUserId = 0;
        $this->capturedLimit = 0;
    }

    #[Test]
    public function unauthenticatedRequestReturns401(): void
    {
        $this->bindDeps(user: $this->makeUser(id: 7), recommendations: []);

        $response = $this->handle($this->jsonRequest('GET', '/v3/me/recommendations', []));
        self::assertSame(401, $response->getStatusCode());
    }

    #[Test]
    public function authenticatedRequestReturnsPersonalizedRecs(): void
    {
        $user = $this->makeUser(id: 7);
        $vendor = $this->makeVendor();
        $product1 = $this->makeProduct(200, $vendor);
        $product2 = $this->makeProduct(300, $vendor);

        $this->bindDeps($user, [
            ['product' => $product1, 'score' => '50.0000', 'source' => 'category'],
            ['product' => $product2, 'score' => '30.0000', 'source' => 'category'],
        ]);

        $response = $this->makeGet($user, '/v3/me/recommendations');

        self::assertSame(200, $response->getStatusCode());
        $body = $this->jsonBody($response);
        self::assertCount(2, $body['data']);
        self::assertSame(200, $body['data'][0]['product']['id']);
        self::assertSame('50.0000', $body['data'][0]['score']);
        self::assertSame('category', $body['data'][0]['source']);

        // User id forwarded
        self::assertSame(7, $this->capturedUserId);
    }

    #[Test]
    public function limitForwarded(): void
    {
        $user = $this->makeUser(id: 7);
        $this->bindDeps($user, []);

        $this->makeGet($user, '/v3/me/recommendations?limit=8');
        self::assertSame(8, $this->capturedLimit);
    }

    #[Test]
    public function defaultLimitWhenUnspecified(): void
    {
        $user = $this->makeUser(id: 7);
        $this->bindDeps($user, []);

        $this->makeGet($user, '/v3/me/recommendations');
        self::assertSame(RecommendationsService::DEFAULT_LIMIT, $this->capturedLimit);
    }

    #[Test]
    public function emptyRecommendationsReturnEmptyArray(): void
    {
        $user = $this->makeUser(id: 7);
        $this->bindDeps($user, []);

        $response = $this->makeGet($user, '/v3/me/recommendations');
        self::assertSame(200, $response->getStatusCode());
        $body = $this->jsonBody($response);
        self::assertSame([], $body['data']);
        self::assertSame(0, $body['meta']['total']);
    }

    // =================================================================
    // Helpers
    // =================================================================

    /**
     * @param list<array{product: Product, score: string, source: string}> $recommendations
     */
    private function bindDeps(User $user, array $recommendations): void
    {
        $userRepo = $this->createMock(UserRepository::class);
        $userRepo->method('findById')->willReturn($user);

        $em = $this->stubEm(function ($em) use ($userRepo) {
            $em->method('getRepository')->willReturnMap([
                [User::class, $userRepo],
            ]);
        });
        $this->bind(EntityManagerInterface::class, $em);

        $service = $this->createMock(RecommendationsService::class);
        $service->method('getRecommendationsForUser')->willReturnCallback(
            function (int $uid, int $limit) use ($recommendations): array {
                $this->capturedUserId = $uid;
                $this->capturedLimit = $limit;
                return $recommendations;
            },
        );
        $this->bind(RecommendationsService::class, $service);

        $this->bind(
            RecommendationsSerializer::class,
            new RecommendationsSerializer(new ProductSerializer()),
        );
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
        $ref = new \ReflectionProperty(Vendor::class, 'id');
        $ref->setAccessible(true);
        $ref->setValue($vendor, 5);
        return $vendor;
    }

    private function makeProduct(int $id, Vendor $vendor): Product
    {
        $product = new Product($vendor, "slug-{$id}", "Product {$id}");
        $idRef = new \ReflectionProperty(Product::class, 'id');
        $idRef->setAccessible(true);
        $idRef->setValue($product, $id);
        $product->setPrice('100.00');
        return $product;
    }
}
