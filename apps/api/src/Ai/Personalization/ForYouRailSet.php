<?php

declare(strict_types=1);

namespace Bayti\Api\Ai\Personalization;

/**
 * The full For-You response: whether a genuine personalised profile backed the
 * rails (`profileReady=false` means the cold "popular" fallback was used, so the
 * client can title it differently) plus the ordered rails.
 */
final class ForYouRailSet
{
    /**
     * @param list<ForYouRail> $rails
     */
    public function __construct(
        public readonly bool $profileReady,
        public readonly array $rails,
    ) {
    }
}
