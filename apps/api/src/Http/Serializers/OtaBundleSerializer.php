<?php

declare(strict_types=1);

namespace Bayti\Api\Http\Serializers;

use Bayti\Api\Domain\Ota\OtaBundle;

/**
 * Public admin shape for an OTA bundle (the portal management UI). The raw
 * session_key is never exposed, only whether the bundle is signed.
 *
 * min_native_version / max_native_version are the native range the update
 * endpoint enforces. max_native_version is the EFFECTIVE upper bound, so it
 * is never null: a row published before the column existed (stored max NULL)
 * reports its min_native_version, exactly what devices are served by.
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
            'max_native_version' => $bundle->getEffectiveMaxNativeVersion(),
            'signed' => $bundle->getSessionKey() !== null,
            'notes' => $bundle->getNotes(),
            'is_active' => $bundle->isActive(),
            'rollback_of_version' => $bundle->getRollbackOfVersion(),
            'created_at' => $bundle->getCreatedAt()->format(\DateTimeInterface::ATOM),
        ];
    }
}
