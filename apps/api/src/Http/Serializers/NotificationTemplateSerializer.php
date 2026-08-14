<?php

declare(strict_types=1);

namespace Bayti\Api\Http\Serializers;

use Bayti\Api\Domain\Notification\NotificationTemplate;
use DateTimeInterface;

final class NotificationTemplateSerializer
{
    /**
     * @param array<int, string> $userNames
     * @return array<string, mixed>
     */
    public function shape(NotificationTemplate $t, array $userNames = []): array
    {
        $by = $t->getCreatedByUserId();
        return [
            'id' => $t->getId(),
            'name' => $t->getName(),
            'title' => $t->getTitle(),
            'body' => $t->getBody(),
            'image_url' => $t->getImageUrl(),
            'deep_link' => $t->getDeepLink(),
            'status' => $t->getStatus(),
            'created_by_user_id' => $by,
            'created_by_name' => $by !== null ? ($userNames[$by] ?? null) : null,
            'created_at' => $t->getCreatedAt()->format(DateTimeInterface::ATOM),
            'updated_at' => $t->getUpdatedAt()->format(DateTimeInterface::ATOM),
        ];
    }

    /**
     * @param iterable<NotificationTemplate> $templates
     * @param array<int, string> $userNames
     * @return list<array<string, mixed>>
     */
    public function shapeMany(iterable $templates, array $userNames = []): array
    {
        $out = [];
        foreach ($templates as $t) {
            $out[] = $this->shape($t, $userNames);
        }
        return $out;
    }
}
