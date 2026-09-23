<?php

declare(strict_types=1);

namespace Bayti\Api\Domain\Chat;

use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;

/**
 * A group of quick-start chat prompts (P1), shown as a section in the
 * prompt-picker. Bilingual via paired label/label_ar columns (the house
 * pattern). `audience` separates buyer-facing question categories from
 * seller-facing reply-template categories. Admin-manageable; the starter
 * set is seeded from PromptCatalog.
 */
#[ORM\Entity(repositoryClass: ChatPromptCategoryRepository::class)]
#[ORM\Table(name: 'chat_prompt_categories')]
#[ORM\Index(name: 'idx_chat_prompt_cat_audience', columns: ['audience', 'is_active', 'sort_order'])]
class ChatPromptCategory
{
    #[ORM\Id]
    #[ORM\GeneratedValue(strategy: 'IDENTITY')]
    #[ORM\Column(type: 'bigint')]
    // @phpstan-ignore-next-line property.unusedType
    private ?int $id = null;

    #[ORM\Column(type: 'string', length: 64, unique: true)]
    private string $slug;

    /** 'customer' | 'vendor'. */
    #[ORM\Column(type: 'string', length: 16)]
    private string $audience;

    #[ORM\Column(type: 'string', length: 120)]
    private string $label;

    #[ORM\Column(name: 'label_ar', type: 'string', length: 120)]
    private string $labelAr;

    #[ORM\Column(type: 'string', length: 64, nullable: true)]
    private ?string $icon = null;

    #[ORM\Column(name: 'sort_order', type: 'integer', options: ['default' => 0])]
    private int $sortOrder = 0;

    #[ORM\Column(name: 'is_active', type: 'boolean', options: ['default' => true])]
    private bool $isActive = true;

    /** @var Collection<int, ChatPrompt> */
    #[ORM\OneToMany(targetEntity: ChatPrompt::class, mappedBy: 'category')]
    #[ORM\OrderBy(['sortOrder' => 'ASC', 'id' => 'ASC'])]
    private Collection $prompts;

    public function __construct(
        string $slug,
        string $audience,
        string $label,
        string $labelAr,
        ?string $icon = null,
        int $sortOrder = 0,
    ) {
        $this->slug = $slug;
        $this->audience = $audience;
        $this->label = $label;
        $this->labelAr = $labelAr;
        $this->icon = $icon;
        $this->sortOrder = $sortOrder;
        $this->prompts = new ArrayCollection();
    }

    public function getId(): ?int { return $this->id; }
    public function getSlug(): string { return $this->slug; }
    public function getAudience(): string { return $this->audience; }
    public function getLabel(): string { return $this->label; }
    public function getLabelAr(): string { return $this->labelAr; }
    public function getIcon(): ?string { return $this->icon; }
    public function getSortOrder(): int { return $this->sortOrder; }
    public function isActive(): bool { return $this->isActive; }
    /** @return Collection<int, ChatPrompt> */
    public function getPrompts(): Collection { return $this->prompts; }
}
