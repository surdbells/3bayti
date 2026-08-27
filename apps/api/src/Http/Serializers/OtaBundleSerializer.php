<?php

declare(strict_types=1);

namespace Bayti\Api\Http\Serializers;

use Bayti\Api\Domain\Ota\OtaBundle;

/**
 * Public admin shape for an OTA bundle (the portal management UI). The raw
 * session_key is never exposed, only whether the bundle is signed.
 */
final class OtaBundleSerializer
{
    /**
     * @return array<string, mixed>
     */
    public function shape(OtaBundle $bundle): array
    {
        return [
            'id' => $bundle->getId(),
            'app_id' => $bundle->getAppId(),
            'platform' => $bundle->getPlatform(),
            'channel' => $bundle->getChannel(),
            'version' => $bundle->getVersion(),
            'url' => $bundle->getUrl(),
            'checksum' => $bundle->getChecksum(),
            'min_native_version' => $bundle->getMinNativeVersion(),
            'signed' => $bundle->getSessionKey() !== null,
            'is_active' => $bundle->isActive(),
            'created_at' => $bundle->getCreatedAt()->format(\DateTimeInterface::ATOM),
        ];
    }
}
