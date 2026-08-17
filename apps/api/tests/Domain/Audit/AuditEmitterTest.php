<?php

declare(strict_types=1);

namespace Bayti\Api\Tests\Domain\Audit;

use Bayti\Api\Domain\Audit\AuditEmitter;
use Bayti\Api\Domain\Audit\AuditLog;
use Bayti\Api\Domain\Audit\AuditLogRepository;
use Bayti\Api\Domain\User\Address;
use Bayti\Api\Domain\User\User;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Slim\Psr7\Factory\ServerRequestFactory;

#[CoversClass(AuditEmitter::class)]
#[CoversClass(AuditLog::class)]
final class AuditEmitterTest extends TestCase
{
    /** @var AuditLogRepository&\PHPUnit\Framework\MockObject\MockObject */
    private $repo;

    /** @var EntityManagerInterface&\PHPUnit\Framework\MockObject\MockObject */
    private $em;

    private AuditEmitter $emitter;

    /** @var AuditLog[] */
    private array $captured = [];

    protected function setUp(): void
    {
        $this->captured = [];

        $this->repo = $this->createMock(AuditLogRepository::class);
        $this->repo->method('save')
            ->willReturnCallback(function (AuditLog $log): void {
                $this->captured[] = $log;
            });

        $this->em = $this->createMock(EntityManagerInterface::class);
        $this->em->method('getRepository')
            ->with(AuditLog::class)
            ->willReturn($this->repo);

        $this->emitter = new AuditEmitter($this->em);
    }

    #[Test]
    public function recordCreateProducesCreatedAuditRow(): void
    {
        $user = $this->makeUser(42);
        $address = $this->makeAddress($user, 100);

        $request = (new ServerRequestFactory())->createServerRequest('POST', '/v3/me/addresses');

        $this->emitter->recordCreate(
            request: $request,
            actor: $user,
            subject: $address,
            afterSnapshot: ['recipient_name' => 'Alice'],
        );

        self::assertCount(1, $this->captured);
        $log = $this->captured[0];
        self::assertSame(42, $log->getUserId());
        self::assertSame('Address', $log->getSubjectType());
        self::assertSame(100, $log->getSubjectId());
        self::assertSame(AuditLog::ACTION_CREATED, $log->getAction());
        self::assertSame(['after' => ['recipient_name' => 'Alice']], $log->getChanges());
    }

    #[Test]
    public function recordUpdateComputesDiffOfChangedFields(): void
    {
        $user = $this->makeUser(43);
        $address = $this->makeAddress($user, 101);

        $before = [
            'recipient_name' => 'Alice',
            'emirate' => 'Dubai',
            'area' => 'Jumeirah',
        ];
        $after = [
            'recipient_name' => 'Alice Smith',  // changed
            'emirate' => 'Dubai',                // unchanged
            'area' => 'Marina',                  // changed
        ];

        $this->emitter->recordUpdate(
            request: null,
            actor: $user,
            subject: $address,
            beforeSnapshot: $before,
            afterSnapshot: $after,
        );

        self::assertCount(1, $this->captured);
        $changes = $this->captured[0]->getChanges();

        // Only changed fields in either before or after
        self::assertSame(
            ['recipient_name' => 'Alice', 'area' => 'Jumeirah'],
            $changes['before'],
        );
        self::assertSame(
            ['recipient_name' => 'Alice Smith', 'area' => 'Marina'],
            $changes['after'],
        );
        self::assertArrayNotHasKey('emirate', $changes['before']);
        self::assertArrayNotHasKey('emirate', $changes['after']);
    }

    #[Test]
    public function recordUpdateSkipsWhenNothingChanged(): void
    {
        $user = $this->makeUser(44);
        $address = $this->makeAddress($user, 102);

        $snapshot = ['recipient_name' => 'Alice'];
        $this->emitter->recordUpdate(
            request: null,
            actor: $user,
            subject: $address,
            beforeSnapshot: $snapshot,
            afterSnapshot: $snapshot,  // identical
        );

        // No audit row written when there's no actual change.
        self::assertCount(0, $this->captured);
    }

    #[Test]
    public function recordDeleteProducesDeletedAuditWithBeforeState(): void
    {
        $user = $this->makeUser(45);
        $address = $this->makeAddress($user, 103);

        $this->emitter->recordDelete(
            request: null,
            actor: $user,
            subject: $address,
            beforeSnapshot: ['recipient_name' => 'Alice'],
        );

        self::assertCount(1, $this->captured);
        $log = $this->captured[0];
        self::assertSame(AuditLog::ACTION_DELETED, $log->getAction());
        self::assertSame(['before' => ['recipient_name' => 'Alice']], $log->getChanges());
    }

    #[Test]
    public function recordDefaultProducesDefaultAction(): void
    {
        $user = $this->makeUser(46);
        $address = $this->makeAddress($user, 104);

        $this->emitter->recordDefault(
            request: null,
            actor: $user,
            subject: $address,
            changes: [
                'is_default_shipping' => ['before' => false, 'after' => true],
            ],
        );

        self::assertCount(1, $this->captured);
        self::assertSame(AuditLog::ACTION_DEFAULT, $this->captured[0]->getAction());
    }

    #[Test]
    public function nullActorIsAllowed(): void
    {
        $user = $this->makeUser(47);
        $address = $this->makeAddress($user, 105);

        $this->emitter->recordCreate(
            request: null,
            actor: null,  // system-driven event
            subject: $address,
            afterSnapshot: null,
        );

        self::assertCount(1, $this->captured);
        self::assertNull($this->captured[0]->getUserId());
    }

    #[Test]
    public function redactsSensitiveFieldNames(): void
    {
        $user = $this->makeUser(48);
        $address = $this->makeAddress($user, 106);

        $this->emitter->recordCreate(
            request: null,
            actor: $user,
            subject: $address,
            afterSnapshot: [
                'recipient_name' => 'Alice',     // logged
                'verification_token' => 'abc123', // redacted
                'password_hash' => 'hashvalue',   // redacted
                'api_secret' => 'secretvalue',    // redacted
            ],
        );

        $changes = $this->captured[0]->getChanges();
        self::assertSame('Alice', $changes['after']['recipient_name']);
        self::assertSame('[REDACTED]', $changes['after']['verification_token']);
        self::assertSame('[REDACTED]', $changes['after']['password_hash']);
        self::assertSame('[REDACTED]', $changes['after']['api_secret']);
    }

    #[Test]
    public function snapshotsExtractEntityState(): void
    {
        $user = $this->makeUser(49);
        $address = $this->makeAddress($user, 107);

        $snapshot = $this->emitter->snapshot($address);

        self::assertArrayHasKey('recipient_name', $snapshot);
        self::assertArrayHasKey('emirate', $snapshot);
        self::assertArrayHasKey('is_default_shipping', $snapshot);
    }

    #[Test]
    public function snapshotsVendorApplication(): void
    {
        // Regression for PHP-1S: approving/rejecting a seller application
        // snapshots the VendorApplication, which had no strategy and threw
        // "No snapshot strategy for ...VendorApplication" -> 500.
        $application = new \Bayti\Api\Domain\Catalog\VendorApplication(
            firstName: 'Sara',
            lastName: 'Malik',
            email: 'Sara@Example.com',
            phone: '+971500000000',
            businessName: 'Sara Couture',
        );

        $snapshot = $this->emitter->snapshot($application);

        self::assertSame('Sara', $snapshot['first_name']);
        self::assertSame('sara@example.com', $snapshot['email']);
        self::assertSame('Sara Couture', $snapshot['business_name']);
        self::assertArrayHasKey('status', $snapshot);
        self::assertArrayHasKey('reject_reason', $snapshot);
        self::assertNull($snapshot['vendor_id']);
    }

    #[Test]
    public function extractsIpFromServerParams(): void
    {
        $user = $this->makeUser(50);
        $address = $this->makeAddress($user, 108);

        $request = (new ServerRequestFactory())->createServerRequest(
            'POST',
            '/v3/me/addresses',
            ['REMOTE_ADDR' => '203.0.113.42'],
        );

        $this->emitter->recordCreate(
            request: $request,
            actor: $user,
            subject: $address,
            afterSnapshot: null,
        );

        self::assertSame('203.0.113.42', $this->captured[0]->getIpAddress());
    }

    #[Test]
    public function rejectsInvalidIp(): void
    {
        $user = $this->makeUser(51);
        $address = $this->makeAddress($user, 109);

        $request = (new ServerRequestFactory())->createServerRequest(
            'POST',
            '/v3/me/addresses',
            ['REMOTE_ADDR' => 'not-an-ip'],
        );

        $this->emitter->recordCreate(
            request: $request,
            actor: $user,
            subject: $address,
            afterSnapshot: null,
        );

        self::assertNull($this->captured[0]->getIpAddress());
    }

    #[Test]
    public function captureUserAgent(): void
    {
        $user = $this->makeUser(52);
        $address = $this->makeAddress($user, 110);

        $request = (new ServerRequestFactory())->createServerRequest('POST', '/v3/me/addresses')
            ->withHeader('User-Agent', 'Mozilla/5.0 Test');

        $this->emitter->recordCreate(
            request: $request,
            actor: $user,
            subject: $address,
            afterSnapshot: null,
        );

        self::assertSame('Mozilla/5.0 Test', $this->captured[0]->getUserAgent());
    }

    #[Test]
    public function truncatesVeryLongUserAgent(): void
    {
        $user = $this->makeUser(53);
        $address = $this->makeAddress($user, 111);

        $longUa = str_repeat('A', 2000);

        $request = (new ServerRequestFactory())->createServerRequest('POST', '/v3/me/addresses')
            ->withHeader('User-Agent', $longUa);

        $this->emitter->recordCreate(
            request: $request,
            actor: $user,
            subject: $address,
            afterSnapshot: null,
        );

        self::assertSame(1000, strlen($this->captured[0]->getUserAgent()));
    }

    #[Test]
    public function dbWriteFailureDoesNotPropagate(): void
    {
        // If the audit save throws (e.g. DB connection lost), the
        // emitter must NOT propagate the exception — the user's
        // request should still succeed.
        $brokenEm = $this->createMock(EntityManagerInterface::class);
        $brokenRepo = $this->createMock(AuditLogRepository::class);
        $brokenRepo->method('save')->willThrowException(new \RuntimeException('DB down'));
        $brokenEm->method('getRepository')->willReturn($brokenRepo);

        $emitter = new AuditEmitter($brokenEm);

        $user = $this->makeUser(54);
        $address = $this->makeAddress($user, 112);

        // Should not throw despite the underlying save failing.
        $emitter->recordCreate(
            request: null,
            actor: $user,
            subject: $address,
            afterSnapshot: null,
        );

        // If we got here without an exception, the test passes.
        self::assertTrue(true);
    }

    private function makeUser(int $id): User
    {
        $hash = password_hash('p4ssword!', PASSWORD_BCRYPT);
        $user = new User('a@b.com', '+971500000000', $hash, 'AE');
        $ref = new \ReflectionProperty(User::class, 'id');
        $ref->setAccessible(true);
        $ref->setValue($user, $id);
        return $user;
    }

    private function makeAddress(User $user, int $id): Address
    {
        $address = new Address(
            user: $user,
            recipientName: 'Alice',
            recipientPhone: '+971501234567',
            emirate: 'Dubai',
            area: 'Jumeirah',
        );
        $ref = new \ReflectionProperty(Address::class, 'id');
        $ref->setAccessible(true);
        $ref->setValue($address, $id);
        return $address;
    }
}
