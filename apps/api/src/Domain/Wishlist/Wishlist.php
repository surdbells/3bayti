<?php

declare(strict_types=1);

namespace Bayti\Api\Domain\Wishlist;

use Bayti\Api\Domain\Catalog\Product;
use Bayti\Api\Domain\User\User;
use DateTimeImmutable;
use Doctrine\ORM\Mapping as ORM;

/**
 * A single saved product on a user's wishlist.
 *
 * One row per (user, product) — the unique index in the migration
 * enforces "saved at most once". Net-new in M3.2.Y.6 (Q6.4:
 * products-only).
 *
 * Only created_at is tracked (no updated_at) — a wishlist entry is
 * immutable: it's either there or it isn't. Toggling "saved" is a
 * create/delete, never an update, so there is nothing to re-stamp.
 */
#[ORM\Entity(repositoryClass: WishlistRepository::class)]
#[ORM\Table(name: 'wishlist')]
#[ORM\UniqueConstraint(name: 'uq_wishlist_user_product', columns: ['user_id', 'product_id'])]
#[ORM\Index(columns: ['user_id', 'created_at'], name: 'idx_wishlist_user_created')]
class Wishlist
{
    #[ORM\Id]
    #[ORM\GeneratedValue(strategy: 'IDENTITY')]
    #[ORM\Column(type: 'bigint')]
    /** @phpstan-ignore property.unusedType (Doctrine assigns the id via reflection on persist) */
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(name: 'user_id', referencedColumnName: 'id', nullable: false, onDelete: 'CASCADE')]
    private User $user;

    #[ORM\ManyToOne(targetEntity: Product::class)]
    #[ORM\JoinColumn(name: 'product_id', referencedColumnName: 'id', nullable: false, onDelete: 'CASCADE')]
    private Product $product;

    #[ORM\Column(name: 'created_at', type: 'datetimetz_immutable')]
    private DateTimeImmutable $createdAt;

    public function __construct(User $user, Product $product)
    {
        $this->user = $user;
        $this->product = $product;
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

    public function getProduct(): Product
    {
        return $this->product;
    }

    public function getCreatedAt(): DateTimeImmutable
    {
        return $this->createdAt;
    }
}
