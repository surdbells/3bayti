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
 * Coverage for the delivery lead-time range on PUT /v3/admin/vendors/{id}.
 * The order-detail screen quotes the slowest store's range; admins set it
 * here.
 */
#[CoversClass(UpdateVendorController::class)]
final class UpdateVendorDeliveryDaysTest extends HttpTestCase
{
    /** @var array<int, AuditLog> */
    private array $recordedAuditLogs = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->recordedAuditLogs = [];
    }

    #[Test]
    public function setsDeliveryDaysAndSurfacesThemInAdminShape(): void
    {
        $admin = $this->makeAdminUser(99);
        $vendor = $this->makeVendor(42);
        // Defaults before edit.
        self::assertSame(7, $vendor->getMinDeliveryDays());
        self::assertSame(14, $vendor->getMaxDeliveryDays());

        $this->bindEm($admin, $vendor);

        $response = $this->makePut($admin, '/v3/admin/vendors/42', [
            'name' => 'Almas Fashion',
            'contact_email' => 'almas@example.com',
            'min_delivery_days' => 3,
            'max_delivery_days' => 9,
        ]);

        self::assertSame(200, $response->getStatusCode(), (string) $response->getBody());
        self::assertSame(3, $vendor->getMinDeliveryDays());
        self::assertSame(9, $vendor->getMaxDeliveryDays());

        $body = $this->jsonBody($response);
        self::assertSame(3, $body['vendor']['min_delivery_days'] ?? null);
        self::assertSame(9, $body['vendor']['max_delivery_days'] ?? null);
    }

    #[Test]
    public function preservesDeliveryDaysWhenOmitted(): void
    {
        $admin = $this->makeAdminUser(99);
        $vendor = $this->makeVendor(42);
        $vendor->setDeliveryDays(5, 10);

        $this->bindEm($admin, $vendor);

        $response = $this->makePut($admin, '/v3/admin/vendors/42', [
            'name' => 'Almas Fashion',
            'contact_email' => 'almas@example.com',
        ]);

        self::assertSame(200, $response->getStatusCode());
        self::assertSame(5, $vendor->getMinDeliveryDays());
        self::assertSame(10, $vendor->getMaxDeliveryDays());
    }

    #[Test]
    public function invertedRangeIsNormalised(): void
    {
        $admin = $this->makeAdminUser(99);
        $vendor = $this->makeVendor(42);
        $this->bindEm($admin, $vendor);

        // min > max is swapped by the entity so the quote stays well-formed.
        $response = $this->makePut($admin, '/v3/admin/vendors/42', [
            'name' => 'Almas Fashion',
            'contact_email' => 'almas@example.com',
            'min_delivery_days' => 12,
            'max_delivery_days' => 4,
        ]);

        self::assertSame(200, $response->getStatusCode());
        self::assertSame(4, $vendor->getMinDeliveryDays());
        self::assertSame(12, $vendor->getMaxDeliveryDays());
    }

    #[Test]
    public function rejectsOutOfRangeDeliveryDays(): void
    {
        $admin = $this->makeAdminUser(99);
        $vendor = $this->makeVendor(42);
        $this->bindEm($admin, $vendor);

        $response = $this->makePut($admin, '/v3/admin/vendors/42', [
            'name' => 'Almas Fashion',
            'contact_email' => 'almas@example.com',
            'min_delivery_days' => 0,
            'max_delivery_days' => 400,
        ]);

        self::assertSame(422, $response->getStatusCode());
        // Entity unchanged (still defaults).
        self::assertSame(14, $vendor->getMaxDeliveryDays());
    }

    #[Test]
    public function acceptsALocalPhoneAndStoresItAsE164(): void
    {
        $admin = $this->makeAdminUser(99);
        $vendor = $this->makeVendor(42);
        $this->bindEm($admin, $vendor);

        // The reported bug: editing the email while a legacy local-format phone
        // ("0552900789") sat in the form 422'd on contact_phone. It must now be
        // accepted and canonicalised to E.164 instead of blocking the save.
        $response = $this->makePut($admin, '/v3/admin/vendors/42', [
            'name' => 'abayatai',
            'contact_email' => 'abayatai@yahoo.com',
            'contact_phone' => '0552900789',
        ]);

        self::assertSame(200, $response->getStatusCode(), (string) $response->getBody());
        self::assertSame('+971552900789', $vendor->getContactPhone());
    }

    // ===== Helpers =====

    private function makeAdminUser(int $id): User
    {
        $user = $this->makeUser(id: $id);
        $user->setRoles(admin: true);
        return $user;
    }

    private function makeVendor(int $id): Vendor
    {
        $vendor = new Vendor("vendor-{$id}", "Vendor {$id}", 'vendor@example.test');
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
            /** @param array<int, AuditLog> $sink */
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

    /** @param array<string, mixed> $body */
    private function makePut(User $user, string $uri, array $body): ResponseInterface
    {
        $jwt = $this->app->getContainer()->get(JwtService::class);
        $pair = $jwt->issueTokenPair($user);
        return $this->handle($this->jsonRequest('PUT', $uri, $body, [
            'Authorization' => 'Bearer ' . $pair->accessToken,
        ]));
    }
}
