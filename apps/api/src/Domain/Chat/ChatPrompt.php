<?php

declare(strict_types=1);

namespace Bayti\Api\Domain\Chat;

use Doctrine\ORM\Mapping as ORM;

/**
 * A single quick-start chat prompt (P1) — a canned customer question or a
 * canned vendor reply, tapped to send. Bilingual via paired text/text_ar.
 * When tapped, a prompt becomes a Message of TYPE_PROMPT whose content is a
 * snapshot of this text (so the message survives the prompt being edited or
 * deactivated); `prompt_id` on the message links back here for analytics.
 */
#[ORM\Entity(repositoryClass: ChatPromptRepository::class)]
#[ORM\Table(name: 'chat_prompts')]
#[ORM\Index(name: 'idx_chat_prompt_category', columns: ['category_id', 'is_active', 'sort_order'])]
class ChatPrompt
{
    #[ORM\Id]
    #[ORM\GeneratedValue(strategy: 'IDENTITY')]
    #[ORM\Column(type: 'bigint')]
    // @phpstan-ignore-next-line property.unusedType
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: ChatPromptCategory::class, inversedBy: 'prompts')]
    #[ORM\JoinColumn(name: 'category_id', referencedColumnName: 'id', nullable: false, onDelete: 'CASCADE')]
    private ChatPromptCategory $category;

    #[ORM\Column(type: 'string', length: 96, unique: true)]
    private string $slug;

    #[ORM\Column(type: 'string', length: 400)]
    private string $text;

    #[ORM\Column(name: 'text_ar', type: 'string', length: 400)]
    private string $textAr;

    #[ORM\Column(name: 'sort_order', type: 'integer', options: ['default' => 0])]
    private int $sortOrder = 0;

    #[ORM\Column(name: 'is_active', type: 'boolean', options: ['default' => true])]
    private bool $isActive = true;

    public function __construct(
        ChatPromptCategory $category,
        string $slug,
        string $text,
        string $textAr,
        int $sortOrder = 0,
    ) {
        $this->category = $category;
        $this->slug = $slug;
        $this->text = $text;
        $this->textAr = $textAr;
        $this->sortOrder = $sortOrder;
    }

    public function getId(): ?int { return $this->id; }
    public function getCategory(): ChatPromptCategory { return $this->category; }
    public function getSlug(): string { return $this->slug; }
    public function getText(): string { return $this->text; }
    public function getTextAr(): string { return $this->textAr; }
    public function getSortOrder(): int { return $this->sortOrder; }
    public function isActive(): bool { return $this->isActive; }
}
