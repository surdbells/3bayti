<?php

declare(strict_types=1);

namespace Bayti\Api\Domain\Catalog;

use Bayti\Api\Domain\User\User;
use DateTimeImmutable;
use Doctrine\ORM\Mapping as ORM;

/**
 * ProductReview, customer review attached to a Product (and via product, a Vendor).
 *
 * Legacy `ec_reviews` table has 12 columns including a product_name string
 * (NOT a product_id). The migration script resolves product_name + store_id
 * → our Product entity by lookup; if it can't resolve, the review is logged
 * + skipped (only 27 rows total, easy to manually handle outliers).
 *
 * Denormalised reviewer fields (reviewer_name, reviewer_email,
 * product_name_snapshot) are kept so that if a user is deleted/anonymised
 * later, the review's archival value is preserved. The FK to User uses
 * ON DELETE SET NULL, the row stays with anonymised reviewer info.
 *
 * `status` lifecycle:
 *   - 'pending'  , submitted, not yet moderated
 *   - 'approved' , visible to public
 *   - 'rejected' , hidden, reason stored elsewhere
 *   - 'spam'     , flagged by spam filter / moderator
 *
 * `star` is decimal(2,1) to allow half-stars (e.g. 4.5) if the UI supports
 * that later. CHECK constraint enforces 0.0–5.0.
 */
#[ORM\Entity(repositoryClass: ProductReviewRepository::class)]
#[ORM\Table(name: 'product_reviews')]
class ProductReview
{
    public const STATUS_PENDING = 'pending';
    public const STATUS_APPROVED = 'approved';
    public const STATUS_REJECTED = 'rejected';
    public const STATUS_SPAM = 'spam';

    #[ORM\Id]
    #[ORM\GeneratedValue(strategy: 'IDENTITY')]
    #[ORM\Column(type: 'bigint')]
    private ?int $id = null;

    #[ORM\Column(name: 'legacy_review_id', type: 'integer', nullable: true, unique: true)]
    private ?int $legacyReviewId = null;

    #[ORM\ManyToOne(targetEntity: Product::class)]
    #[ORM\JoinColumn(name: 'product_id', referencedColumnName: 'id', nullable: true, onDelete: 'SET NULL')]
    private ?Product $product = null;

    #[ORM\ManyToOne(targetEntity: Vendor::class)]
    #[ORM\JoinColumn(name: 'vendor_id', referencedColumnName: 'id', nullable: true, onDelete: 'SET NULL')]
    private ?Vendor $vendor = null;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(name: 'user_id', referencedColumnName: 'id', nullable: true, onDelete: 'SET NULL')]
    private ?User $user = null;

    // Denorm reviewer fields preserved across user-delete events
    #[ORM\Column(name: 'reviewer_name', type: 'string', length: 191, nullable: true)]
    private ?string $reviewerName = null;

    #[ORM\Column(name: 'reviewer_email', type: 'string', length: 191, nullable: true)]
    private ?string $reviewerEmail = null;

    #[ORM\Column(name: 'product_name_snapshot', type: 'string', length: 255, nullable: true)]
    private ?string $productNameSnapshot = null;

    #[ORM\Column(type: 'decimal', precision: 2, scale: 1)]
    private string $star;

    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $title = null;

    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $comment = null;

    #[ORM\Column(name: 'vendor_reply', type: 'text', nullable: true)]
    private ?string $vendorReply = null;

    #[ORM\Column(type: 'string', length: 20)]
    private string $status = self::STATUS_PENDING;

    #[ORM\Column(name: 'is_verified', type: 'boolean')]
    private bool $isVerified = false;

    #[ORM\Column(name: 'helpful_count', type: 'integer')]
    private int $helpfulCount = 0;

    #[ORM\Column(name: 'created_at', type: 'datetimetz_immutable')]
    private DateTimeImmutable $createdAt;

    #[ORM\Column(name: 'updated_at', type: 'datetimetz_immutable')]
    private DateTimeImmutable $updatedAt;

    public function __construct(string $star)
    {
        $this->star = $star;
        $this->createdAt = new DateTimeImmutable();
        $this->updatedAt = $this->createdAt;
    }

    public function getId(): ?int { return $this->id; }
    public function getLegacyReviewId(): ?int { return $this->legacyReviewId; }
    public function getProduct(): ?Product { return $this->product; }
    public function getVendor(): ?Vendor { return $this->vendor; }
    public function getUser(): ?User { return $this->user; }
    public function getReviewerName(): ?string { return $this->reviewerName; }
    public function getReviewerEmail(): ?string { return $this->reviewerEmail; }
    public function getProductNameSnapshot(): ?string { return $this->productNameSnapshot; }
    public function getStar(): string { return $this->star; }
    public function getTitle(): ?string { return $this->title; }
    public function getComment(): ?string { return $this->comment; }
    public function getVendorReply(): ?string { return $this->vendorReply; }
    public function getStatus(): string { return $this->status; }
    public function isVerified(): bool { return $this->isVerified; }
    public function getHelpfulCount(): int { return $this->helpfulCount; }
    public function getCreatedAt(): DateTimeImmutable { return $this->createdAt; }
    public function getUpdatedAt(): DateTimeImmutable { return $this->updatedAt; }

    public function setLegacyReviewId(?int $id): void { $this->legacyReviewId = $id; }
    public function setProduct(?Product $product): void { $this->product = $product; $this->touch(); }
    public function setVendor(?Vendor $vendor): void { $this->vendor = $vendor; $this->touch(); }
    public function setUser(?User $user): void { $this->user = $user; $this->touch(); }
    public function setReviewerName(?string $name): void { $this->reviewerName = $name; $this->touch(); }
    public function setReviewerEmail(?string $email): void { $this->reviewerEmail = $email; $this->touch(); }
    public function setProductNameSnapshot(?string $name): void { $this->productNameSnapshot = $name; $this->touch(); }
    public function setTitle(?string $title): void { $this->title = $title; $this->touch(); }
    public function setComment(?string $comment): void { $this->comment = $comment; $this->touch(); }
    public function setStar(string $star): void { $this->star = $star; $this->touch(); }
    public function setVendorReply(?string $reply): void { $this->vendorReply = $reply; $this->touch(); }

    public function setStatus(string $status): void
    {
        $valid = [self::STATUS_PENDING, self::STATUS_APPROVED, self::STATUS_REJECTED, self::STATUS_SPAM];
        if (!in_array($status, $valid, true)) {
            throw new \InvalidArgumentException("Invalid review status '{$status}'");
        }
        $this->status = $status;
        $this->touch();
    }

    public function setVerified(bool $v): void { $this->isVerified = $v; $this->touch(); }
    public function setHelpfulCount(int $count): void { $this->helpfulCount = max(0, $count); $this->touch(); }
    public function incrementHelpful(): void { $this->helpfulCount++; $this->touch(); }

    public function setCreatedAt(DateTimeImmutable $at): void
    {
        // Used by migration script to preserve legacy timestamps.
        $this->createdAt = $at;
    }

    private function touch(): void
    {
        $this->updatedAt = new DateTimeImmutable();
    }
}
