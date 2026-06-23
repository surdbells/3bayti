<?php

declare(strict_types=1);

namespace Bayti\Api\Tests\Domain\User;

use Bayti\Api\Domain\Authz\Permission;
use Bayti\Api\Domain\Authz\Role;
use Bayti\Api\Domain\User\User;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class UserRbacTest extends TestCase
{
    private function user(): User
    {
        return new User('staff@example.com', '+971500000000', password_hash('x', PASSWORD_BCRYPT), 'AE');
    }

    private function role(string $slug, array $permissionKeys): Role
    {
        $role = new Role($slug, ucfirst($slug));
        foreach ($permissionKeys as $key) {
            $role->addPermission(new Permission($key, explode('.', $key)[0], $key));
        }
        return $role;
    }

    #[Test]
    public function customerWithNoRolesIsNotStaffAndHasNoPermissions(): void
    {
        $user = $this->user();

        self::assertFalse($user->isStaff());
        self::assertSame([], $user->effectivePermissionKeys());
        self::assertFalse($user->hasPermission('orders.view'));
    }

    #[Test]
    public function effectivePermissionsAreTheUnionOfAllRolesDeduplicated(): void
    {
        $user = $this->user();
        $user->addRole($this->role('support', ['tickets.view', 'orders.view']));
        $user->addRole($this->role('finance', ['orders.view', 'payouts.process']));

        $keys = $user->effectivePermissionKeys();
        sort($keys);

        self::assertSame(['orders.view', 'payouts.process', 'tickets.view'], $keys);
        self::assertTrue($user->isStaff());
        self::assertTrue($user->hasPermission('payouts.process'));
        self::assertFalse($user->hasPermission('orders.refund'));
    }

    #[Test]
    public function superAdminHoldsEveryPermissionWithoutAnyRole(): void
    {
        $user = $this->user();
        $user->setRoles(admin: true);

        self::assertTrue($user->isStaff());
        self::assertTrue($user->hasPermission('orders.refund'));
        self::assertTrue($user->hasPermission('anything.at.all'));
    }

    #[Test]
    public function backOfficeFlagMarkersCountAsStaffEvenWithoutAnyRole(): void
    {
        // A freshly created back-office account (finance/support/sub_admin)
        // must be reachable on the admin surface BEFORE any RBAC role is
        // attached, otherwise it can never be assigned a role. Per-route
        // PermissionMiddleware still gates each endpoint.
        foreach (['finance', 'support', 'sub_admin'] as $marker) {
            $user = $this->user();
            $user->setRoles(
                finance: $marker === 'finance',
                support: $marker === 'support',
                subAdmin: $marker === 'sub_admin',
            );

            self::assertTrue(
                $user->isStaff(),
                "marker '{$marker}' should make the account staff",
            );
            // But it confers no granular permissions on its own.
            self::assertSame([], $user->effectivePermissionKeys());
        }
    }

    #[Test]
    public function removingARoleRevokesItsPermissions(): void
    {
        $user = $this->user();
        $finance = $this->role('finance', ['payouts.process']);
        $user->addRole($finance);
        self::assertTrue($user->hasPermission('payouts.process'));

        $user->removeRole($finance);
        self::assertFalse($user->hasPermission('payouts.process'));
        self::assertFalse($user->isStaff());
    }
}
