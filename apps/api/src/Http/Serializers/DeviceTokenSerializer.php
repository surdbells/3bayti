<?php

declare(strict_types=1);

namespace Bayti\Api\Http\Serializers;

use Bayti\Api\Domain\Notification\DeviceToken;
use DateTimeInterface;

/**
 * Serialises a DeviceToken for the registration response.
 *
 * The full token string is NOT echoed back, the client already has
 * it, and not reflecting it avoids logging/leaking the secret in
 * response bodies. We return the row's metadata so the client can
 * confirm the registration took.
 */
final class DeviceTokenSerializer
{
    /**
     * @return array{
     *   id: int|null,
     *   platform: string,
     *   is_active: bool,
     *   created_at: string,
     *   updated_at: string,
     *   last_seen_at: string|null
     * }
     */
    public function publicShape(DeviceToken $t): array
    {
        return [
            'id' => $t->getId(),
            'platform' => $t->getPlatform(),
            'is_active' => $t->isActive(),
            'created_at' => $t->getCreatedAt()->format(DateTimeInterface::ATOM),
            'updated_at' => $t->getUpdatedAt()->format(DateTimeInterface::ATOM),
            'last_seen_at' => $t->getLastSeenAt()?->format(DateTimeInterface::ATOM),
        ];
    }
}
