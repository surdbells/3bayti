<?php

declare(strict_types=1);

namespace Bayti\Api\Domain\Wishlist;

use Bayti\Api\Domain\User\User;
use DateTimeImmutable;
use Doctrine\ORM\Mapping as ORM;

/**
 * A user-defined wishlist label (a "closet" / folder).
 *
 * Net-new in M3.2.Z.3 (Q-Z3=B). A user can group their saved products
 * under named labels; a saved product belongs to at most one label
 * (Wishlist.label, nullable). Deleting a label moves its products back
 * to uncategorized via ON DELETE SET NULL, no products are lost.
 *
 * Only created_at is tracked (rename mutates `name` in place; we don't
 * surface an updated_at).
 */
#[ORM\Entity(repositoryClass: WishlistLabelRepository::class)]
#[ORM\Table(name: 'wishlist_labels')]
#[ORM\UniqueConstraint(name: 'uq_wishlist_labels_user_name', columns: ['user_id', 'name'])]
#[ORM\Index(columns: ['user_id'], name: 'idx_wishlist_labels_user')]
class WishlistLabel
{
    #[ORM\Id]
    #[ORM\GeneratedValue(strategy: 'IDENTITY')]
    #[ORM\Column(type: 'bigint')]
    /** @phpstan-ignore property.unusedType (Doctrine assigns the id via reflection on persist) */
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(name: 'user_id', referencedColumnName: 'id', nullable: false, onDelete: 'CASCADE')]
    private User $user;

    #[ORM\Column(type: 'string', length: 80)]
    private string $name;

    #[ORM\Column(name: 'created_at', type: 'datetimetz_immutable')]
    private DateTimeImmutable $createdAt;

    public function __construct(User $user, string $name)
    {
        $this->user = $user;
        $this->name = $name;
        $this->createdAt = new DateTimeImmutable();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getUser(): User
    {
        return $this->user;
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function rename(string $name): void
    {
        $this->name = $name;
    }

    public function getCreatedAt(): DateTimeImmutable
    {
        return $this->createdAt;
    }
}
