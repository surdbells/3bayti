<?php

declare(strict_types=1);

namespace Bayti\Api\Domain\Authz;

use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'permissions')]
class Permission
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'integer')]
    private ?int $id = null;

    #[ORM\Column(name: 'permission_key', type: 'string', length: 100, unique: true)]
    private string $key;

    #[ORM\Column(type: 'string', length: 50)]
    private string $module;

    #[ORM\Column(type: 'string', length: 150)]
    private string $label;

    public function __construct(string $key, string $module, string $label)
    {
        $this->key = $key;
        $this->module = $module;
        $this->label = $label;
    }

    public function getId(): ?int { return $this->id; }
    public function getKey(): string { return $this->key; }
    public function getModule(): string { return $this->module; }
    public function getLabel(): string { return $this->label; }
}
