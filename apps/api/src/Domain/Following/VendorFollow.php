<?php

declare(strict_types=1);

namespace Bayti\Api\Domain\Following;

use Bayti\Api\Domain\Catalog\Vendor;
use Bayti\Api\Domain\User\User;
use DateTimeImmutable;
use Doctrine\ORM\Mapping as ORM;

/**
 * A single "user follows vendor" relationship.
 *
 * One row per (user, vendor), the unique index enforces "followed at
 * most once". Following is immutable: it's either there or it isn't, so
 * toggling follow is a create/delete, never an update (mirrors the
 * Wishlist entity's posture). Net-new in the v3 customer-features
 * build (legacy /customer/follow + /customer/unfollow).
 */
#[ORM\Entity(repositoryClass: VendorFollowRepository::class)]
#[ORM\Table(name: 'vendor_follow')]
#[ORM\UniqueConstraint(name: 'uq_vendor_follow_user_vendor', columns: ['user_id', 'vendor_id'])]
#[ORM\Index(columns: ['user_id', 'created_at'], name: 'idx_vendor_follow_user_created')]
#[ORM\Index(columns: ['vendor_id'], name: 'idx_vendor_follow_vendor')]
class VendorFollow
{
    #[ORM\Id]
    #[ORM\GeneratedValue(strategy: 'IDENTITY')]
    #[ORM\Column(type: 'bigint')]
    /** @phpstan-ignore property.unusedType (Doctrine assigns the id via reflection on persist) */
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(name: 'user_id', referencedColumnName: 'id', nullable: false, onDelete: 'CASCADE')]
    private User $user;

    #[ORM\ManyToOne(targetEntity: Vendor::class)]
    #[ORM\JoinColumn(name: 'vendor_id', referencedColumnName: 'id', nullable: false, onDelete: 'CASCADE')]
    private Vendor $vendor;

    #[ORM\Column(name: 'created_at', type: 'datetimetz_immutable')]
    private DateTimeImmutable $createdAt;

    public function __construct(User $user, Vendor $vendor)
    {
        $this->user = $user;
        $this->vendor = $vendor;
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

    public function getVendor(): Vendor
    {
        return $this->vendor;
    }

    public function getCreatedAt(): DateTimeImmutable
    {
        return $this->createdAt;
    }
}
