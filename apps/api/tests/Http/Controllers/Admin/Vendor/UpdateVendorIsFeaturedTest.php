<?php

declare(strict_types=1);

namespace Bayti\Api\Tests\Http\Controllers\Admin\Vendor;

use Bayti\Api\Domain\Audit\AuditEmitter;
use Bayti\Api\Domain\Audit\AuditLog;
use Bayti\Api\Domain\Catalog\Vendor;
use Bayti\Api\Domain\Catalog\VendorRepository;
use Bayti\Api\Domain\User\User;
use Bayti\Api\Domain\User\UserRepository;
use Bayti\Api\Http\Controllers\Admin\Vendor\UpdateVendorController;
use Bayti\Api\Infrastructure\Auth\JwtService;
use Bayti\Api\Tests\Http\HttpTestCase;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use Psr\Http\Message\ResponseInterface;
use Psr\Log\NullLogger;

/**
 * Coverage for PUT /v3/admin/vendors/{id} focused on M3.2.X.2-D's
 * is_featured toggle support.
 *
 * Verifies:
 *   - is_featured: true persists and surfaces in adminShape
 *   - is_featured: false resets the flag
 *   - is_featured omitted → existing value preserved
 *   - Audit log captures the before/after diff including is_featured
 *
 * NOT covered here (out of scope; existing PUT behavior is verified
 * elsewhere or implicitly):
 *   - Other field updates (name, contact_email, etc.)
 *   - Slug collision handling
 *   - 404 for unknown vendor
 *   - Validation errors
 */
#[CoversClass(UpdateVendorController::class)]
final class UpdateVendorIsFeaturedTest extends HttpTestCase
{
    /** @var array<int, AuditLog> */
    private array $recordedAuditLogs = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->recordedAuditLogs = [];
    }

    #[Test]
    public function setsIsFeaturedTrue(): void
    {
        $admin = $this->makeAdminUser(99);
        $vendor = $this->makeVendor(42, 'almas-fashion', 'Almas Fashion');
        // Pre-state: not featured
        self::assertFalse($vendor->isFeatured());

        $this->bindEm($admin, $vendor);

        $response = $this->makePut($admin, '/v3/admin/vendors/42', [
            'name' => 'Almas Fashion',
            'contact_email' => 'almas@example.com',
            'is_featured' => true,
        ]);

        self::assertSame(200, $response->getStatusCode());
        self::assertTrue(
            $vendor->isFeatured(),
            'is_featured must flip to true after the PUT'
        );

        // adminShape echo
        $body = $this->jsonBody($response);
        self::assertArrayHasKey('vendor', $body);
        self::assertTrue($body['vendor']['is_featured']);
    }

    #[Test]
    public function setsIsFeaturedFalse(): void
    {
        $admin = $this->makeAdminUser(99);
        $vendor = $this->makeVendor(42, 'almas-fashion', 'Almas Fashion');
        $vendor->setFeatured(true); // start featured

        $this->bindEm($admin, $vendor);

        $response = $this->makePut($admin, '/v3/admin/vendors/42', [
            'name' => 'Almas Fashion',
            'contact_email' => 'almas@example.com',
            'is_featured' => false,
        ]);

        self::assertSame(200, $response->getStatusCode());
        self::assertFalse(
            $vendor->isFeatured(),
            'is_featured must reset to false after the PUT'
        );
    }

    #[Test]
    public function omittedIsFeaturedPreservesExistingValue(): void
    {
        $admin = $this->makeAdminUser(99);
        $vendor = $this->makeVendor(42, 'almas-fashion', 'Almas Fashion');
        $vendor->setFeatured(true); // pre-existing curation

        $this->bindEm($admin, $vendor);

        // PUT without is_featured key — admin only wants to update name
        $response = $this->makePut($admin, '/v3/admin/vendors/42', [
            'name' => 'Almas Fashion Renamed',
            'contact_email' => 'almas@example.com',
            // is_featured intentionally omitted
        ]);

        self::assertSame(200, $response->getStatusCode());
        self::assertTrue(
            $vendor->isFeatured(),
            'Omitted is_featured must NOT reset the curation flag — '
            . 'partial PUTs respect existing state for null-omitted fields'
        );
    }

    #[Test]
    public function isFeaturedAppearsInAuditDiff(): void
    {
        $admin = $this->makeAdminUser(99);
        $vendor = $this->makeVendor(42, 'almas-fashion', 'Almas Fashion');
        self::assertFalse($vendor->isFeatured());

        $this->bindEm($admin, $vendor);

        $this->makePut($admin, '/v3/admin/vendors/42', [
            'name' => 'Almas Fashion',
            'contact_email' => 'almas@example.com',
            'is_featured' => true,
        ]);

        // Audit log captured
        self::assertGreaterThan(
            0,
            count($this->recordedAuditLogs),
            'PUT must emit an audit log'
        );

        $audit = end($this->recordedAuditLogs);
        self::assertSame(AuditLog::ACTION_UPDATED, $audit->getAction());

        $changes = $audit->getChanges();
        // Audit changes shape: {before: {...}, after: {...}} for updates.
        // is_featured must surface in both snapshots so the diff is
        // traceable: who featured this vendor, when, from what state.
        self::assertArrayHasKey('before', $changes);
        self::assertArrayHasKey('after', $changes);
        self::assertArrayHasKey('is_featured', $changes['before']);
        self::assertArrayHasKey('is_featured', $changes['after']);
        self::assertFalse($changes['before']['is_featured']);
        self::assertTrue($changes['after']['is_featured']);
    }

    // ===== Helpers =====

    private function makeAdminUser(int $id): User
    {
        $user = $this->makeUser(id: $id);
        $user->setRoles(admin: true);
        return $user;
    }

    private function makeVendor(int $id, string $slug, string $name): Vendor
    {
        $vendor = new Vendor($slug, $name, 'vendor@example.test');

        $ref = new \ReflectionProperty(Vendor::class, 'id');
        $ref->setAccessible(true);
        $ref->setValue($vendor, $id);

        return $vendor;
    }

    private function bindEm(User $user, Vendor $vendor): void
    {
        $userRepo = $this->createMock(UserRepository::class);
        $userRepo->method('findById')->willReturn($user);

        $vendorRepo = $this->createMock(VendorRepository::class);
        $vendorRepo->method('find')->with($vendor->getId())->willReturn($vendor);
        $vendorRepo->method('slugExists')->willReturn(false);

        // Capturing audit repo
        $auditRepo = new class($this->recordedAuditLogs) extends \Doctrine\ORM\EntityRepository {
            public function __construct(private array &$sink) {}
            public function save(AuditLog $log): void { $this->sink[] = $log; }
            public function getClassName(): string { return AuditLog::class; }
        };

        $em = $this->stubEm(function ($em) use ($userRepo, $vendorRepo, $auditRepo) {
            $em->method('getRepository')->willReturnMap([
                [User::class, $userRepo],
                [Vendor::class, $vendorRepo],
                [AuditLog::class, $auditRepo],
            ]);
        });
        $this->bind(EntityManagerInterface::class, $em);

        $emitter = new AuditEmitter($em, new NullLogger());
        $this->bind(AuditEmitter::class, $emitter);
    }

    /**
     * @param array<string, mixed> $body
     */
    private function makePut(User $user, string $uri, array $body): ResponseInterface
    {
        $jwt = $this->app->getContainer()->get(JwtService::class);
        $pair = $jwt->issueTokenPair($user);
        return $this->handle($this->jsonRequest('PUT', $uri, $body, [
            'Authorization' => 'Bearer ' . $pair->accessToken,
        ]));
    }
}
