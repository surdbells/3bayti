<?php

declare(strict_types=1);

namespace Bayti\Api\Http\Controllers\GiftReminder\Dto;

use Symfony\Component\Validator\Constraints as Assert;
use Symfony\Component\Validator\Context\ExecutionContextInterface;

/**
 * Input for PUT /v3/me/gift-reminders/{id} — full-replace semantics, same
 * fields + validation as {@see CreateGiftReminderInput}.
 */
final class UpdateGiftReminderInput
{
    #[Assert\NotBlank(message: 'recipient_name is required.')]
    #[Assert\Length(max: 200, maxMessage: 'recipient_name must not exceed 200 characters.')]
    public readonly string $recipient_name;

    #[Assert\NotBlank(message: 'occasion is required.')]
    #[Assert\Length(max: 100, maxMessage: 'occasion must not exceed 100 characters.')]
    public readonly string $occasion;

    #[Assert\NotBlank(message: 'remind_date is required.')]
    #[Assert\Date(message: 'remind_date must be a valid YYYY-MM-DD date.')]
    public readonly string $remind_date;

    #[Assert\Length(max: 2000, maxMessage: 'note must not exceed 2000 characters.')]
    public readonly ?string $note;

    #[Assert\Regex(pattern: '/^\d{1,8}(\.\d{1,2})?$/', message: 'budget_max must be a non-negative amount.')]
    public readonly ?string $budget_max;

    #[Assert\Length(max: 160, maxMessage: 'category_slug must not exceed 160 characters.')]
    public readonly ?string $category_slug;

    public function __construct(
        string $recipient_name = '',
        string $occasion = '',
        string $remind_date = '',
        ?string $note = null,
        int|float|string|null $budget_max = null,
        ?string $category_slug = null,
    ) {
        $this->recipient_name = trim($recipient_name);
        $this->occasion = trim($occasion);
        $this->remind_date = trim($remind_date);
        $this->note = $note !== null ? trim($note) : null;
        $this->budget_max = $budget_max !== null && $budget_max !== '' ? (string) $budget_max : null;
        $this->category_slug = $category_slug !== null ? trim($category_slug) : null;
    }

    #[Assert\Callback]
    public function validateFutureDate(ExecutionContextInterface $context): void
    {
        if ($this->remind_date === '') {
            return;
        }
        $date = \DateTimeImmutable::createFromFormat('!Y-m-d', $this->remind_date);
        if ($date === false) {
            return;
        }
        $today = new \DateTimeImmutable('today');
        if ($date < $today) {
            $context->buildViolation('remind_date must be today or a future date.')
                ->atPath('remind_date')
                ->addViolation();
        }
    }
}
