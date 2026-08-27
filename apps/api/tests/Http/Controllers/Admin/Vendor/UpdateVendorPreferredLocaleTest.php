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
 * Coverage for PUT /v3/admin/vendors/{id} focused on M3.2.X.7-D's
 * preferred_locale field support.
 *
 * Verifies:
 *   - preferred_locale: 'ar' persists and surfaces in adminShape
 *   - preferred_locale: 'en' overwrites existing value
 *   - preferred_locale omitted → existing value preserved
 *   - Invalid value (e.g. 'fr') returns 422 (DTO validation)
 *   - Audit log captures the before/after diff including
 *     preferred_locale
 *
 * Customer endpoint coverage is implicit: PATCH /v3/me/profile
 * already accepts a `locale` field (M1.7.0; pre-existing). The
 * M3.2.X.7-D Q-Unification design re-uses this rather than adding
 * a parallel preferred_locale field on User. The resolver
 * normalizes whatever User.locale contains to a short tag.
 */
#[CoversClass(UpdateVendorController::class)]
final class UpdateVendorPreferredLocaleTest extends HttpTestCase
{
    /** @var array<int, AuditLog> */
    private array $recordedAuditLogs = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->recordedAuditLogs = [];
    }

    #[Test]
    public function setsPreferredLocaleToArabic(): void
    {
        $admin = $this->makeAdminUser(99);
        $vendor = $this->makeVendor(42, 'almas-fashion', 'Almas Fashion');
        // Pre-state: no preference
        self::assertNull($vendor->getPreferredLocale());

        $this->bindEm($admin, $vendor);

        $response = $this->makePut($admin, '/v3/admin/vendors/42', [
            'name' => 'Almas Fashion',
            'contact_email' => 'almas@example.com',
            'preferred_locale' => 'ar',
        ]);

        self::assertSame(200, $response->getStatusCode());
        self::assertSame(
            'ar',
            $vendor->getPreferredLocale(),
            'preferred_locale must persist to entity after PUT',
        );

        $body = $this->jsonBody($response);
        self::assertSame(
            'ar',
            $body['vendor']['preferred_locale'] ?? null,
            'adminShape must surface preferred_locale for tooling visibility',
        );
    }

    #[Test]
    public function setsPreferredLocaleToEnglish(): void
    {
        $admin = $this->makeAdminUser(99);
        $vendor = $this->makeVendor(42, 'almas-fashion', 'Almas Fashion');
        $vendor->setPreferredLocale('ar'); // pre-state: Arabic

        $this->bindEm($admin, $vendor);

        $response = $this->makePut($admin, '/v3/admin/vendors/42', [
            'name' => 'Almas Fashion',
            'contact_email' => 'almas@example.com',
            'preferred_locale' => 'en',
        ]);

        self::assertSame(200, $response->getStatusCode());
        self::assertSame('en', $vendor->getPreferredLocale());
    }

    #[Test]
    public function preservesPreferredLocaleWhenFieldOmitted(): void
    {
        $admin = $this->makeAdminUser(99);
        $vendor = $this->makeVendor(42, 'almas-fashion', 'Almas Fashion');
        $vendor->setPreferredLocale('ar'); // pre-state

        $this->bindEm($admin, $vendor);

        // PUT WITHOUT preferred_locale field
        $response = $this->makePut($admin, '/v3/admin/vendors/42', [
            'name' => 'Almas Fashion',
            'contact_email' => 'almas@example.com',
            // preferred_locale intentionally omitted
        ]);

        self::assertSame(200, $response->getStatusCode());
        self::assertSame(
            'ar',
            $vendor->getPreferredLocale(),
            'Omitting preferred_locale must preserve existing value',
        );
    }

    #[Test]
    public function rejectsInvalidPreferredLocale(): void
    {
        $admin = $this->makeAdminUser(99);
        $vendor = $this->makeVendor(42, 'almas-fashion', 'Almas Fashion');

        $this->bindEm($admin, $vendor);

        $response = $this->makePut($admin, '/v3/admin/vendors/42', [
            'name' => 'Almas Fashion',
            'contact_email' => 'almas@example.com',
            'preferred_locale' => 'fr',  // unsupported
        ]);

        self::assertSame(422, $response->getStatusCode());
        // Entity unchanged
        self::assertNull($vendor->getPreferredLocale());
    }

    #[Test]
    public function rejectsRegionTaggedPreferredLocale(): void
    {
        // DTO accepts only 'en' / 'ar', no region variants. UAE-
        // region-tagged values like 'ar-AE' should be rejected at the
        // endpoint layer. (User.locale field allows them; Vendor's
        // narrower constraint doesn't.)
        $admin = $this->makeAdminUser(99);
        $vendor = $this->makeVendor(42, 'almas-fashion', 'Almas Fashion');

        $this->bindEm($admin, $vendor);

        $response = $this->makePut($admin, '/v3/admin/vendors/42', [
            'name' => 'Almas Fashion',
            'contact_email' => 'almas@example.com',
            'preferred_locale' => 'ar-AE',
        ]);

        self::assertSame(422, $response->getStatusCode());
        self::assertNull($vendor->getPreferredLocale());
    }

    #[Test]
    public function auditCapturesPreferredLocaleChange(): void
    {
        $admin = $this->makeAdminUser(99);
        $vendor = $this->makeVendor(42, 'almas-fashion', 'Almas Fashion');
        // Pre-state: null
        self::assertNull($vendor->getPreferredLocale());

        $this->bindEm($admin, $vendor);

        $this->makePut($admin, '/v3/admin/vendors/42', [
            'name' => 'Almas Fashion',
            'contact_email' => 'almas@example.com',
            'preferred_locale' => 'ar',
        ]);

        self::assertGreaterThan(0, count($this->recordedAuditLogs));
        $audit = end($this->recordedAuditLogs);
        self::assertSame(AuditLog::ACTION_UPDATED, $audit->getAction());

        $changes = $audit->getChanges();
        self::assertArrayHasKey(
            'preferred_locale',
            $changes['before'] ?? [],
            'before snapshot must include preferred_locale key',
        );
        self::assertNull(
            $changes['before']['preferred_locale'],
            'before snapshot captures the null pre-state',
        );
        self::assertSame(
            'ar',
            $changes['after']['preferred_locale'] ?? null,
            'after snapshot captures the new value',
        );
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
