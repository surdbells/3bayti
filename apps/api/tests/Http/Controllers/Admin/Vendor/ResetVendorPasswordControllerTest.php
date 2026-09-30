<?php

declare(strict_types=1);

namespace Bayti\Api\Tests\Http\Controllers\Admin\Vendor;

use Bayti\Api\Domain\Audit\AuditEmitter;
use Bayti\Api\Domain\Audit\AuditLog;
use Bayti\Api\Domain\Authz\Permission;
use Bayti\Api\Domain\Authz\Role;
use Bayti\Api\Domain\Catalog\Vendor;
use Bayti\Api\Domain\Catalog\VendorRepository;
use Bayti\Api\Domain\User\RefreshToken;
use Bayti\Api\Domain\User\RefreshTokenRepository;
use Bayti\Api\Domain\User\TemporaryPassword;
use Bayti\Api\Domain\User\User;
use Bayti\Api\Domain\User\UserRepository;
use Bayti\Api\Http\Controllers\Admin\Vendor\Dto\ResetVendorPasswordInput;
use Bayti\Api\Http\Controllers\Admin\Vendor\ResetVendorPasswordController;
use Bayti\Api\Infrastructure\Auth\JwtService;
use Bayti\Api\Notification\InMemoryMailer;
use Bayti\Api\Notification\MailerInterface;
use Bayti\Api\Notification\VendorPasswordResetMailer;
use Bayti\Api\Tests\Http\HttpTestCase;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use Psr\Http\Message\ResponseInterface;
use Psr\Log\NullLogger;

/**
 * POST /v3/admin/vendors/{id}/reset-password
 *
 * Verifies:
 *   - generate mode: new temp password hashed, emailed, never returned
 *   - manual mode: admin password hashed, notice email carries no password
 *   - both: must_change_password set, all refresh tokens revoked, audit row
 *   - email failure is non-blocking (reset committed, email_sent=false)
 *   - validation (mode, password-per-mode, length)
 *   - guards: 404, no owner, own account, staff owner, inactive owner
 *   - RBAC: 403 without vendors.reset_password, 200 with it via a role
 */
#[CoversClass(ResetVendorPasswordController::class)]
#[CoversClass(ResetVendorPasswordInput::class)]
#[CoversClass(VendorPasswordResetMailer::class)]
#[CoversClass(TemporaryPassword::class)]
final class ResetVendorPasswordControllerTest extends HttpTestCase
{
    /** @var array<int, AuditLog> */
    private array $recordedAuditLogs = [];
    private int $revokeAllCalls = 0;
    private ?string $lastRevokeReason = null;
    private ?User $lastRevokedUser = null;
    private InMemoryMailer $mailer;

    protected function setUp(): void
    {
        parent::setUp();
        $this->recordedAuditLogs = [];
        $this->revokeAllCalls = 0;
        $this->lastRevokeReason = null;
        $this->lastRevokedUser = null;
        $this->mailer = new InMemoryMailer();
    }

    // -----------------------------------------------------------------
    // Happy paths
    // -----------------------------------------------------------------

    #[Test]
    public function generateModeIssuesEmailedTempPasswordAndRevokesSessions(): void
    {
        $admin = $this->makeAdminUser(99);
        $owner = $this->makeOwner(55, 'seller@bayti.example', 'oldPass!');
        $oldHash = $owner->getPasswordHash();
        $vendor = $this->makeVendor(42, $owner);
        $this->bindEm($admin, $vendor, $owner);

        $response = $this->post($admin, 42, ['mode' => 'generate', 'reason' => 'Locked out']);

        self::assertSame(200, $response->getStatusCode());
        $body = $this->jsonBody($response);
        self::assertSame(42, $body['vendor_id']);
        self::assertSame(55, $body['owner_user_id']);
        self::assertSame('generate', $body['mode']);
        self::assertSame('seller@bayti.example', $body['email']);
        self::assertTrue($body['email_sent']);
        self::assertTrue($body['must_change_password']);
        self::assertSame(3, $body['sessions_revoked']);
        self::assertArrayNotHasKey('password', $body, 'generated password is never returned');

        self::assertNotSame($oldHash, $owner->getPasswordHash());
        self::assertTrue($owner->mustChangePassword());
        self::assertSame(1, $this->revokeAllCalls);
        self::assertSame('admin_password_reset', $this->lastRevokeReason);
        self::assertSame($owner, $this->lastRevokedUser);

        // The emailed temporary password is the one that now verifies.
        $sent = $this->mailer->sent();
        self::assertCount(1, $sent);
        self::assertSame('seller@bayti.example', $sent[0]['to']);
        self::assertSame(VendorPasswordResetMailer::TEMPLATE, $sent[0]['context']['template']);
        self::assertSame('generate', $sent[0]['context']['mode']);
        self::assertSame(1, preg_match('/Password: (\S+)/', $sent[0]['text_body'], $m));
        self::assertSame(12, strlen($m[1]));
        self::assertTrue(password_verify($m[1], (string) $owner->getPasswordHash()));

        // Audit: override on the owner, with reason, never the secret.
        self::assertCount(1, $this->recordedAuditLogs);
        $audit = $this->recordedAuditLogs[0];
        self::assertSame(AuditLog::ACTION_OVERRIDDEN, $audit->getAction());
        $changes = $audit->getChanges();
        self::assertSame('Locked out', $changes['reason']);
        self::assertSame('generate', $changes['mode']);
        self::assertSame(42, $changes['vendor_id']);
        self::assertTrue($changes['email_sent']);
        $encoded = (string) json_encode($changes);
        self::assertStringNotContainsString($m[1], $encoded);
        self::assertStringNotContainsString((string) $owner->getPasswordHash(), $encoded);
    }

    #[Test]
    public function manualModeSetsAdminPasswordAndSendsNoticeWithoutIt(): void
    {
        $admin = $this->makeAdminUser(99);
        $owner = $this->makeOwner(55, 'seller@bayti.example', 'oldPass!');
        $vendor = $this->makeVendor(42, $owner);
        $this->bindEm($admin, $vendor, $owner);

        $response = $this->post($admin, 42, ['mode' => 'manual', 'password' => ' Admin Chosen 9 ']);

        self::assertSame(200, $response->getStatusCode());
        self::assertSame('manual', $this->jsonBody($response)['mode']);
        self::assertTrue(
            password_verify(' Admin Chosen 9 ', (string) $owner->getPasswordHash()),
            'password is stored untrimmed',
        );
        self::assertTrue($owner->mustChangePassword());
        self::assertSame(1, $this->revokeAllCalls);

        $sent = $this->mailer->sent();
        self::assertCount(1, $sent);
        self::assertSame('manual', $sent[0]['context']['mode']);
        self::assertStringNotContainsString('Admin Chosen 9', $sent[0]['text_body']);
        self::assertStringNotContainsString('Admin Chosen 9', $sent[0]['html_body']);
        self::assertNull($this->recordedAuditLogs[0]->getChanges()['reason']);
    }

    #[Test]
    public function emailFailureDoesNotBlockReset(): void
    {
        $admin = $this->makeAdminUser(99);
        $owner = $this->makeOwner(55, 'seller@bayti.example', 'oldPass!');
        $oldHash = $owner->getPasswordHash();
        $vendor = $this->makeVendor(42, $owner);
        $this->bindEm($admin, $vendor, $owner, new class implements MailerInterface {
            public function send(string $to, string $subject, string $textBody, string $htmlBody, array $context = []): void
            {
                throw new \RuntimeException('SMTP down');
            }
        });

        $response = $this->post($admin, 42, ['mode' => 'generate']);

        self::assertSame(200, $response->getStatusCode());
        self::assertFalse($this->jsonBody($response)['email_sent']);
        self::assertNotSame($oldHash, $owner->getPasswordHash());
        self::assertSame(1, $this->revokeAllCalls);
        self::assertFalse($this->recordedAuditLogs[0]->getChanges()['email_sent']);
    }

    #[Test]
    public function staffWithResetPermissionRoleIsAllowed(): void
    {
        $staff = $this->makeUser(id: 70, email: 'ops@bayti.example');
        $staff->addRole($this->role(1, 'ops', ['vendors.reset_password']));
        $owner = $this->makeOwner(55, 'seller@bayti.example', 'oldPass!');
        $this->bindEm($staff, $this->makeVendor(42, $owner), $owner);

        $response = $this->post($staff, 42, ['mode' => 'generate']);

        self::assertSame(200, $response->getStatusCode());
    }

    // -----------------------------------------------------------------
    // Validation
    // -----------------------------------------------------------------

    #[Test]
    public function missingOrUnknownModeReturns422(): void
    {
        $admin = $this->makeAdminUser(99);
        $owner = $this->makeOwner(55, 'seller@bayti.example', 'oldPass!');
        $this->bindEm($admin, $this->makeVendor(42, $owner), $owner);

        self::assertSame(422, $this->post($admin, 42, [])->getStatusCode());
        self::assertSame(422, $this->post($admin, 42, ['mode' => 'email'])->getStatusCode());
        self::assertSame(0, $this->revokeAllCalls);
    }

    #[Test]
    public function manualModeRequiresValidPassword(): void
    {
        $admin = $this->makeAdminUser(99);
        $owner = $this->makeOwner(55, 'seller@bayti.example', 'oldPass!');
        $oldHash = $owner->getPasswordHash();
        $this->bindEm($admin, $this->makeVendor(42, $owner), $owner);

        self::assertSame(422, $this->post($admin, 42, ['mode' => 'manual'])->getStatusCode());
        self::assertSame(422, $this->post($admin, 42, ['mode' => 'manual', 'password' => 'short'])->getStatusCode());
        self::assertSame(
            422,
            $this->post($admin, 42, ['mode' => 'manual', 'password' => str_repeat('a', 201)])->getStatusCode(),
        );
        self::assertSame($oldHash, $owner->getPasswordHash());
        self::assertSame(0, $this->revokeAllCalls);
        self::assertSame([], $this->mailer->sent());
    }

    #[Test]
    public function generateModeRejectsSuppliedPassword(): void
    {
        $admin = $this->makeAdminUser(99);
        $owner = $this->makeOwner(55, 'seller@bayti.example', 'oldPass!');
        $this->bindEm($admin, $this->makeVendor(42, $owner), $owner);

        $response = $this->post($admin, 42, ['mode' => 'generate', 'password' => 'brandN3wPass!']);

        self::assertSame(422, $response->getStatusCode());
        self::assertSame(0, $this->revokeAllCalls);
    }

    // -----------------------------------------------------------------
    // Guards
    // -----------------------------------------------------------------

    #[Test]
    public function unknownVendorReturns404(): void
    {
        $admin = $this->makeAdminUser(99);
        $this->bindEm($admin, null, null);

        self::assertSame(404, $this->post($admin, 777, ['mode' => 'generate'])->getStatusCode());
        self::assertSame(0, $this->revokeAllCalls);
    }

    #[Test]
    public function vendorWithoutOwnerReturns422(): void
    {
        $admin = $this->makeAdminUser(99);
        $this->bindEm($admin, $this->makeVendor(42, null), null);

        self::assertSame(422, $this->post($admin, 42, ['mode' => 'generate'])->getStatusCode());
        self::assertSame(0, $this->revokeAllCalls);
    }

    #[Test]
    public function cannotResetOwnAccount(): void
    {
        $admin = $this->makeAdminUser(99);
        $this->bindEm($admin, $this->makeVendor(42, $admin), $admin);

        self::assertSame(422, $this->post($admin, 42, ['mode' => 'generate'])->getStatusCode());
        self::assertSame(0, $this->revokeAllCalls);
    }

    #[Test]
    public function staffOwnerIsForbidden(): void
    {
        $admin = $this->makeAdminUser(99);
        $owner = $this->makeOwner(55, 'finance@bayti.example', 'oldPass!');
        $owner->setRoles(finance: true);
        $oldHash = $owner->getPasswordHash();
        $this->bindEm($admin, $this->makeVendor(42, $owner), $owner);

        self::assertSame(403, $this->post($admin, 42, ['mode' => 'generate'])->getStatusCode());
        self::assertSame($oldHash, $owner->getPasswordHash());
        self::assertSame(0, $this->revokeAllCalls);
    }

    #[Test]
    public function inactiveOwnerReturns422(): void
    {
        $admin = $this->makeAdminUser(99);
        $owner = $this->makeOwner(55, 'seller@bayti.example', 'oldPass!');
        $owner->deactivate();
        $this->bindEm($admin, $this->makeVendor(42, $owner), $owner);

        self::assertSame(422, $this->post($admin, 42, ['mode' => 'generate'])->getStatusCode());
        self::assertSame(0, $this->revokeAllCalls);
    }

    #[Test]
    public function softDeletedOwnerReturns422AndResetsNothing(): void
    {
        // A soft-deleted owner is invisible to login (findByEmail filters
        // deleted_at IS NULL), so resetting its password would just email a
        // credential that 401s. Refuse instead of handing out a dead password.
        $admin = $this->makeAdminUser(99);
        $owner = $this->makeOwner(55, 'seller@bayti.example', 'oldPass!');
        $oldHash = $owner->getPasswordHash();
        $ref = new \ReflectionProperty(User::class, 'deletedAt');
        $ref->setAccessible(true);
        $ref->setValue($owner, new \DateTimeImmutable());
        $this->bindEm($admin, $this->makeVendor(42, $owner), $owner);

        self::assertSame(422, $this->post($admin, 42, ['mode' => 'generate'])->getStatusCode());
        self::assertSame($oldHash, $owner->getPasswordHash());
        self::assertSame(0, $this->revokeAllCalls);
        self::assertSame([], $this->mailer->sent());
    }

    // -----------------------------------------------------------------
    // RBAC
    // -----------------------------------------------------------------

    #[Test]
    public function customerIsForbidden(): void
    {
        $customer = $this->makeUser(id: 7, email: 'cust@bayti.example');
        $owner = $this->makeOwner(55, 'seller@bayti.example', 'oldPass!');
        $this->bindEm($customer, $this->makeVendor(42, $owner), $owner);

        self::assertSame(403, $this->post($customer, 42, ['mode' => 'generate'])->getStatusCode());
        self::assertSame(0, $this->revokeAllCalls);
    }

    #[Test]
    public function staffWithoutResetPermissionIsForbidden(): void
    {
        $staff = $this->makeUser(id: 70, email: 'ops@bayti.example');
        $staff->addRole($this->role(1, 'ops', ['vendors.view', 'vendors.edit', 'vendors.impersonate']));
        $owner = $this->makeOwner(55, 'seller@bayti.example', 'oldPass!');
        $this->bindEm($staff, $this->makeVendor(42, $owner), $owner);

        self::assertSame(403, $this->post($staff, 42, ['mode' => 'generate'])->getStatusCode());
        self::assertSame(0, $this->revokeAllCalls);
    }

    // -----------------------------------------------------------------
    // TemporaryPassword
    // -----------------------------------------------------------------

    #[Test]
    public function temporaryPasswordUsesUnambiguousAlphabet(): void
    {
        for ($i = 0; $i < 50; $i++) {
            $p = TemporaryPassword::generate();
            self::assertSame(12, strlen($p));
            self::assertSame(0, preg_match('/[0O1lI]/', $p), "ambiguous character in {$p}");
        }
        $this->expectException(\InvalidArgumentException::class);
        TemporaryPassword::generate(6);
    }

    // ===== Helpers =====

    private function makeAdminUser(int $id): User
    {
        $user = $this->makeUser(id: $id, email: "admin{$id}@bayti.example");
        $user->setRoles(admin: true);
        return $user;
    }

    private function makeOwner(int $id, string $email, string $password): User
    {
        $user = $this->makeUser(id: $id, email: $email, passwordPlain: $password);
        $user->setRoles(vendor: true);
        $user->setName('Mariam', 'Haddad');
        return $user;
    }

    private function makeVendor(int $id, ?User $owner): Vendor
    {
        $vendor = new Vendor('almas-fashion', 'Almas Fashion', 'vendor@example.test');
        $ref = new \ReflectionProperty(Vendor::class, 'id');
        $ref->setAccessible(true);
        $ref->setValue($vendor, $id);
        $vendor->setOwnerUser($owner);
        return $vendor;
    }

    /** @param list<string> $keys */
    private function role(int $id, string $slug, array $keys): Role
    {
        $role = new Role($slug, ucfirst($slug));
        foreach ($keys as $k) {
            $role->addPermission(new Permission($k, explode('.', $k)[0], $k));
        }
        $ref = new \ReflectionProperty(Role::class, 'id');
        $ref->setAccessible(true);
        $ref->setValue($role, $id);
        return $role;
    }

    private function bindEm(User $caller, ?Vendor $vendor, ?User $owner, ?MailerInterface $mailer = null): void
    {
        $userRepo = $this->createMock(UserRepository::class);
        $userRepo->method('findById')->willReturnCallback(
            fn (int $id): ?User => match (true) {
                $id === $caller->getId() => $caller,
                $owner !== null && $id === $owner->getId() => $owner,
                default => null,
            },
        );

        $vendorRepo = $this->createMock(VendorRepository::class);
        $vendorRepo->method('find')->willReturnCallback(
            fn (int $id): ?Vendor => $vendor !== null && $vendor->getId() === $id ? $vendor : null,
        );

        $refreshRepo = $this->createMock(RefreshTokenRepository::class);
        $refreshRepo->method('revokeAllForUser')->willReturnCallback(
            function (User $u, string $reason): int {
                $this->revokeAllCalls++;
                $this->lastRevokeReason = $reason;
                $this->lastRevokedUser = $u;
                return 3;
            },
        );

        $auditRepo = new class($this->recordedAuditLogs) extends \Doctrine\ORM\EntityRepository {
            public function __construct(private array &$sink) {}
            public function save(AuditLog $log): void { $this->sink[] = $log; }
            public function getClassName(): string { return AuditLog::class; }
        };

        $em = $this->stubEm(function ($em) use ($userRepo, $vendorRepo, $refreshRepo, $auditRepo): void {
            $em->method('getRepository')->willReturnMap([
                [User::class, $userRepo],
                [Vendor::class, $vendorRepo],
                [RefreshToken::class, $refreshRepo],
                [AuditLog::class, $auditRepo],
            ]);
        });
        $this->bind(EntityManagerInterface::class, $em);
        $this->bind(AuditEmitter::class, new AuditEmitter($em, new NullLogger()));
        $this->bind(
            VendorPasswordResetMailer::class,
            new VendorPasswordResetMailer($mailer ?? $this->mailer, new NullLogger()),
        );
    }

    /** @param array<string, mixed> $body */
    private function post(User $user, int $vendorId, array $body): ResponseInterface
    {
        $jwt = $this->app->getContainer()->get(JwtService::class);
        $pair = $jwt->issueTokenPair($user);
        return $this->handle($this->jsonRequest('POST', "/v3/admin/vendors/{$vendorId}/reset-password", $body, [
            'Authorization' => 'Bearer ' . $pair->accessToken,
        ]));
    }
}
