<?php

declare(strict_types=1);

namespace Bayti\Api\Http\Serializers;

use Bayti\Api\Domain\GiftReminder\GiftReminder;

/**
 * Public API shape for gift reminders. v3-native — no legacy ids.
 */
final class GiftReminderSerializer
{
    /**
     * @return array<string, mixed>
     */
    public function publicShape(GiftReminder $r): array
    {
        return [
            'id' => $r->getId(),
            'recipient_name' => $r->getRecipientName(),
            'occasion' => $r->getOccasion(),
            'remind_date' => $r->getRemindDate()->format('Y-m-d'),
            'note' => $r->getNote(),
            'budget_max' => $r->getBudgetMax(),
            'category_slug' => $r->getCategorySlug(),
            'last_notified_stage' => $r->getLastNotifiedStage(),
            'created_at' => $r->getCreatedAt()->format(\DateTimeInterface::ATOM),
            'updated_at' => $r->getUpdatedAt()->format(\DateTimeInterface::ATOM),
        ];
    }

    /**
     * @param iterable<GiftReminder> $reminders
     * @return list<array<string, mixed>>
     */
    public function publicShapeMany(iterable $reminders): array
    {
        $out = [];
        foreach ($reminders as $r) {
            $out[] = $this->publicShape($r);
        }
        return $out;
    }
}
