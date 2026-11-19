<?php

declare(strict_types=1);

namespace Bayti\Api\Domain\Cashback;

use Doctrine\ORM\Mapping as ORM;

/**
 * One rule's contribution to an award, per order item. Feeds the rule budget,
 * the per-customer limit and per-rule reports. `amount` is kept in sync with
 * the award's release-time adjustment, so budget spend reflects what was
 * actually paid out.
 */
#[ORM\Entity(repositoryClass: CashbackAwardLineRepository::class)]
#[ORM\Table(name: 'cashback_award_lines')]
class CashbackAwardLine
{
    #[ORM\Id]
    #[ORM\GeneratedValue(strategy: 'IDENTITY')]
    #[ORM\Column(type: 'bigint')]
    // @phpstan-ignore-next-line property.unusedType
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: CashbackAward::class, inversedBy: 'lines')]
    #[ORM\JoinColumn(name: 'award_id', referencedColumnName: 'id', nullable: false, onDelete: 'CASCADE')]
    private CashbackAward $award;

    #[ORM\ManyToOne(targetEntity: CashbackRule::class)]
    #[ORM\JoinColumn(name: 'rule_id', referencedColumnName: 'id', nullable: false, onDelete: 'RESTRICT')]
    private CashbackRule $rule;

    /** v3 order_items.id this line was earned on (plain id). */
    #[ORM\Column(name: 'order_item_id', type: 'bigint', nullable: true)]
    private ?int $orderItemId;

    #[ORM\Column(name: 'base_amount', type: 'decimal', precision: 10, scale: 2)]
    private string $baseAmount;

    #[ORM\Column(name: 'amount', type: 'decimal', precision: 10, scale: 2)]
    private string $amount;

    public function __construct(CashbackAward $award, CashbackRule $rule, ?int $orderItemId, string $baseAmount, string $amount)
    {
        $this->award = $award;
        $this->rule = $rule;
        $this->orderItemId = $orderItemId;
        $this->baseAmount = bcadd($baseAmount, '0', 2);
        $this->amount = bcadd($amount, '0', 2);
    }

    public function setAmount(string $amount): void
    {
        $this->amount = bcadd($amount, '0', 2);
    }

    public function getId(): ?int { return $this->id; }
    public function getAward(): CashbackAward { return $this->award; }
    public function getRule(): CashbackRule { return $this->rule; }
    public function getOrderItemId(): ?int { return $this->orderItemId !== null ? (int) $this->orderItemId : null; }
    public function getBaseAmount(): string { return $this->baseAmount; }
    public function getAmount(): string { return $this->amount; }
}
