<?php

declare(strict_types=1);

namespace Bayti\Api\Domain\Catalog;

use Doctrine\ORM\Mapping as ORM;

/**
 * A single product within a Campaign, carrying campaign-scoped pricing
 * and stock.
 *
 *   - discountPercent: optional per-item override of the campaign's
 *     default discount. Null → use the campaign default.
 *   - stockTotal / stockRemaining: campaign-scoped allocation, used to
 *     render flash-sale "X left" stock bars. Both null → no stock bar
 *     (an unlimited-stock deal). stockRemaining is admin-managed for now;
 *     live decrement-on-purchase is a tracked follow-up.
 */
#[ORM\Entity(repositoryClass: CampaignItemRepository::class)]
#[ORM\Table(name: 'campaign_items')]
#[ORM\UniqueConstraint(name: 'uniq_campaign_product', columns: ['campaign_id', 'product_id'])]
#[ORM\Index(name: 'idx_campaign_items_campaign', columns: ['campaign_id'])]
class CampaignItem
{
    #[ORM\Id]
    #[ORM\GeneratedValue(strategy: 'IDENTITY')]
    #[ORM\Column(type: 'bigint')]
    /** @phpstan-ignore-next-line */
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: Campaign::class, inversedBy: 'items')]
    #[ORM\JoinColumn(name: 'campaign_id', referencedColumnName: 'id', nullable: false, onDelete: 'CASCADE')]
    private Campaign $campaign;

    #[ORM\ManyToOne(targetEntity: Product::class)]
    #[ORM\JoinColumn(name: 'product_id', referencedColumnName: 'id', nullable: false, onDelete: 'CASCADE')]
    private Product $product;

    /** Per-item discount override (0-100); null → use the campaign default. */
    #[ORM\Column(name: 'discount_percent', type: 'smallint', nullable: true)]
    private ?int $discountPercent = null;

    #[ORM\Column(name: 'stock_total', type: 'integer', nullable: true)]
    private ?int $stockTotal = null;

    #[ORM\Column(name: 'stock_remaining', type: 'integer', nullable: true)]
    private ?int $stockRemaining = null;

    #[ORM\Column(name: 'sort_order', type: 'smallint', nullable: true)]
    private ?int $sortOrder = null;

    public function __construct(Campaign $campaign, Product $product)
    {
        $this->campaign = $campaign;
        $this->product  = $product;
    }

    /**
     * Effective discount for this item: the per-item override if set,
     * otherwise the campaign-wide default. Clamped to 0-100.
     */
    public function effectiveDiscountPercent(): int
    {
        $pct = $this->discountPercent ?? $this->campaign->getDiscountPercent();
        return max(0, min(100, $pct));
    }

    public function getId(): ?int                { return $this->id; }
    public function getCampaign(): Campaign      { return $this->campaign; }
    public function getProduct(): Product        { return $this->product; }
    public function getDiscountPercent(): ?int   { return $this->discountPercent; }
    public function getStockTotal(): ?int        { return $this->stockTotal; }
    public function getStockRemaining(): ?int    { return $this->stockRemaining; }
    public function getSortOrder(): ?int         { return $this->sortOrder; }

    public function setCampaign(Campaign $campaign): void { $this->campaign = $campaign; }
    public function setProduct(Product $product): void    { $this->product = $product; }
    public function setDiscountPercent(?int $pct): void   { $this->discountPercent = $pct !== null ? max(0, min(100, $pct)) : null; }
    public function setStockTotal(?int $n): void          { $this->stockTotal = $n !== null ? max(0, $n) : null; }
    public function setStockRemaining(?int $n): void      { $this->stockRemaining = $n !== null ? max(0, $n) : null; }
    public function setSortOrder(?int $n): void           { $this->sortOrder = $n; }
}
