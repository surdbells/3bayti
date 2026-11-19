<?php

declare(strict_types=1);

namespace Bayti\Api\Domain\Cashback;

use DateTimeImmutable;
use DateTimeZone;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;

/**
 * An admin-defined cashback promotion.
 *
 * A rule says "customers earn X (percent of the line, or a fixed amount once
 * per order) on items in this scope, inside this window, on this channel".
 * The reward is paid out later as a cashback gift card (see CashbackService).
 *
 * Scope + targets
 * ---------------
 *   scope=all         every item, minus any exclusion targets
 *   scope=stores      items whose vendor is an (inclusion) vendor target
 *   scope=products    items whose product is an (inclusion) product target
 *   scope=categories  items whose category (or an ancestor) is a target
 * Exclusion targets of ANY type apply in every scope ("all stores except X",
 * "this store except product Y"). Targets are v3 ids only.
 *
 * Setters validate their input (PromoCode pattern) and throw
 * InvalidArgumentException, which the admin controllers map to 422.
 */
#[ORM\Entity(repositoryClass: CashbackRuleRepository::class)]
#[ORM\Table(name: 'cashback_rules')]
#[ORM\HasLifecycleCallbacks]
class CashbackRule
{
    public const REWARD_PERCENTAGE = 'percentage';
    public const REWARD_FIXED = 'fixed';
    public const REWARD_TYPES = [self::REWARD_PERCENTAGE, self::REWARD_FIXED];

    public const SCOPE_ALL = 'all';
    public const SCOPE_STORES = 'stores';
    public const SCOPE_PRODUCTS = 'products';
    public const SCOPE_CATEGORIES = 'categories';
    public const SCOPES = [self::SCOPE_ALL, self::SCOPE_STORES, self::SCOPE_PRODUCTS, self::SCOPE_CATEGORIES];

    public const CHANNEL_ALL = 'all';
    public const CHANNEL_WEB = 'web';
    public const CHANNEL_MOBILE = 'mobile';
    public const CHANNELS = [self::CHANNEL_ALL, self::CHANNEL_WEB, self::CHANNEL_MOBILE];

    public const DEFAULT_HOLD_DAYS = 14;
    public const DEFAULT_VALIDITY_DAYS = 365;
    public const MAX_HOLD_DAYS = 365;
    public const MAX_VALIDITY_DAYS = 1825;

    #[ORM\Id]
    #[ORM\GeneratedValue(strategy: 'IDENTITY')]
    #[ORM\Column(type: 'bigint')]
    // @phpstan-ignore-next-line property.unusedType
    private ?int $id = null;

    #[ORM\Column(name: 'name', type: 'string', length: 120)]
    private string $name;

    #[ORM\Column(name: 'description', type: 'text', nullable: true)]
    private ?string $description = null;

    #[ORM\Column(name: 'is_active', type: 'boolean', options: ['default' => false])]
    private bool $isActive = false;

    #[ORM\Column(name: 'priority', type: 'integer', options: ['default' => 0])]
    private int $priority = 0;

    #[ORM\Column(name: 'reward_type', type: 'string', length: 16)]
    private string $rewardType;

    #[ORM\Column(name: 'reward_value', type: 'decimal', precision: 10, scale: 2)]
    private string $rewardValue;

    #[ORM\Column(name: 'max_reward_per_order', type: 'decimal', precision: 10, scale: 2, nullable: true)]
    private ?string $maxRewardPerOrder = null;

    #[ORM\Column(name: 'min_eligible_amount', type: 'decimal', precision: 10, scale: 2, nullable: true)]
    private ?string $minEligibleAmount = null;

    #[ORM\Column(name: 'valid_from', type: 'datetime_immutable', nullable: true)]
    private ?DateTimeImmutable $validFrom = null;

    #[ORM\Column(name: 'valid_until', type: 'datetime_immutable', nullable: true)]
    private ?DateTimeImmutable $validUntil = null;

    #[ORM\Column(name: 'scope', type: 'string', length: 16, options: ['default' => self::SCOPE_ALL])]
    private string $scope = self::SCOPE_ALL;

    #[ORM\Column(name: 'channel', type: 'string', length: 16, options: ['default' => self::CHANNEL_ALL])]
    private string $channel = self::CHANNEL_ALL;

    #[ORM\Column(name: 'first_order_only', type: 'boolean', options: ['default' => false])]
    private bool $firstOrderOnly = false;

    #[ORM\Column(name: 'per_customer_limit', type: 'integer', nullable: true)]
    private ?int $perCustomerLimit = null;

    #[ORM\Column(name: 'budget_total', type: 'decimal', precision: 12, scale: 2, nullable: true)]
    private ?string $budgetTotal = null;

    #[ORM\Column(name: 'allow_with_promo', type: 'boolean', options: ['default' => false])]
    private bool $allowWithPromo = false;

    #[ORM\Column(name: 'hold_days', type: 'integer', options: ['default' => self::DEFAULT_HOLD_DAYS])]
    private int $holdDays = self::DEFAULT_HOLD_DAYS;

    #[ORM\Column(name: 'gift_card_validity_days', type: 'integer', options: ['default' => self::DEFAULT_VALIDITY_DAYS])]
    private int $giftCardValidityDays = self::DEFAULT_VALIDITY_DAYS;

    /** Admin user id that created the rule (plain id; no FK cascade concerns). */
    #[ORM\Column(name: 'created_by', type: 'bigint', nullable: true)]
    private ?int $createdBy = null;

    #[ORM\Column(name: 'created_at', type: 'datetime_immutable')]
    private DateTimeImmutable $createdAt;

    #[ORM\Column(name: 'updated_at', type: 'datetime_immutable')]
    private DateTimeImmutable $updatedAt;

    /** @var Collection<int, CashbackRuleTarget> */
    #[ORM\OneToMany(targetEntity: CashbackRuleTarget::class, mappedBy: 'rule', cascade: ['persist', 'remove'], orphanRemoval: true)]
    #[ORM\OrderBy(['id' => 'ASC'])]
    private Collection $targets;

    public function __construct(string $name, string $rewardType, string $rewardValue)
    {
        $this->targets = new ArrayCollection();
        $this->setName($name);
        $this->setReward($rewardType, $rewardValue);
        $this->createdAt = new DateTimeImmutable('now', new DateTimeZone('UTC'));
        $this->updatedAt = $this->createdAt;
    }

    #[ORM\PreUpdate]
    public function onUpdate(): void
    {
        $this->updatedAt = new DateTimeImmutable('now', new DateTimeZone('UTC'));
    }

    // ── Setters (validating) ─────────────────────────────────────────

    public function setName(string $name): void
    {
        $name = trim($name);
        if ($name === '' || mb_strlen($name) > 120) {
            throw new \InvalidArgumentException('name must be 1-120 characters.');
        }
        $this->name = $name;
    }

    public function setDescription(?string $description): void
    {
        $description = $description !== null ? trim($description) : null;
        $this->description = $description === '' ? null : $description;
    }

    public function setActive(bool $active): void
    {
        $this->isActive = $active;
    }

    public function setPriority(int $priority): void
    {
        if ($priority < -1000 || $priority > 1000) {
            throw new \InvalidArgumentException('priority must be between -1000 and 1000.');
        }
        $this->priority = $priority;
    }

    public function setReward(string $type, string $value): void
    {
        if (!in_array($type, self::REWARD_TYPES, true)) {
            throw new \InvalidArgumentException('reward_type must be one of: ' . implode(', ', self::REWARD_TYPES) . '.');
        }
        self::assertMoney($value, 'reward_value');
        if (bccomp($value, '0.00', 2) <= 0) {
            throw new \InvalidArgumentException('reward_value must be greater than 0.');
        }
        if ($type === self::REWARD_PERCENTAGE && bccomp($value, '100.00', 2) > 0) {
            throw new \InvalidArgumentException('A percentage reward_value cannot exceed 100.');
        }
        $this->rewardType = $type;
        $this->rewardValue = bcadd($value, '0', 2);
    }

    public function setMaxRewardPerOrder(?string $value): void
    {
        $this->maxRewardPerOrder = self::optionalPositiveMoney($value, 'max_reward_per_order');
    }

    public function setMinEligibleAmount(?string $value): void
    {
        if ($value === null) {
            $this->minEligibleAmount = null;
            return;
        }
        self::assertMoney($value, 'min_eligible_amount');
        $this->minEligibleAmount = bcadd($value, '0', 2);
    }

    public function setValidity(?DateTimeImmutable $from, ?DateTimeImmutable $until): void
    {
        if ($from !== null && $until !== null && $from > $until) {
            throw new \InvalidArgumentException('valid_until must be on or after valid_from.');
        }
        $this->validFrom = $from;
        $this->validUntil = $until;
    }

    public function setScope(string $scope): void
    {
        if (!in_array($scope, self::SCOPES, true)) {
            throw new \InvalidArgumentException('scope must be one of: ' . implode(', ', self::SCOPES) . '.');
        }
        $this->scope = $scope;
    }

    public function setChannel(string $channel): void
    {
        if (!in_array($channel, self::CHANNELS, true)) {
            throw new \InvalidArgumentException('channel must be one of: ' . implode(', ', self::CHANNELS) . '.');
        }
        $this->channel = $channel;
    }

    public function setFirstOrderOnly(bool $v): void
    {
        $this->firstOrderOnly = $v;
    }

    public function setPerCustomerLimit(?int $limit): void
    {
        if ($limit !== null && $limit < 1) {
            throw new \InvalidArgumentException('per_customer_limit must be at least 1 (or null for unlimited).');
        }
        $this->perCustomerLimit = $limit;
    }

    public function setBudgetTotal(?string $value): void
    {
        $this->budgetTotal = self::optionalPositiveMoney($value, 'budget_total');
    }

    public function setAllowWithPromo(bool $v): void
    {
        $this->allowWithPromo = $v;
    }

    public function setHoldDays(int $days): void
    {
        if ($days < 0 || $days > self::MAX_HOLD_DAYS) {
            throw new \InvalidArgumentException('hold_days must be between 0 and ' . self::MAX_HOLD_DAYS . '.');
        }
        $this->holdDays = $days;
    }

    public function setGiftCardValidityDays(int $days): void
    {
        if ($days < 1 || $days > self::MAX_VALIDITY_DAYS) {
            throw new \InvalidArgumentException('gift_card_validity_days must be between 1 and ' . self::MAX_VALIDITY_DAYS . '.');
        }
        $this->giftCardValidityDays = $days;
    }

    public function setCreatedBy(?int $userId): void
    {
        $this->createdBy = $userId;
    }

    /**
     * Replace every target with the given set.
     *
     * @param list<array{target_type: string, target_id: int, is_exclusion: bool}> $targets
     */
    public function replaceTargets(array $targets): void
    {
        $wanted = [];
        foreach ($targets as $t) {
            $wanted[$t['target_type'] . ':' . $t['target_id'] . ':' . ($t['is_exclusion'] ? '1' : '0')] = $t;
        }
        // Keep rows that stay (Doctrine runs INSERTs before orphan DELETEs,
        // so re-adding an identical target would hit the unique index).
        foreach ($this->targets->toArray() as $existing) {
            $key = $existing->getTargetType() . ':' . $existing->getTargetId() . ':' . ($existing->isExclusion() ? '1' : '0');
            if (isset($wanted[$key])) {
                unset($wanted[$key]);
            } else {
                $this->targets->removeElement($existing);
            }
        }
        foreach ($wanted as $t) {
            $this->targets->add(new CashbackRuleTarget($this, $t['target_type'], $t['target_id'], $t['is_exclusion']));
        }
    }

    /** Test helper / programmatic add. */
    public function addTarget(string $type, int $targetId, bool $isExclusion = false): void
    {
        $this->targets->add(new CashbackRuleTarget($this, $type, $targetId, $isExclusion));
    }

    // ── Queries ──────────────────────────────────────────────────────

    /** Active AND inside its date window at $at. */
    public function isLiveAt(DateTimeImmutable $at): bool
    {
        if (!$this->isActive) {
            return false;
        }
        if ($this->validFrom !== null && $at < $this->validFrom) {
            return false;
        }
        if ($this->validUntil !== null && $at > $this->validUntil) {
            return false;
        }
        return true;
    }

    /**
     * Does the rule apply on the order's checkout channel? Orders store
     * 'WEB' / 'MOBILE' (or null for untracked); a null channel only matches
     * channel=all rules.
     */
    public function appliesToChannel(?string $orderChannel): bool
    {
        if ($this->channel === self::CHANNEL_ALL) {
            return true;
        }
        if ($orderChannel === null) {
            return false;
        }
        return strtolower($orderChannel) === $this->channel;
    }

    /**
     * Does this rule cover an item with the given vendor/product/categories?
     *
     * @param list<int> $categoryIds the item's category and its ancestors
     */
    public function covers(?int $vendorId, ?int $productId, array $categoryIds): bool
    {
        $include = [CashbackRuleTarget::TYPE_VENDOR => [], CashbackRuleTarget::TYPE_PRODUCT => [], CashbackRuleTarget::TYPE_CATEGORY => []];
        $exclude = $include;
        foreach ($this->targets as $t) {
            if ($t->isExclusion()) {
                $exclude[$t->getTargetType()][$t->getTargetId()] = true;
            } else {
                $include[$t->getTargetType()][$t->getTargetId()] = true;
            }
        }

        // Exclusions win in every scope.
        if ($vendorId !== null && isset($exclude[CashbackRuleTarget::TYPE_VENDOR][$vendorId])) {
            return false;
        }
        if ($productId !== null && isset($exclude[CashbackRuleTarget::TYPE_PRODUCT][$productId])) {
            return false;
        }
        foreach ($categoryIds as $cid) {
            if (isset($exclude[CashbackRuleTarget::TYPE_CATEGORY][$cid])) {
                return false;
            }
        }

        return match ($this->scope) {
            self::SCOPE_ALL => true,
            self::SCOPE_STORES => $vendorId !== null && isset($include[CashbackRuleTarget::TYPE_VENDOR][$vendorId]),
            self::SCOPE_PRODUCTS => $productId !== null && isset($include[CashbackRuleTarget::TYPE_PRODUCT][$productId]),
            self::SCOPE_CATEGORIES => (static function () use ($categoryIds, $include): bool {
                foreach ($categoryIds as $cid) {
                    if (isset($include[CashbackRuleTarget::TYPE_CATEGORY][$cid])) {
                        return true;
                    }
                }
                return false;
            })(),
            default => false,
        };
    }

    // ── Accessors ────────────────────────────────────────────────────

    public function getId(): ?int { return $this->id; }
    public function getName(): string { return $this->name; }
    public function getDescription(): ?string { return $this->description; }
    public function isActive(): bool { return $this->isActive; }
    public function getPriority(): int { return $this->priority; }
    public function getRewardType(): string { return $this->rewardType; }
    public function getRewardValue(): string { return $this->rewardValue; }
    public function getMaxRewardPerOrder(): ?string { return $this->maxRewardPerOrder; }
    public function getMinEligibleAmount(): ?string { return $this->minEligibleAmount; }
    public function getValidFrom(): ?DateTimeImmutable { return $this->validFrom; }
    public function getValidUntil(): ?DateTimeImmutable { return $this->validUntil; }
    public function getScope(): string { return $this->scope; }
    public function getChannel(): string { return $this->channel; }
    public function isFirstOrderOnly(): bool { return $this->firstOrderOnly; }
    public function getPerCustomerLimit(): ?int { return $this->perCustomerLimit; }
    public function getBudgetTotal(): ?string { return $this->budgetTotal; }
    public function allowsWithPromo(): bool { return $this->allowWithPromo; }
    public function getHoldDays(): int { return $this->holdDays; }
    public function getGiftCardValidityDays(): int { return $this->giftCardValidityDays; }
    public function getCreatedBy(): ?int { return $this->createdBy; }
    public function getCreatedAt(): DateTimeImmutable { return $this->createdAt; }
    public function getUpdatedAt(): DateTimeImmutable { return $this->updatedAt; }

    /** @return Collection<int, CashbackRuleTarget> */
    public function getTargets(): Collection { return $this->targets; }

    // ── Helpers ──────────────────────────────────────────────────────

    private static function assertMoney(string $v, string $field): void
    {
        if (!preg_match('/^\d{1,10}(\.\d{1,2})?$/', $v)) {
            throw new \InvalidArgumentException("{$field} must be a non-negative decimal string with up to 2 decimals, got '{$v}'.");
        }
    }

    private static function optionalPositiveMoney(?string $value, string $field): ?string
    {
        if ($value === null) {
            return null;
        }
        self::assertMoney($value, $field);
        if (bccomp($value, '0.00', 2) <= 0) {
            throw new \InvalidArgumentException("{$field} must be greater than 0 (or null for none).");
        }
        return bcadd($value, '0', 2);
    }
}
