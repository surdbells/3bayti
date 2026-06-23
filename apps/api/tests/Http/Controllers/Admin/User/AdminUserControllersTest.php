<?php

declare(strict_types=1);

namespace Bayti\Api\Tests\Http\Controllers\Admin\User;

use Bayti\Api\Domain\Authz\Permission;
use Bayti\Api\Domain\Authz\Role;
use Bayti\Api\Domain\User\RefreshToken;
use Bayti\Api\Domain\User\RefreshTokenRepository;
use Bayti\Api\Domain\User\User;
use Bayti\Api\Domain\User\UserRepository;
use Doctrine\ORM\EntityRepository;
use Bayti\Api\Http\Controllers\Admin\User\AdminResetPasswordController;
use Bayti\Api\Http\Controllers\Admin\User\CreateUserController;
use Bayti\Api\Http\Controllers\Admin\User\Dto\AdminResetPasswordInput;
use Bayti\Api\Http\Controllers\Admin\User\Dto\CreateUserInput;
use Bayti\Api\Infrastructure\Auth\JwtService;
use Bayti\Api\Tests\Http\HttpTestCase;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use Psr\Http\Message\ResponseInterface;

/**
 * Coverage for admin-initiated user management (M5.1):
 *
 *   POST  /v3/admin/users
 *   PATCH /v3/admin/users/{id}/password
 *
 * Verifies:
 *   - Create persists an active, email-verified staff user with the
 *     requested roles and NEVER customer/vendor.
 *   - Create returns 409 on a taken email.
 *   - Create returns 422 on validation errors (missing name, short pw).
 *   - Create requires admin tier — 403 for a non-admin, 401 unauthenticated.
 *   - Password reset re-hashes and revokes ALL the target's refresh tokens.
 *   - Password reset returns 404 for an unknown target.
 *   - Password reset requires admin tier.
 */
#[CoversClass(CreateUserController::class)]
#[CoversClass(AdminResetPasswordController::class)]
#[CoversClass(CreateUserInput::class)]
#[CoversClass(AdminResetPasswordInput::class)]
final class AdminUserControllersTest extends HttpTestCase
{
    /** @var list<User> Captured persisted users. */
    private array $persistedUsers = [];
    private int $revokeAllCalls = 0;
    private ?string $lastRevokeReason = null;

    protected function setUp(): void
    {
        parent::setUp();
        $this->persistedUsers = [];
        $this->revokeAllCalls = 0;
        $this->lastRevokeReason = null;
    }

    private function makeAdmin(int $id = 99): User
    {
        $admin = $this->makeUser(id: $id, email: 'admin@bayti.example');
        $admin->setRoles(admin: true);
        return $admin;
    }

    // ----------------------------------------------------------------
    // CreateUserController
    // ----------------------------------------------------------------

    #[Test]
    public function createPersistsActiveStaffUserWithRoles(): void
    {
        $admin = $this->makeAdmin();
        $this->bindEmForCreate($admin, emailTaken: false);

        $response = $this->makePost($admin, '/v3/admin/users', [
            'first_name' => 'Farah',
            'last_name' => 'Khan',
            'email' => 'farah@bayti.example',
            'password' => 'str0ngPass!',
            'is_finance' => true,
        ]);

        self::assertSame(201, $response->getStatusCode());
        self::assertCount(1, $this->persistedUsers);

        $created = $this->persistedUsers[0];
        self::assertSame('farah@bayti.example', $created->getEmail());
        self::assertTrue($created->isFinance(), 'finance role assigned');
        self::assertFalse($created->isCustomer(), 'never customer via this path');
        self::assertFalse($created->isVendor(), 'never vendor via this path');
        self::assertTrue($created->isEmailVerified(), 'staff created email-verified');
        self::assertTrue(
            password_verify('str0ngPass!', $created->getPasswordHash()),
            'password hashed correctly',
        );
    }

    #[Test]
    public function createAssignsInitialRolesSoStaffIsBornVisible(): void
    {
        // #2: a new back-office account created with an explicit role_ids list
        // is "born" holding a role, so it appears on the Staff screen and is
        // immediately manageable (no separate assignment round-trip needed).
        $admin = $this->makeAdmin();
        $opsRole = $this->role(5, 'operations', ['orders.view']);
        $this->bindEmForCreate($admin, emailTaken: false, roles: [$opsRole]);

        $response = $this->makePost($admin, '/v3/admin/users', [
            'first_name' => 'Nour',
            'last_name' => 'Saleh',
            'email' => 'nour@bayti.example',
            'password' => 'str0ngPass!',
            'role_ids' => [5],
        ]);

        self::assertSame(201, $response->getStatusCode());
        self::assertCount(1, $this->persistedUsers);

        $created = $this->persistedUsers[0];
        $assignedSlugs = array_map(
            static fn (Role $r): string => $r->getSlug(),
            $created->getAssignedRoles()->toArray(),
        );
        self::assertSame(['operations'], $assignedSlugs, 'born with the operations role');
        self::assertTrue($created->isStaff(), 'role-holder is staff');
        self::assertContains('orders.view', $created->effectivePermissionKeys());
    }

    #[Test]
    public function createWithUnknownRoleIdReturns422AndPersistsNothing(): void
    {
        $admin = $this->makeAdmin();
        // findBy resolves to empty — none of the requested ids exist.
        $this->bindEmForCreate($admin, emailTaken: false, roles: []);

        $response = $this->makePost($admin, '/v3/admin/users', [
            'first_name' => 'Nour',
            'last_name' => 'Saleh',
            'email' => 'nour@bayti.example',
            'password' => 'str0ngPass!',
            'role_ids' => [999],
        ]);

        self::assertSame(422, $response->getStatusCode());
        self::assertCount(0, $this->persistedUsers);
    }

    #[Test]
    public function createCannotAssignRoleBeyondActingAdminPermissions(): void
    {
        // #5: a non-super-admin with users.create cannot mint a staff account
        // carrying a role that grants more than the actor holds.
        $actor = $this->makeUser(id: 33, email: 'scoped@bayti.example');
        $scopedRole = $this->role(1, 'creator', ['users.create', 'orders.view']);
        $actor->addRole($scopedRole);

        $powerfulRole = $this->role(7, 'refunds', ['orders.refund']);
        $this->bindEmForCreate($actor, emailTaken: false, roles: [$powerfulRole]);

        $response = $this->makePost($actor, '/v3/admin/users', [
            'first_name' => 'Nour',
            'last_name' => 'Saleh',
            'email' => 'nour@bayti.example',
            'password' => 'str0ngPass!',
            'role_ids' => [7],
        ]);

        self::assertSame(403, $response->getStatusCode());
        self::assertCount(0, $this->persistedUsers);
    }

    #[Test]
    public function createReturns409OnTakenEmail(): void
    {
        $admin = $this->makeAdmin();
        $this->bindEmForCreate($admin, emailTaken: true);

        $response = $this->makePost($admin, '/v3/admin/users', [
            'first_name' => 'Farah',
            'last_name' => 'Khan',
            'email' => 'taken@bayti.example',
            'password' => 'str0ngPass!',
        ]);

        self::assertSame(409, $response->getStatusCode());
        self::assertCount(0, $this->persistedUsers);
    }

    #[Test]
    public function createReturns422OnShortPassword(): void
    {
        $admin = $this->makeAdmin();
        $this->bindEmForCreate($admin, emailTaken: false);

        $response = $this->makePost($admin, '/v3/admin/users', [
            'first_name' => 'Farah',
            'last_name' => 'Khan',
            'email' => 'farah@bayti.example',
            'password' => 'short',
        ]);

        self::assertSame(422, $response->getStatusCode());
    }

    #[Test]
    public function createReturns422OnMissingName(): void
    {
        $admin = $this->makeAdmin();
        $this->bindEmForCreate($admin, emailTaken: false);

        $response = $this->makePost($admin, '/v3/admin/users', [
            'email' => 'farah@bayti.example',
            'password' => 'str0ngPass!',
        ]);

        self::assertSame(422, $response->getStatusCode());
    }

    #[Test]
    public function createForbiddenForNonAdmin(): void
    {
        $nonAdmin = $this->makeUser(id: 7, email: 'cust@bayti.example');
        $this->bindEmForCreate($nonAdmin, emailTaken: false);

        $response = $this->makePost($nonAdmin, '/v3/admin/users', [
            'first_name' => 'Farah',
            'last_name' => 'Khan',
            'email' => 'farah@bayti.example',
            'password' => 'str0ngPass!',
        ]);

        self::assertSame(403, $response->getStatusCode());
        self::assertCount(0, $this->persistedUsers);
    }

    #[Test]
    public function createUnauthenticatedReturns401(): void
    {
        $admin = $this->makeAdmin();
        $this->bindEmForCreate($admin, emailTaken: false);

        $response = $this->handle($this->jsonRequest('POST', '/v3/admin/users', [
            'first_name' => 'Farah',
            'last_name' => 'Khan',
            'email' => 'farah@bayti.example',
            'password' => 'str0ngPass!',
        ]));

        self::assertSame(401, $response->getStatusCode());
    }

    // ----------------------------------------------------------------
    // AdminResetPasswordController
    // ----------------------------------------------------------------

    #[Test]
    public function resetRehashesAndRevokesSessions(): void
    {
        $admin = $this->makeAdmin();
        $target = $this->makeUser(id: 55, email: 'locked@bayti.example', passwordPlain: 'oldPass!');
        $oldHash = $target->getPasswordHash();
        $this->bindEmForReset($admin, $target);

        $response = $this->makePatch($admin, '/v3/admin/users/55/password', [
            'password' => 'brandN3wPass!',
        ]);

        self::assertSame(200, $response->getStatusCode());
        self::assertNotSame($oldHash, $target->getPasswordHash(), 'hash changed');
        self::assertTrue(
            password_verify('brandN3wPass!', $target->getPasswordHash()),
            'new password verifies',
        );
        self::assertSame(1, $this->revokeAllCalls, 'all refresh tokens revoked once');
        self::assertSame('admin_password_reset', $this->lastRevokeReason);
    }

    #[Test]
    public function resetReturns404ForUnknownUser(): void
    {
        $admin = $this->makeAdmin();
        $this->bindEmForReset($admin, target: null);

        $response = $this->makePatch($admin, '/v3/admin/users/9999/password', [
            'password' => 'brandN3wPass!',
        ]);

        self::assertSame(404, $response->getStatusCode());
        self::assertSame(0, $this->revokeAllCalls);
    }

    #[Test]
    public function resetReturns422OnShortPassword(): void
    {
        $admin = $this->makeAdmin();
        $target = $this->makeUser(id: 55, email: 'locked@bayti.example');
        $this->bindEmForReset($admin, $target);

        $response = $this->makePatch($admin, '/v3/admin/users/55/password', [
            'password' => 'short',
        ]);

        self::assertSame(422, $response->getStatusCode());
    }

    #[Test]
    public function resetForbiddenForNonAdmin(): void
    {
        $nonAdmin = $this->makeUser(id: 7, email: 'cust@bayti.example');
        $target = $this->makeUser(id: 55, email: 'locked@bayti.example');
        $this->bindEmForReset($nonAdmin, $target);

        $response = $this->makePatch($nonAdmin, '/v3/admin/users/55/password', [
            'password' => 'brandN3wPass!',
        ]);

        self::assertSame(403, $response->getStatusCode());
        self::assertSame(0, $this->revokeAllCalls);
    }

    // ----------------------------------------------------------------
    // Binding helpers
    // ----------------------------------------------------------------

    /**
     * @param list<Role> $roles roles findBy([id=>...]) should resolve to
     */
    private function bindEmForCreate(User $caller, bool $emailTaken, array $roles = []): void
    {
        $userRepo = $this->createMock(UserRepository::class);
        $userRepo->method('findById')->willReturn($caller);
        $userRepo->method('isEmailAvailable')->willReturn(!$emailTaken);

        $persistedSink = &$this->persistedUsers;
        $userRepo->method('save')->willReturnCallback(
            function (User $u) use (&$persistedSink): void {
                $ref = new \ReflectionProperty(User::class, 'id');
                $ref->setAccessible(true);
                if ($ref->getValue($u) === null) {
                    $ref->setValue($u, count($persistedSink) + 2000);
                }
                $persistedSink[] = $u;
            },
        );

        $roleRepo = $this->createMock(EntityRepository::class);
        $roleRepo->method('findBy')->willReturn($roles);

        $em = $this->stubEm(function ($em) use ($userRepo, $roleRepo): void {
            $em->method('getRepository')->willReturnMap([
                [User::class, $userRepo],
                [Role::class, $roleRepo],
            ]);
        });
        $this->bind(EntityManagerInterface::class, $em);
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

    private function bindEmForReset(User $caller, ?User $target): void
    {
        $userRepo = $this->createMock(UserRepository::class);
        // The caller is resolved by AuthMiddleware via findById(callerId);
        // the target by the controller via findById(targetId). Distinguish
        // by id so both resolve correctly.
        $userRepo->method('findById')->willReturnCallback(
            function (int $id) use ($caller, $target): ?User {
                if ($id === $caller->getId()) {
                    return $caller;
                }
                return $target;
            },
        );

        $refreshRepo = $this->createMock(RefreshTokenRepository::class);
        $refreshRepo->method('revokeAllForUser')->willReturnCallback(
            function (User $u, string $reason): int {
                $this->revokeAllCalls++;
                $this->lastRevokeReason = $reason;
                return 1;
            },
        );

        $em = $this->stubEm(function ($em) use ($userRepo, $refreshRepo): void {
            $em->method('getRepository')->willReturnMap([
                [User::class, $userRepo],
                [RefreshToken::class, $refreshRepo],
            ]);
        });
        $this->bind(EntityManagerInterface::class, $em);
    }

    /** @param array<string, mixed> $body */
    private function makePost(User $user, string $uri, array $body): ResponseInterface
    {
        $jwt = $this->app->getContainer()->get(JwtService::class);
        $pair = $jwt->issueTokenPair($user);
        return $this->handle($this->jsonRequest('POST', $uri, $body, [
            'Authorization' => 'Bearer ' . $pair->accessToken,
        ]));
    }

    /** @param array<string, mixed> $body */
    private function makePatch(User $user, string $uri, array $body): ResponseInterface
    {
        $jwt = $this->app->getContainer()->get(JwtService::class);
        $pair = $jwt->issueTokenPair($user);
        return $this->handle($this->jsonRequest('PATCH', $uri, $body, [
            'Authorization' => 'Bearer ' . $pair->accessToken,
        ]));
    }
}
