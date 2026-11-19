<?php

declare(strict_types=1);

namespace Bayti\Api\Domain\Cashback;

use Doctrine\ORM\Mapping as ORM;

/**
 * One scope target of a cashback rule: a vendor (store), product or category,
 * by v3 id. is_exclusion=true carves the target OUT of the rule's scope.
 */
#[ORM\Entity(repositoryClass: CashbackRuleTargetRepository::class)]
#[ORM\Table(name: 'cashback_rule_targets')]
class CashbackRuleTarget
{
    public const TYPE_VENDOR = 'vendor';
    public const TYPE_PRODUCT = 'product';
    public const TYPE_CATEGORY = 'category';
    public const TYPES = [self::TYPE_VENDOR, self::TYPE_PRODUCT, self::TYPE_CATEGORY];

    #[ORM\Id]
    #[ORM\GeneratedValue(strategy: 'IDENTITY')]
    #[ORM\Column(type: 'bigint')]
    // @phpstan-ignore-next-line property.unusedType
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: CashbackRule::class, inversedBy: 'targets')]
    #[ORM\JoinColumn(name: 'rule_id', referencedColumnName: 'id', nullable: false, onDelete: 'CASCADE')]
    private CashbackRule $rule;

    #[ORM\Column(name: 'target_type', type: 'string', length: 16)]
    private string $targetType;

    #[ORM\Column(name: 'target_id', type: 'bigint')]
    private int $targetId;

    #[ORM\Column(name: 'is_exclusion', type: 'boolean', options: ['default' => false])]
    private bool $isExclusion;

    public function __construct(CashbackRule $rule, string $targetType, int $targetId, bool $isExclusion = false)
    {
        if (!in_array($targetType, self::TYPES, true)) {
            throw new \InvalidArgumentException('target_type must be one of: ' . implode(', ', self::TYPES) . '.');
        }
        if ($targetId < 1) {
            throw new \InvalidArgumentException('target_id must be a positive v3 id.');
        }
        $this->rule = $rule;
        $this->targetType = $targetType;
        $this->targetId = $targetId;
        $this->isExclusion = $isExclusion;
    }

    public function getId(): ?int { return $this->id; }
    public function getRule(): CashbackRule { return $this->rule; }
    public function getTargetType(): string { return $this->targetType; }
    public function getTargetId(): int { return (int) $this->targetId; }
    public function isExclusion(): bool { return $this->isExclusion; }
}
