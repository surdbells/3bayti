<?php

declare(strict_types=1);

namespace Bayti\Api\Domain\Catalog;

use Bayti\Api\Domain\Common\Timestamps;
use DateTimeImmutable;
use Doctrine\ORM\Mapping as ORM;

/**
 * A vendor-curated merchandising label (collection).
 *
 * Domain shape
 * ============
 * Labels are per-vendor named groupings of products, examples:
 * "Eid Collection", "New In", "Hand-embroidered". A product belongs
 * to at most one label (single-label-per-product is enforced by the
 * `products.label_id` NULLABLE INT column; see migration
 * Version20260514000002).
 *
 * Labels are populated from the legacy WordPress/CodeIgniter schema
 * during the M3.1.5.5c migration step. Mobile call sites reference
 * them by legacy id (e.g. body `label: 4`); the resolver pattern from
 * M3.1.5a (by-legacy-id) handles the lookup.
 *
 * Soft-delete
 * ===========
 * is_active=false hides the label from list responses but keeps the
 * row so existing FK references from products.label_id remain valid.
 * The product retains its label_id; the FK constraint allows it
 * because the row exists.
 *
 * Slug uniqueness
 * ===============
 * Slug is unique PER VENDOR (composite UNIQUE on vendor_id + slug).
 * Two vendors can each have an "eid-collection" slug; the URL design
 * is /vendors/<vendor_slug>/labels/<label_slug> so disambiguation
 * happens via the vendor path segment.
 *
 * Display ordering
 * ================
 * display_order SMALLINT NULL. NULL means "no explicit order, sort
 * by name". When the vendor explicitly orders labels via admin UI,
 * smaller values appear first. Same semantics as the existing
 * categories.display_order field for consistency.
 */
#[ORM\Entity(repositoryClass: VendorLabelRepository::class)]
#[ORM\Table(name: 'vendor_labels')]
#[ORM\HasLifecycleCallbacks]
class VendorLabel
{
    use Timestamps;

    #[ORM\Id]
    #[ORM\GeneratedValue(strategy: 'IDENTITY')]
    #[ORM\Column(type: 'bigint')]
    private ?int $id = null;

    /**
     * Legacy `labels.id` reference from the legacy DB.
     *
     * Nullable because v3-native labels (created via admin after the
     * migration) have no legacy counterpart. UNIQUE so idempotent
     * re-migration is safe (the M3.1.5.5c step checks
     * legacyLabelId before INSERT).
     */
    #[ORM\Column(name: 'legacy_label_id', type: 'bigint', nullable: true, unique: true)]
    private ?int $legacyLabelId = null;

    /**
     * Owning vendor. Cascade delete: if a vendor is removed, their
     * labels are removed too (label-without-vendor is meaningless).
     */
    #[ORM\ManyToOne(targetEntity: Vendor::class)]
    #[ORM\JoinColumn(name: 'vendor_id', referencedColumnName: 'id', nullable: false, onDelete: 'CASCADE')]
    private Vendor $vendor;

    #[ORM\Column(name: 'slug', type: 'string', length: 120)]
    private string $slug;

    #[ORM\Column(name: 'name', type: 'string', length: 150)]
    private string $name;

    /**
     * Display order. NULL means "no explicit order, sort by name".
     * Smaller values appear first.
     */
    #[ORM\Column(name: 'display_order', type: 'smallint', nullable: true)]
    private ?int $displayOrder = null;

    #[ORM\Column(name: 'is_active', type: 'boolean')]
    private bool $isActive = true;

    public function __construct(Vendor $vendor, string $slug, string $name)
    {
        $this->vendor = $vendor;
        $this->slug = $slug;
        $this->name = $name;
        $this->createdAt = new DateTimeImmutable();
        $this->updatedAt = $this->createdAt;
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getLegacyLabelId(): ?int
    {
        return $this->legacyLabelId;
    }

    public function setLegacyLabelId(?int $id): void
    {
        $this->legacyLabelId = $id;
    }

    public function getVendor(): Vendor
    {
        return $this->vendor;
    }

    public function getSlug(): string
    {
        return $this->slug;
    }

    public function setSlug(string $slug): void
    {
        $this->slug = $slug;
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function setName(string $name): void
    {
        $this->name = $name;
    }

    public function getDisplayOrder(): ?int
    {
        return $this->displayOrder;
    }

    public function setDisplayOrder(?int $order): void
    {
        $this->displayOrder = $order;
    }

    public function isActive(): bool
    {
        return $this->isActive;
    }

    public function setActive(bool $active): void
    {
        $this->isActive = $active;
    }
}
