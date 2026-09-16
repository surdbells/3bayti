<?php

declare(strict_types=1);

namespace Bayti\Api\Http\Serializers;

use Bayti\Api\Ai\Personalization\ForYouRail;
use Bayti\Api\Ai\Personalization\ForYouRailSet;

/**
 * Serialize the personalised For-You rails for GET /v3/me/ai/for-you.
 *
 * Each rail carries a stable machine `key` (the client maps it to a localised
 * heading) and full product cards via ProductSerializer::listShape. `seed_name`
 * is present only on the "because you liked…" rail. `profile_ready` is false
 * when the popular cold-start fallback was used.
 */
final class ForYouSerializer
{
    public function __construct(
        private readonly ProductSerializer $productSerializer,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function shape(ForYouRailSet $set): array
    {
        return [
            'profile_ready' => $set->profileReady,
            'rails' => array_map(fn (ForYouRail $rail): array => $this->railShape($rail), $set->rails),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function railShape(ForYouRail $rail): array
    {
        $row = [
            'key' => $rail->key,
            'products' => $this->productSerializer->listShapeMany($rail->products),
        ];
        if ($rail->seedName !== null) {
            $row['seed_name'] = $rail->seedName;
        }
        return $row;
    }
}
