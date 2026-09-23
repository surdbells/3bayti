<?php

declare(strict_types=1);

namespace Bayti\Api\Http\Controllers\Customization;

use Bayti\Api\Domain\Customization\CustomizationRequest;

/**
 * Parse + clamp the shared list query params (status / limit / offset) for
 * the customer + vendor customization-request list endpoints. An unknown
 * status is dropped rather than erroring (returns an unfiltered list).
 */
final class CustomizationQueryParams
{
    private const DEFAULT_LIMIT = 20;
    private const MAX_LIMIT = 100;

    /**
     * @param array<string, mixed> $query
     * @return array{status?: string, limit: int, offset: int}
     */
    public static function fromRequest(array $query): array
    {
        $filters = [
            'limit' => self::DEFAULT_LIMIT,
            'offset' => 0,
        ];

        $status = $query['status'] ?? null;
        if (is_string($status) && in_array($status, CustomizationRequest::ALL_STATUSES, true)) {
            $filters['status'] = $status;
        }

        $limit = isset($query['limit']) ? (int) $query['limit'] : self::DEFAULT_LIMIT;
        $filters['limit'] = max(1, min(self::MAX_LIMIT, $limit));

        $offset = isset($query['offset']) ? (int) $query['offset'] : 0;
        $filters['offset'] = max(0, $offset);

        return $filters;
    }
}
