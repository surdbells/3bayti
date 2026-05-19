<?php

declare(strict_types=1);

namespace Bayti\Api\Tests\Http\Controllers\Admin\Catalog;

use Bayti\Api\Domain\Audit\AuditEmitter;
use Bayti\Api\Domain\Audit\AuditLog;
use Bayti\Api\Domain\Catalog\Product;
use Bayti\Api\Domain\Catalog\ProductRepository;
use Bayti\Api\Domain\Catalog\RecommendationsService;
use Bayti\Api\Domain\Catalog\Vendor;
use Bayti\Api\Domain\User\User;
use Bayti\Api\Domain\User\UserRepository;
use Bayti\Api\Http\Controllers\Admin\Catalog\GetAdminRecommendationsExplainController;
use Bayti\Api\Http\Serializers\ProductSerializer;
use Bayti\Api\Http\Serializers\RecommendationsSerializer;
use Bayti\Api\Infrastructure\Auth\JwtService;
use Bayti\Api\Tests\Http\HttpTestCase;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use Psr\Http\Message\ResponseInterface;
use Psr\Log\NullLogger;

/**
 * HTTP tests for GET /v3/admin/recommendations/{product_id}/explain
 * (M3.2.X.12-G).
 *
 * Admin-only, audited via AuditEmitter::recordView.
 */
#[CoversClass(GetAdminRecommendationsExplainController::class)]
#[CoversClass(RecommendationsSerializer::class)]
final class GetAdminRecommendationsExplainControllerTest extends HttpTestCase
{
    /** @var list<AuditLog> */
    private array $recordedAuditLogs = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->recordedAuditLogs = [];
    }

    #[Test]
    public function returnsExplainEnvelopeForAdmin(): void
    {
        $admin = $this->makeAdminUser(99);
        $vendor = $this->makeVendor();
        $source = $this->makeProduct(100, $vendor, 'source-product');
        $target1 = $this->makeProduct(200, $vendor, 'recommendation-one');
        $target2 = $this->makeProduct(300, $vendor, 'recommendation-two');

        $this->bindDeps($admin, $source, [
            ['product' => $target1, 'score' => '23.0000', 'source' => 'copurchase', 'rank' => 1],
            ['product' => $target2, 'score' => '1.0000', 'source' => 'category', 'rank' => 2],
        ]);

        $response = $this->makeGet($admin, '/v3/admin/recommendations/100/explain');

        self::assertSame(200, $response->getStatusCode());
        $body = $this->jsonBody($response);

        self::assertSame(100, $body['data']['product_id']);
        self::assertSame(2, $body['data']['total_recommendations']);

        // by_source breakdown
        self::assertSame(1, $body['data']['by_source']['copurchase']['count']);
        self::assertSame(1, $body['data']['by_source']['category']['count']);
        self::assertSame(0, $body['data']['by_source']['fallback_popular']['count']);

        // Copurchase row preserves rank
        $copurchaseRow = $body['data']['by_source']['copurchase']['rows'][0];
        self::assertSame(200, $copurchaseRow['product']['id']);
        self::assertSame('23.0000', $copurchaseRow['score']);
        self::assertSame(1, $copurchaseRow['rank']);
    }

    #[Test]
    public function recordsAuditViewOnSuccess(): void
    {
        $admin = $this->makeAdminUser(99);
        $vendor = $this->makeVendor();
        $source = $this->makeProduct(100, $vendor, 'source-product');
        $target = $this->makeProduct(200, $vendor, 'rec');

        $this->bindDeps($admin, $source, [
            ['product' => $target, 'score' => '10.0000', 'source' => 'copurchase', 'rank' => 1],
        ]);

        $response = $this->makeGet($admin, '/v3/admin/recommendations/100/explain');
        self::assertSame(200, $response->getStatusCode());

        self::assertCount(1, $this->recordedAuditLogs);
        $audit = $this->recordedAuditLogs[0];
        self::assertSame(AuditLog::ACTION_VIEWED, $audit->getAction());
        self::assertSame('Product', $audit->getSubjectType());
        self::assertSame(100, $audit->getSubjectId());

        $changes = $audit->getChanges();
        self::assertSame('admin_recommendations_explain', $changes['context']);
        self::assertSame(1, $changes['recommendation_count']);
    }

    #[Test]
    public function requiresAdmin(): void
    {
        $regularUser = $this->makeUser(id: 200);
        $vendor = $this->makeVendor();
        $product = $this->makeProduct(100, $vendor, 'p');
        $this->bindDeps($regularUser, $product, []);

        $response = $this->makeGet($regularUser, '/v3/admin/recommendations/100/explain');
        self::assertSame(403, $response->getStatusCode());

        self::assertCount(0, $this->recordedAuditLogs);
    }

    #[Test]
    public function nonExistentProductReturns404(): void
    {
        $admin = $this->makeAdminUser(99);
        $this->bindDeps($admin, product: null, recs: []);

        $response = $this->makeGet($admin, '/v3/admin/recommendations/999/explain');
        self::assertSame(404, $response->getStatusCode());
        self::assertCount(0, $this->recordedAuditLogs);
    }

    #[Test]
    public function emptyExplainStillReturns200WithZeroCounts(): void
    {
        $admin = $this->makeAdminUser(99);
        $vendor = $this->makeVendor();
        $source = $this->makeProduct(100, $vendor, 'source-product');
        $this->bindDeps($admin, $source, []);

        $response = $this->makeGet($admin, '/v3/admin/recommendations/100/explain');
        self::assertSame(200, $response->getStatusCode());

        $body = $this->jsonBody($response);
        self::assertSame(0, $body['data']['total_recommendations']);
        self::assertSame(0, $body['data']['by_source']['copurchase']['count']);
        self::assertSame(0, $body['data']['by_source']['category']['count']);
        self::assertSame(0, $body['data']['by_source']['fallback_popular']['count']);
    }

    // =================================================================
    // Helpers
    // =================================================================

    private function makeAdminUser(int $id): User
    {
        $user = $this->makeUser(id: $id);
        $user->setRoles(admin: true);
        return $user;
    }

    private function makeVendor(): Vendor
    {
        $vendor = new Vendor('test-vendor', 'Test Vendor', 'tv@example.com');
        $ref = new \ReflectionProperty(Vendor::class, 'id');
        $ref->setAccessible(true);
        $ref->setValue($vendor, 5);
        return $vendor;
    }

    private function makeProduct(int $id, Vendor $vendor, string $slug): Product
    {
        $product = new Product($vendor, $slug, "Product {$id}");
        $idRef = new \ReflectionProperty(Product::class, 'id');
        $idRef->setAccessible(true);
        $idRef->setValue($product, $id);
        $product->setPrice('100.00');
        return $product;
    }

    /**
     * @param list<array{product: Product, score: string, source: string, rank: int}> $recs
     */
    private function bindDeps(User $user, ?Product $product, array $recs): void
    {
        $userRepo = $this->createMock(UserRepository::class);
        $userRepo->method('findById')->willReturn($user);

        $productRepo = $this->createMock(ProductRepository::class);
        $productRepo->method('find')->willReturn($product);

        $auditRepo = new class($this->recordedAuditLogs) extends \Doctrine\ORM\EntityRepository {
            /** @param list<AuditLog> $sink */
            public function __construct(private array &$sink)
            {
            }
            public function save(AuditLog $log): void
            {
                $this->sink[] = $log;
            }
            public function getClassName(): string
            {
                return AuditLog::class;
            }
        };

        $em = $this->stubEm(function ($em) use ($userRepo, $productRepo, $auditRepo) {
            $em->method('getRepository')->willReturnMap([
                [User::class, $userRepo],
                [Product::class, $productRepo],
                [AuditLog::class, $auditRepo],
            ]);
        });
        $this->bind(EntityManagerInterface::class, $em);
        $this->bind(AuditEmitter::class, new AuditEmitter($em, new NullLogger()));

        $service = $this->createMock(RecommendationsService::class);
        $service->method('getExplainForProduct')->willReturn($recs);
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
}
