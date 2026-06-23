<?php

declare(strict_types=1);

namespace Bayti\Api\Tests\Http\Controllers\Admin\Role;

use Bayti\Api\Domain\Authz\Permission;
use Bayti\Api\Domain\Authz\Role;
use Bayti\Api\Domain\User\User;
use Bayti\Api\Domain\User\UserRepository;
use Bayti\Api\Http\Controllers\Admin\Role\AssignUserRolesController;
use Bayti\Api\Http\Controllers\Admin\Role\CreateRoleController;
use Bayti\Api\Http\Controllers\Admin\Role\DeleteRoleController;
use Bayti\Api\Http\Controllers\Admin\Role\ListPermissionCatalogController;
use Bayti\Api\Http\Controllers\Admin\Role\UpdateRoleController;
use Bayti\Api\Infrastructure\Auth\JwtService;
use Bayti\Api\Tests\Http\HttpTestCase;
use Doctrine\ORM\EntityRepository;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use Psr\Http\Message\ResponseInterface;

#[CoversClass(ListPermissionCatalogController::class)]
#[CoversClass(CreateRoleController::class)]
#[CoversClass(UpdateRoleController::class)]
#[CoversClass(DeleteRoleController::class)]
#[CoversClass(AssignUserRolesController::class)]
final class RoleControllersTest extends HttpTestCase
{
    private function makeAdminUser(int $id = 99): User
    {
        $user = $this->makeUser(id: $id);
        $user->setRoles(admin: true);
        return $user;
    }

    /**
     * A non-super-admin staff user whose single role grants exactly the given
     * permission keys (plus enough to pass the route's PermissionMiddleware,
     * which the caller supplies in $keys).
     *
     * @param list<string> $keys
     */
    private function makeScopedStaff(int $id, array $keys): User
    {
        $user = $this->makeUser(id: $id);
        $role = new Role('scoped_' . $id, 'Scoped ' . $id);
        foreach ($keys as $k) {
            $role->addPermission(new Permission($k, explode('.', $k)[0], $k));
        }
        $user->addRole($role);
        return $user;
    }

    private function token(User $user): string
    {
        return $this->app->getContainer()->get(JwtService::class)->issueTokenPair($user)->accessToken;
    }

    private function get(User $user, string $uri): ResponseInterface
    {
        return $this->handle($this->jsonRequest('GET', $uri, [], ['Authorization' => 'Bearer ' . $this->token($user)]));
    }

    /** @param array<string,mixed> $body */
    private function send(User $user, string $method, string $uri, array $body): ResponseInterface
    {
        return $this->handle($this->jsonRequest($method, $uri, $body, ['Authorization' => 'Bearer ' . $this->token($user)]));
    }

    /** @param array<class-string,object> $repos */
    private function bindRepos(User $caller, array $repos): void
    {
        $userRepo = $this->createMock(UserRepository::class);
        $userRepo->method('findById')->willReturnCallback(
            fn (int $id): ?User => $id === $caller->getId() ? $caller : ($repos['__target_user'] ?? null),
        );
        $map = [[User::class, $userRepo]];
        foreach ($repos as $class => $repo) {
            if ($class !== '__target_user') {
                $map[] = [$class, $repo];
            }
        }
        $em = $this->stubEm(function ($em) use ($map): void {
            $em->method('getRepository')->willReturnMap($map);
        });
        $this->bind(\Doctrine\ORM\EntityManagerInterface::class, $em);
    }

    #[Test]
    public function permissionCatalogReturnsModulesAndPresets(): void
    {
        $admin = $this->makeAdminUser();
        $this->bindRepos($admin, []);

        $response = $this->get($admin, '/v3/admin/permission-catalog');
        self::assertSame(200, $response->getStatusCode());

        $body = json_decode((string) $response->getBody(), true);
        $data = $body['data'] ?? [];
        self::assertNotEmpty($data['modules'] ?? []);
        self::assertNotEmpty($data['presets'] ?? []);
        $slugs = array_column($data['modules'], 'module');
        self::assertContains('orders', $slugs);
        self::assertContains('users', $slugs);
        $presetSlugs = array_column($data['presets'], 'slug');
        self::assertContains('super_admin', $presetSlugs);
    }

    #[Test]
    public function createRoleWithValidPermissionsReturns201(): void
    {
        $admin = $this->makeAdminUser();

        $roleRepo = $this->createMock(EntityRepository::class);
        $roleRepo->method('findOneBy')->willReturn(null); // slug is unique
        $permRepo = $this->createMock(EntityRepository::class);
        $permRepo->method('findBy')->willReturn([
            new Permission('orders.view', 'orders', 'View orders'),
            new Permission('orders.refund', 'orders', 'Refund orders'),
        ]);
        $this->bindRepos($admin, [Role::class => $roleRepo, Permission::class => $permRepo]);

        $response = $this->send($admin, 'POST', '/v3/admin/roles', [
            'name' => 'Refund Desk',
            'description' => 'Handles refunds',
            'permissions' => ['orders.view', 'orders.refund'],
        ]);

        self::assertSame(201, $response->getStatusCode());
        $body = json_decode((string) $response->getBody(), true);
        $role = $body['data'] ?? [];
        self::assertSame('Refund Desk', $role['name']);
        self::assertSame('refund_desk', $role['slug']);
        self::assertFalse($role['is_system']);
        self::assertEqualsCanonicalizing(['orders.view', 'orders.refund'], $role['permissions']);
    }

    #[Test]
    public function createRoleWithUnknownPermissionIsRejected(): void
    {
        $admin = $this->makeAdminUser();
        $this->bindRepos($admin, []);

        $response = $this->send($admin, 'POST', '/v3/admin/roles', [
            'name' => 'Bogus',
            'permissions' => ['orders.view', 'totally.madeup'],
        ]);

        self::assertSame(422, $response->getStatusCode());
        $body = json_decode((string) $response->getBody(), true);
        self::assertArrayHasKey('error', $body);
    }

    #[Test]
    public function deletingSystemRoleIsBlocked(): void
    {
        $admin = $this->makeAdminUser();

        $systemRole = new Role('super_admin', 'Super Admin', 'all', true);
        $roleRepo = $this->createMock(EntityRepository::class);
        $roleRepo->method('find')->willReturn($systemRole);
        $this->bindRepos($admin, [Role::class => $roleRepo]);

        $response = $this->send($admin, 'DELETE', '/v3/admin/roles/1', []);

        self::assertSame(409, $response->getStatusCode());
        $body = json_decode((string) $response->getBody(), true);
        self::assertSame('role_is_system', $body['error']['code'] ?? null);
    }

    #[Test]
    public function assignRolesReplacesUserRoles(): void
    {
        $admin = $this->makeAdminUser(7); // caller == target (assign to self)

        $opsRole = new Role('operations', 'Operations');
        $finRole = new Role('finance', 'Finance');
        $roleRepo = $this->createMock(EntityRepository::class);
        $roleRepo->method('findBy')->willReturn([$opsRole, $finRole]);
        $this->bindRepos($admin, [Role::class => $roleRepo]);

        $response = $this->send($admin, 'POST', '/v3/admin/users/7/roles', ['role_ids' => [1, 2]]);

        self::assertSame(200, $response->getStatusCode());
        $body = json_decode((string) $response->getBody(), true);
        $assigned = array_column($body['data']['assigned_roles'] ?? [], 'slug');
        self::assertEqualsCanonicalizing(['operations', 'finance'], $assigned);
    }

    // ----------------------------------------------------------------
    // #4 System-role protection (UpdateRoleController)
    // ----------------------------------------------------------------

    #[Test]
    public function updatingSystemRolePermissionsIsRejected(): void
    {
        $admin = $this->makeAdminUser();

        $systemRole = new Role('super_admin', 'Super Admin', 'all', true);
        $roleRepo = $this->createMock(EntityRepository::class);
        $roleRepo->method('find')->willReturn($systemRole);
        $this->bindRepos($admin, [Role::class => $roleRepo]);

        $response = $this->send($admin, 'PUT', '/v3/admin/roles/1', [
            'permissions' => ['orders.view'],
        ]);

        self::assertSame(422, $response->getStatusCode());
        $body = json_decode((string) $response->getBody(), true);
        self::assertArrayHasKey('permissions', $body['error']['details']['fields'] ?? []);
    }

    #[Test]
    public function updatingSystemRoleSlugIsRejected(): void
    {
        $admin = $this->makeAdminUser();

        $systemRole = new Role('super_admin', 'Super Admin', 'all', true);
        $roleRepo = $this->createMock(EntityRepository::class);
        $roleRepo->method('find')->willReturn($systemRole);
        $this->bindRepos($admin, [Role::class => $roleRepo]);

        $response = $this->send($admin, 'PUT', '/v3/admin/roles/1', [
            'slug' => 'not_super',
        ]);

        self::assertSame(422, $response->getStatusCode());
    }

    #[Test]
    public function updatingSystemRoleNameOnlyIsAllowed(): void
    {
        $admin = $this->makeAdminUser();

        $systemRole = new Role('super_admin', 'Super Admin', 'all', true);
        $roleRepo = $this->createMock(EntityRepository::class);
        $roleRepo->method('find')->willReturn($systemRole);
        $this->bindRepos($admin, [Role::class => $roleRepo]);

        $response = $this->send($admin, 'PUT', '/v3/admin/roles/1', [
            'name' => 'Super Administrator',
            'description' => 'Tweaked label',
        ]);

        self::assertSame(200, $response->getStatusCode());
        $body = json_decode((string) $response->getBody(), true);
        self::assertSame('Super Administrator', $body['data']['name']);
        self::assertSame('super_admin', $body['data']['slug']);
    }

    // ----------------------------------------------------------------
    // #5 Privilege-escalation guard
    // ----------------------------------------------------------------

    #[Test]
    public function nonSuperAdminCannotCreateRoleBeyondOwnPermissions(): void
    {
        // Actor holds roles.create (to pass the route guard) + orders.view only.
        $actor = $this->makeScopedStaff(42, ['roles.create', 'orders.view']);

        $roleRepo = $this->createMock(EntityRepository::class);
        $roleRepo->method('findOneBy')->willReturn(null);
        $permRepo = $this->createMock(EntityRepository::class);
        $this->bindRepos($actor, [Role::class => $roleRepo, Permission::class => $permRepo]);

        // Tries to grant orders.refund — which the actor does NOT hold.
        $response = $this->send($actor, 'POST', '/v3/admin/roles', [
            'name' => 'Sneaky',
            'permissions' => ['orders.view', 'orders.refund'],
        ]);

        self::assertSame(403, $response->getStatusCode());
    }

    #[Test]
    public function nonSuperAdminCanCreateRoleWithinOwnPermissions(): void
    {
        $actor = $this->makeScopedStaff(43, ['roles.create', 'orders.view', 'orders.refund']);

        $roleRepo = $this->createMock(EntityRepository::class);
        $roleRepo->method('findOneBy')->willReturn(null);
        $permRepo = $this->createMock(EntityRepository::class);
        $permRepo->method('findBy')->willReturn([
            new Permission('orders.view', 'orders', 'View orders'),
            new Permission('orders.refund', 'orders', 'Refund orders'),
        ]);
        $this->bindRepos($actor, [Role::class => $roleRepo, Permission::class => $permRepo]);

        $response = $this->send($actor, 'POST', '/v3/admin/roles', [
            'name' => 'Within Scope',
            'permissions' => ['orders.view', 'orders.refund'],
        ]);

        self::assertSame(201, $response->getStatusCode());
    }

    #[Test]
    public function nonSuperAdminCannotAssignRoleBeyondOwnPermissions(): void
    {
        // Actor can manage roles + holds orders.view, but the role they try to
        // assign confers orders.refund (which they lack).
        $actor = $this->makeScopedStaff(50, ['users.manage_roles', 'orders.view']);

        $powerfulRole = new Role('refunds', 'Refunds');
        $powerfulRole->addPermission(new Permission('orders.refund', 'orders', 'Refund orders'));

        $roleRepo = $this->createMock(EntityRepository::class);
        $roleRepo->method('findBy')->willReturn([$powerfulRole]);
        // Target user is someone else (id 60).
        $target = $this->makeUser(id: 60);
        $this->bindRepos($actor, [Role::class => $roleRepo, '__target_user' => $target]);

        $response = $this->send($actor, 'POST', '/v3/admin/users/60/roles', ['role_ids' => [9]]);

        self::assertSame(403, $response->getStatusCode());
    }
}
