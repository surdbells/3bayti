<?php

declare(strict_types=1);

namespace Bayti\Api\Domain\Catalog;

use Bayti\Api\Domain\Common\Timestamps;
use DateTimeImmutable;
use Doctrine\ORM\Mapping as ORM;

/**
 * A product category — node in the adjacency-list tree.
 *
 * Tree shape
 * ----------
 * Adjacency list with denormalised `path` for fast subtree queries.
 * See plan doc + migration docblock for why over nested set /
 * materialised path.
 *
 *   id  parent_id  slug      path
 *    1  NULL       clothing  /clothing
 *    2  1          womens    /clothing/womens
 *    3  2          abayas    /clothing/womens/abayas
 *
 * Path-maintenance contract
 * -------------------------
 * The `path` column is application-maintained, not DB-maintained.
 * Whenever a category is created or its parent changes, the
 * application MUST rebuild the path from the slug chain. The
 * CategoryRepository::recomputePath() helper does this.
 *
 * If a parent's slug changes, ALL descendant paths must be rebuilt.
 * That's a rare operation (slugs are stable URLs) but the helper
 * supports it via CategoryRepository::rebuildSubtreePaths().
 *
 * Soft-delete
 * -----------
 * Per D1, deletion sets is_active=false rather than removing the
 * row. This preserves audit log integrity and order history (when
 * products link to a deleted category, order details still resolve).
 *
 * Slug uniqueness
 * ---------------
 * GLOBALLY unique (the UNIQUE constraint is on the slug column
 * alone, not (parent_id, slug)). Reasons:
 *   - URL design: /categories/abayas not /categories/clothing/womens/abayas
 *   - Easier to refactor tree later (move a category without breaking links)
 *   - SEO: stable URLs across tree restructures
 *
 * Trade-off: two sibling categories can't share a slug under
 * different parents (e.g., "shoes" under "mens" and "womens" would
 * collide). Mitigation: namespace via prefix in the slug ("mens-
 * shoes"). Real catalogs don't actually have this collision often.
 */
#[ORM\Entity(repositoryClass: CategoryRepository::class)]
#[ORM\Table(name: 'categories')]
#[ORM\HasLifecycleCallbacks]
class Category
{
    use Timestamps;

    #[ORM\Id]
    #[ORM\GeneratedValue(strategy: 'IDENTITY')]
    #[ORM\Column(type: 'bigint')]
    private ?int $id = null;

    /**
     * Self-referential FK. NULL = root category.
     *
     * Why self-referential one-to-many vs many-to-one + collection
     * of children: we mostly read paths (denormalised) and rarely
     * navigate parent->children at the entity level. Repository
     * exposes findChildren($parent) explicitly. Avoids accidental
     * full-subtree hydration on entity load.
     */
    #[ORM\ManyToOne(targetEntity: Category::class)]
    #[ORM\JoinColumn(name: 'parent_id', referencedColumnName: 'id', nullable: true, onDelete: 'RESTRICT')]
    private ?Category $parent = null;

    #[ORM\Column(type: 'string', length: 100, unique: true)]
    private string $slug;

    #[ORM\Column(type: 'string', length: 150)]
    private string $name;

    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $description = null;

    #[ORM\Column(name: 'display_order', type: 'integer')]
    private int $displayOrder = 0;

    #[ORM\Column(name: 'image_url', type: 'string', length: 500, nullable: true)]
    private ?string $imageUrl = null;

    #[ORM\Column(name: 'is_active', type: 'boolean')]
    private bool $isActive = true;

    /**
     * Denormalised full path: '/clothing/womens/abayas'.
     * Maintained by application — see class docblock.
     */
    #[ORM\Column(type: 'string', length: 500)]
    private string $path;

    public function __construct(
        string $slug,
        string $name,
        ?Category $parent = null,
    ) {
        $this->slug = $slug;
        $this->name = $name;
        $this->parent = $parent;
        // Path is computed from slug + parent at construction.
        // If parent's path is empty (i.e., we're being built before
        // the parent has its own path set — shouldn't happen via
        // normal flows, but defensive), we still produce a valid
        // string.
        $this->path = $this->computePath();
        $this->createdAt = new DateTimeImmutable();
        $this->updatedAt = $this->createdAt;
    }

    /**
     * Rebuild this category's path from current slug + parent.
     * Called by repository when slug or parent changes.
     */
    public function refreshPath(): void
    {
        $this->path = $this->computePath();
    }

    private function computePath(): string
    {
        if ($this->parent === null) {
            return '/' . $this->slug;
        }

        $parentPath = $this->parent->getPath();
        // Defensive: if parent has empty/missing path, fall back to
        // root behaviour — better a sane fallback than a malformed path.
        if ($parentPath === '') {
            return '/' . $this->slug;
        }

        return rtrim($parentPath, '/') . '/' . $this->slug;
    }

    // -----------------------------------------------------------------
    // Accessors
    // -----------------------------------------------------------------

    public function getId(): ?int { return $this->id; }
    public function getParent(): ?Category { return $this->parent; }
    public function getSlug(): string { return $this->slug; }
    public function getName(): string { return $this->name; }
    public function getDescription(): ?string { return $this->description; }
    public function getDisplayOrder(): int { return $this->displayOrder; }
    public function getImageUrl(): ?string { return $this->imageUrl; }
    public function isActive(): bool { return $this->isActive; }
    public function getPath(): string { return $this->path; }

    // -----------------------------------------------------------------
    // Mutators
    // -----------------------------------------------------------------

    public function setSlug(string $slug): void
    {
        $this->slug = $slug;
        // Slug changes ripple through path. Caller must also
        // rebuildSubtreePaths() via the repository if this category
        // has descendants.
        $this->path = $this->computePath();
    }

    public function setName(string $name): void { $this->name = $name; }
    public function setDescription(?string $description): void { $this->description = $description; }
    public function setDisplayOrder(int $order): void { $this->displayOrder = $order; }
    public function setImageUrl(?string $url): void { $this->imageUrl = $url; }
    public function setActive(bool $active): void { $this->isActive = $active; }

    /**
     * Reparent this category. Path is rebuilt; descendants need
     * separate path refresh (caller's responsibility via repository).
     *
     * Self-parenting and cycle prevention live in the repository
     * (it has the visibility to walk ancestors).
     */
    public function setParent(?Category $parent): void
    {
        $this->parent = $parent;
        $this->path = $this->computePath();
    }
}
