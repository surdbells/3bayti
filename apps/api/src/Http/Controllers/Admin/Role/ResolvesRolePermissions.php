<?php declare(strict_types=1);
namespace Bayti\Api\Http\Controllers\Admin\Role;

use Bayti\Api\Domain\Authz\Permission;
use Bayti\Api\Domain\Authz\PermissionCatalog;
use Bayti\Api\Domain\Authz\Role;
use Bayti\Api\Http\Errors\HttpException;
use Doctrine\ORM\EntityManagerInterface;

/** Shared permission-key validation + role↔permission syncing for role controllers. */
trait ResolvesRolePermissions
{
    /**
     * Validate that every provided key exists in the catalog.
     * @return list<string>
     */
    private function validatePermissionKeys(mixed $raw): array
    {
        if (!is_array($raw)) {
            throw HttpException::validation(['permissions' => 'Permissions must be an array of permission keys.']);
        }
        $keys = array_values(array_unique(array_filter(
            array_map(static fn ($k): string => (string) $k, $raw),
            static fn (string $k): bool => $k !== '',
        )));
        $invalid = array_values(array_filter($keys, static fn (string $k): bool => !PermissionCatalog::isValid($k)));
        if ($invalid !== []) {
            throw HttpException::validation(['permissions' => 'Unknown permission(s): ' . implode(', ', $invalid)]);
        }
        return $keys;
    }

    /** @param list<string> $keys */
    private function syncPermissions(EntityManagerInterface $em, Role $role, array $keys): void
    {
        $role->clearPermissions();
        if ($keys === []) {
            return;
        }
        $perms = $em->getRepository(Permission::class)->findBy(['key' => $keys]);
        foreach ($perms as $perm) {
            $role->addPermission($perm);
        }
    }

    private function uniqueSlug(EntityManagerInterface $em, string $name): string
    {
        $base = trim((string) preg_replace('/[^a-z0-9]+/', '_', strtolower($name)), '_');
        if ($base === '') {
            $base = 'role';
        }
        $slug = $base;
        $i = 2;
        $repo = $em->getRepository(Role::class);
        while ($repo->findOneBy(['slug' => $slug]) !== null) {
            $slug = $base . '_' . $i++;
        }
        return $slug;
    }
}
