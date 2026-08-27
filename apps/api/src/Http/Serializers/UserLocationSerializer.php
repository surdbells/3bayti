<?php

declare(strict_types=1);

namespace Bayti\Api\Http\Serializers;

use Bayti\Api\Domain\User\UserLocation;

/**
 * Convert UserLocation entities into public response shapes.
 *
 * Why this lives here and not on the entity
 * -----------------------------------------
 * Same rationale as UserSerializer: HTTP response shapes are HTTP
 * concerns. Different endpoints may want different views of a
 * location (e.g. admin view including IP address from capture, public
 * view excluding precision details). The serializer is the place
 * for that.
 *
 * Coordinate type in JSON
 * -----------------------
 * Doctrine stores latitude/longitude as DECIMAL(9,6), which PHP
 * surfaces as strings (to avoid float-precision drift). For JSON
 * output we convert to float, clients want numbers, not strings,
 * to feed into map/geocoding libraries.
 *
 * The conversion is safe because the column scale (6) is well within
 * float's representable range. If a future need surfaces for
 * arbitrary-precision arithmetic (e.g. financial geofencing), we'd
 * keep the string in the response and document it.
 */
final class UserLocationSerializer
{
    /**
     * Public self-view, what the user sees about their own location.
     *
     * @return array<string, mixed>
     */
    public function publicShape(UserLocation $location): array
    {
        return [
            'latitude' => $location->getLatitude() !== null
                ? (float) $location->getLatitude()
                : null,
            'longitude' => $location->getLongitude() !== null
                ? (float) $location->getLongitude()
                : null,
            'city' => $location->getCity(),
            'country_code' => $location->getCountryCode(),
            'permission_granted' => $location->isPermissionGranted(),
            'last_captured_at' => $location->getLastCapturedAt()?->format(\DateTimeInterface::ATOM),
            'updated_at' => $location->getUpdatedAt()->format(\DateTimeInterface::ATOM),
        ];
    }
}
