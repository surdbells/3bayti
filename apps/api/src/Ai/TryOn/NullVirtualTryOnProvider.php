<?php

declare(strict_types=1);

namespace Bayti\Api\Ai\TryOn;

/**
 * Disabled default. Guarantees the container boots without any try-on config
 * and that Virtual Try-On degrades to "unavailable" when TRYON_ENABLED is off.
 * The start endpoint checks {@see self::isEnabled()} and refuses up-front, so
 * this generate() is only ever a safety net.
 */
final class NullVirtualTryOnProvider implements VirtualTryOnProviderInterface
{
    public function isEnabled(): bool
    {
        return false;
    }

    public function generate(
        string $personBytes,
        string $personMime,
        string $garmentBytes,
        string $garmentMime,
        string $garmentPrompt,
    ): VirtualTryOnResult {
        return VirtualTryOnResult::empty();
    }
}
