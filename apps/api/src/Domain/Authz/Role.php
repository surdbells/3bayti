<?php

declare(strict_types=1);

namespace Bayti\Api\Domain\Authz;

use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'roles')]
class Role
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'integer')]
    private ?int $id = null;

    #[ORM\Column(type: 'string', length: 80, unique: true)]
    private string $slug;

    #[ORM\Column(type: 'string', length: 120)]
    private string $name;

    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $description = null;

    /** System roles are seeded and cannot be deleted (their permissions can still be tuned). */
    #[ORM\Column(name: 'is_system', type: 'boolean', options: ['default' => false])]
    private bool $isSystem = false;

    /** @var Collection<int, Permission> */
    #[ORM\ManyToMany(targetEntity: Permission::class, fetch: 'EAGER')]
    #[ORM\JoinTable(name: 'role_permission')]
    #[ORM\JoinColumn(name: 'role_id', referencedColumnName: 'id', onDelete: 'CASCADE')]
    #[ORM\InverseJoinColumn(name: 'permission_id', referencedColumnName: 'id', onDelete: 'CASCADE')]
    private Collection $permissions;

    public function __construct(string $slug, string $name, ?string $description = null, bool $isSystem = false)
    {
        $this->slug = $slug;
        $this->name = $name;
        $this->description = $description;
        $this->isSystem = $isSystem;
        $this->permissions = new ArrayCollection();
    }

    public function getId(): ?int { return $this->id; }
    public function getSlug(): string { return $this->slug; }
    public function getName(): string { return $this->name; }
    public function setName(string $name): void { $this->name = $name; }
    public function getDescription(): ?string { return $this->description; }
    public function setDescription(?string $description): void { $this->description = $description; }
    public function isSystem(): bool { return $this->isSystem; }

    /** @return Collection<int, Permission> */
    public function getPermissions(): Collection { return $this->permissions; }

    /** @return list<string> */
    public function getPermissionKeys(): array
    {
        return array_values(array_map(static fn (Permission $p): string => $p->getKey(), $this->permissions->toArray()));
    }

    public function addPermission(Permission $permission): void
    {
        if (!$this->permissions->contains($permission)) {
            $this->permissions->add($permission);
        }
    }

    public function removePermission(Permission $permission): void
    {
        $this->permissions->removeElement($permission);
    }

    public function clearPermissions(): void
    {
        $this->permissions->clear();
    }
}
