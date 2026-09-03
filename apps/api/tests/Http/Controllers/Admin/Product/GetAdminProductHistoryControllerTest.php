<?php

declare(strict_types=1);

namespace Bayti\Api\Tests\Http\Controllers\Admin\Product;

use Bayti\Api\Domain\Audit\AuditLog;
use Bayti\Api\Domain\Catalog\Product;
use Bayti\Api\Domain\Catalog\ProductRepository;
use Bayti\Api\Domain\Catalog\Vendor;
use Bayti\Api\Domain\User\User;
use Bayti\Api\Http\Controllers\Admin\Product\GetAdminProductHistoryController;
use Bayti\Api\Http\Serializers\AuditLogSerializer;
use Bayti\Api\Infrastructure\Auth\JwtService;
use Bayti\Api\Tests\Http\HttpTestCase;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use Psr\Http\Message\ResponseInterface;

/**
 * HTTP tests for GET /v3/admin/products/{id}/history.
 *
 * Admin-only (products.view). Returns the append-only audit trail for a
 * single product, newest first, with denormalised actor info. Reading the
 * trail must NOT itself write an audit row.
 */
#[CoversClass(GetAdminProductHistoryController::class)]
#[CoversClass(AuditLogSerializer::class)]
final class GetAdminProductHistoryControllerTest extends HttpTestCase
{
    /** @var list<AuditLog> */
    private array $savedAuditLogs = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->savedAuditLogs = [];
    }

    #[Test]
    public function returnsHistoryForAdmin(): void
    {
        $admin = $this->makeAdminUser(99);
        $vendor = $this->makeVendor();
        $product = $this->makeProduct(100, $vendor);

        $logs = [
            $this->makeAuditLog(99, 100, AuditLog::ACTION_UPDATED, [
                'before' => ['price' => '100.00'],
                'after' => ['price' => '120.00'],
            ]),
            $this->makeAuditLog(99, 100, AuditLog::ACTION_CREATED, [
                'after' => ['name' => 'Product 100'],
            ]),
        ];

        $this->bindDeps($admin, $product, $logs);

        $response = $this->makeGet($admin, '/v3/admin/products/100/history');
        self::assertSame(200, $response->getStatusCode());

        $body = $this->jsonBody($response);
        self::assertSame(100, $body['product']['id']);
        self::assertSame('Product 100', $body['product']['name']);
        self::assertSame(2, $body['count']);
        self::assertCount(2, $body['logs']);

        // Newest first (as returned by findForSubject).
        self::assertSame('updated', $body['logs'][0]['action']);
        self::assertSame('Product', $body['logs'][0]['subject_type']);
        self::assertSame(100, $body['logs'][0]['subject_id']);

        // Actor denormalised from the batch user load.
        self::assertSame(99, $body['logs'][0]['actor']['id']);
        self::assertSame($admin->getEmail(), $body['logs'][0]['actor']['email']);

        // The diff payload survives to the client.
        self::assertSame('120.00', $body['logs'][0]['changes']['after']['price']);
    }

    #[Test]
    public function doesNotRecordAViewOfTheProduct(): void
    {
        $admin = $this->makeAdminUser(99);
        $vendor = $this->makeVendor();
        $product = $this->makeProduct(100, $vendor);

        $this->bindDeps($admin, $product, []);

        $response = $this->makeGet($admin, '/v3/admin/products/100/history');
        self::assertSame(200, $response->getStatusCode());

        // Reading history must not pollute the very timeline it returns.
        self::assertCount(0, $this->savedAuditLogs);
    }

    #[Test]
    public function emptyHistoryReturns200WithZeroCount(): void
    {
        $admin = $this->makeAdminUser(99);
        $vendor = $this->makeVendor();
        $product = $this->makeProduct(100, $vendor);

        $this->bindDeps($admin, $product, []);

        $response = $this->makeGet($admin, '/v3/admin/products/100/history');
        self::assertSame(200, $response->getStatusCode());

        $body = $this->jsonBody($response);
        self::assertSame(0, $body['count']);
        self::assertSame([], $body['logs']);
    }

    #[Test]
    public function nonExistentProductReturns404(): void
    {
        $admin = $this->makeAdminUser(99);
        $this->bindDeps($admin, product: null, logs: []);

        $response = $this->makeGet($admin, '/v3/admin/products/999/history');
        self::assertSame(404, $response->getStatusCode());
    }

    #[Test]
    public function requiresAdmin(): void
    {
        $regularUser = $this->makeUser(id: 200);
        $vendor = $this->makeVendor();
        $product = $this->makeProduct(100, $vendor);
        $this->bindDeps($regularUser, $product, []);

        $response = $this->makeGet($regularUser, '/v3/admin/products/100/history');
        self::assertSame(403, $response->getStatusCode());
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

    private function makeProduct(int $id, Vendor $vendor): Product
    {
        $product = new Product($vendor, "product-{$id}", "Product {$id}");
        $idRef = new \ReflectionProperty(Product::class, 'id');
        $idRef->setAccessible(true);
        $idRef->setValue($product, $id);
        $product->setPrice('100.00');
        return $product;
    }

    /**
     * @param array<string, mixed> $changes
     */
    private function makeAuditLog(int $userId, int $subjectId, string $action, array $changes): AuditLog
    {
        return new AuditLog($userId, 'Product', $subjectId, $action, $changes);
    }

    /**
     * @param list<AuditLog> $logs
     */
    private function bindDeps(User $user, ?Product $product, array $logs): void
    {
        $productRepo = $this->createMock(ProductRepository::class);
        $productRepo->method('find')->willReturn($product);

        // AuditLog repo: returns the seeded rows for findForSubject and records
        // any save() so the "no view recorded" assertion can prove it stays empty.
        $auditRepo = new class($this->savedAuditLogs, $logs) extends \Doctrine\ORM\EntityRepository {
            /**
             * @param list<AuditLog> $savedSink
             * @param list<AuditLog> $rows
             */
            public function __construct(private array &$savedSink, private array $rows)
            {
            }

            /** @return list<AuditLog> */
            public function findForSubject(string $subjectType, int $subjectId, int $limit = 50): array
            {
                if ($subjectType !== 'Product') {
                    return [];
                }
                return array_slice(array_filter(
                    $this->rows,
                    static fn (AuditLog $l): bool => $l->getSubjectId() === $subjectId,
                ), 0, $limit);
            }

            public function save(AuditLog $log): void
            {
                $this->savedSink[] = $log;
            }

            public function getClassName(): string
            {
                return AuditLog::class;
            }
        };

        // User repo used by the controller's actor batch load (findBy).
        $userRepo = $this->createMock(\Bayti\Api\Domain\User\UserRepository::class);
        $userRepo->method('findById')->willReturn($user);
        $userRepo->method('findBy')->willReturn([$user]);

        $em = $this->stubEm(function ($em) use ($userRepo, $productRepo, $auditRepo) {
            $em->method('getRepository')->willReturnMap([
                [User::class, $userRepo],
                [Product::class, $productRepo],
                [AuditLog::class, $auditRepo],
            ]);
        });
        $this->bind(EntityManagerInterface::class, $em);
        $this->bind(AuditLogSerializer::class, new AuditLogSerializer());
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
