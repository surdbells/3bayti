<?php

declare(strict_types=1);

namespace Bayti\Api\Http\Serializers;

use Bayti\Api\Domain\Authz\Role;

/**
 * Serializes RBAC roles for the admin staff/roles management surface.
 */
final class RoleSerializer
{
    /** @return array{id:int|null, slug:string, name:string, description:string|null, is_system:bool, permissions:list<string>} */
    public function detail(Role $role): array
    {
        return [
            'id' => $role->getId(),
            'slug' => $role->getSlug(),
            'name' => $role->getName(),
            'description' => $role->getDescription(),
            'is_system' => $role->isSystem(),
            'permissions' => $role->getPermissionKeys(),
        ];
    }

    /**
     * @param iterable<Role> $roles
     * @return list<array<string, mixed>>
     */
    public function list(iterable $roles): array
    {
        $out = [];
        foreach ($roles as $role) {
            $out[] = $this->detail($role);
        }
        return $out;
    }
}
