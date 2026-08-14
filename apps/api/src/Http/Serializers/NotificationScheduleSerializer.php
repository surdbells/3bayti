<?php

declare(strict_types=1);

namespace Bayti\Api\Http\Serializers;

use Bayti\Api\Domain\Notification\NotificationSchedule;
use DateTimeInterface;

final class NotificationScheduleSerializer
{
    /**
     * @param array<int, string> $userNames
     * @return array<string, mixed>
     */
    public function shape(NotificationSchedule $s, array $userNames = []): array
    {
        $by = $s->getCreatedByUserId();
        return [
            'id' => $s->getId(),
            'name' => $s->getName(),
            'title' => $s->getTitle(),
            'body' => $s->getBody(),
            'image_url' => $s->getImageUrl(),
            'deep_link' => $s->getDeepLink(),
            'data' => $s->getData(),
            'template_id' => $s->getTemplateId(),
            'audience' => $s->getAudience(),
            'audience_label' => $this->audienceLabel($s->getAudience()),
            'audience_mode' => $s->getAudienceMode(),
            'timezone' => $s->getTimezone(),
            'frequency' => $s->getFrequency(),
            'start_at' => $s->getStartAt()->format(DateTimeInterface::ATOM),
            'end_at' => $s->getEndAt()?->format(DateTimeInterface::ATOM),
            'next_run_at' => $s->getNextRunAt()?->format(DateTimeInterface::ATOM),
            'last_run_at' => $s->getLastRunAt()?->format(DateTimeInterface::ATOM),
            'status' => $s->getStatus(),
            'editable' => $s->isEditable(),
            'created_by_user_id' => $by,
            'created_by_name' => $by !== null ? ($userNames[$by] ?? null) : null,
            'created_at' => $s->getCreatedAt()->format(DateTimeInterface::ATOM),
            'updated_at' => $s->getUpdatedAt()->format(DateTimeInterface::ATOM),
        ];
    }

    /**
     * @param iterable<NotificationSchedule> $schedules
     * @param array<int, string> $userNames
     * @return list<array<string, mixed>>
     */
    public function shapeMany(iterable $schedules, array $userNames = []): array
    {
        $out = [];
        foreach ($schedules as $s) {
            $out[] = $this->shape($s, $userNames);
        }
        return $out;
    }

    /** @param array<string, mixed> $audience */
    private function audienceLabel(array $audience): string
    {
        return match ((string) ($audience['type'] ?? 'all')) {
            'customers' => 'Customers',
            'vendors' => 'Vendors',
            'admins' => 'Admins',
            default => 'Everyone',
        };
    }
}
