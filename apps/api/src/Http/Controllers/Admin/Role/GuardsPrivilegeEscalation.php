<?php declare(strict_types=1);
namespace Bayti\Api\Http\Controllers\Admin\Role;

use Bayti\Api\Domain\User\User;
use Bayti\Api\Http\Errors\HttpException;
use Bayti\Api\Http\Middleware\AuthMiddleware;
use Psr\Http\Message\ServerRequestInterface;

/**
 * Privilege-escalation guard for the role/permission-management surface.
 *
 * The admin tier is gated coarsely by AdminAuthMiddleware + per-route
 * PermissionMiddleware, but that is not sufficient on its own: a non-super-admin
 * who legitimately holds `roles.create` / `roles.edit` / `users.manage_roles`
 * could otherwise mint or assign a role granting permissions BEYOND their own,
 * escalating their effective privileges. We close that hole here by intersecting
 * any permission set the actor tries to grant against the actor's OWN effective
 * permissions.
 *
 * Super-admins (`is_admin`) are exempt, they already hold every permission, so
 * no set can be a superset of theirs.
 */
trait GuardsPrivilegeEscalation
{
    /** Resolve the authenticated acting admin, or 401 if absent (mis-wired chain). */
    private function actingAdmin(ServerRequestInterface $request): User
    {
        $actor = $request->getAttribute(AuthMiddleware::ATTR_USER);
        if (!$actor instanceof User) {
            throw HttpException::unauthorized();
        }
        return $actor;
    }

    /**
     * Reject (403) if any of the requested permission keys is not held by the
     * acting admin. No-op for super-admins. Returns silently when allowed.
     *
     * @param list<string> $requestedKeys
     */
    private function assertNoPermissionEscalation(User $actor, array $requestedKeys): void
    {
        if ($actor->isAdmin()) {
            return; // Super-admin holds every key; nothing can exceed it.
        }
        $held = array_flip($actor->effectivePermissionKeys());
        $beyond = array_values(array_filter(
            $requestedKeys,
            static fn (string $k): bool => !isset($held[$k]),
        ));
        if ($beyond !== []) {
            throw HttpException::forbidden(
                'You cannot grant permissions beyond your own: ' . implode(', ', $beyond),
            );
        }
    }
}
