<?php

declare(strict_types=1);

namespace Bayti\Api\Http\Controllers\GiftReminder\Dto;

use Symfony\Component\Validator\Constraints as Assert;
use Symfony\Component\Validator\Context\ExecutionContextInterface;

/**
 * Input for POST /v3/me/gift-reminders.
 *
 * remind_date is a strict YYYY-MM-DD that must be today or later (validated by
 * Assert attributes + a callback — the ctor only normalises, never throws).
 * budget_max is an optional non-negative decimal stored as a string (money is a
 * string throughout).
 */
final class CreateGiftReminderInput
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
            return; // NotBlank reports the empty case.
        }
        $date = \DateTimeImmutable::createFromFormat('!Y-m-d', $this->remind_date);
        if ($date === false) {
            return; // Assert\Date reports a bad format.
        }
        $today = new \DateTimeImmutable('today');
        if ($date < $today) {
            $context->buildViolation('remind_date must be today or a future date.')
                ->atPath('remind_date')
                ->addViolation();
        }
    }
}
