<?php

declare(strict_types=1);

namespace Bayti\Api\Http\Controllers\Admin\PromoCode;

use Bayti\Api\Domain\Audit\AuditEmitter;
use Bayti\Api\Domain\Promo\PromoCode;
use Bayti\Api\Domain\Promo\PromoCodeRepository;
use Bayti\Api\Domain\User\User;
use Bayti\Api\Http\Errors\ErrorCodes;
use Bayti\Api\Http\Errors\HttpException;
use Bayti\Api\Http\Middleware\AuthMiddleware;
use Bayti\Api\Http\PaginatedEnvelope;
use Bayti\Api\Http\Responder;
use Bayti\Api\Http\Serializers\PromoCodeSerializer;
use DateTimeImmutable;
use DateTimeZone;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * GET /v3/admin/promo-codes
 *
 * Paginated list with filters. Mirrors the ListNotificationLogs
 * pattern from M3.2.X.4-C.
 *
 * Query parameters:
 *   ?is_active=true|false        — filter by active flag
 *   ?discount_type=percentage|fixed_amount
 *   ?code=...                    — case-insensitive LIKE search
 *                                   (matches substring of UPPER(code))
 *   ?valid_at=ISO-datetime       — "currently valid at this timestamp"
 *                                   filters out codes outside their
 *                                   time-window
 *   ?limit=N                     — default 20, max 100
 *   ?offset=N                    — default 0
 *
 * No per-row redemption count — that would be N+1 SQL. GetPromoCode
 * provides the count for a single record (justifies one extra query).
 *
 * Audit: emits ACTION_VIEWED with filter context, matching
 * ListNotificationLogs.
 */
final class ListPromoCodesController
{
    use Responder;

    private const DEFAULT_LIMIT = 20;
    private const MAX_LIMIT = 100;

    public function __construct(
        protected readonly ResponseFactoryInterface $responseFactory,
        private readonly EntityManagerInterface $em,
        private readonly PromoCodeSerializer $serializer,
        private readonly AuditEmitter $audit,
    ) {
    }

    protected function getResponseFactory(): ResponseFactoryInterface
    {
        return $this->responseFactory;
    }

    public function __invoke(ServerRequestInterface $request): ResponseInterface
    {
        $user = $request->getAttribute(AuthMiddleware::ATTR_USER);
        if (!$user instanceof User) {
            throw HttpException::unauthorized(ErrorCodes::AUTH_INVALID_TOKEN, 'Authentication required.');
        }

        $query = $request->getQueryParams();

        [$sitewideOnly, $vendorId] = $this->parseScope($query['scope'] ?? null);
        $filters = [
            'isActive' => $this->parseBool($query['is_active'] ?? null, 'is_active'),
            'discountType' => $this->parseDiscountType($query['discount_type'] ?? null),
            'codeContains' => $this->parseNonEmptyString($query['code'] ?? null),
            'validAt' => $this->parseDateTime($query['valid_at'] ?? null, 'valid_at'),
            'sitewideOnly' => $sitewideOnly,
            'vendorId' => $vendorId,
            'limit' => $this->clampLimit($query['limit'] ?? null),
            'offset' => $this->clampOffset($query['offset'] ?? null),
        ];

        /** @var PromoCodeRepository $repo */
        $repo = $this->em->getRepository(PromoCode::class);

        // Repo filters strip null values internally; pass the full
        // set and let the QueryBuilder ignore null filters.
        $repoFilters = array_filter(
            $filters,
            static fn ($v) => $v !== null,
        );
        // Re-add limit + offset always (they have non-null defaults
        // and the repo expects them in the array).
        $repoFilters['limit'] = $filters['limit'];
        $repoFilters['offset'] = $filters['offset'];

        $result = $repo->findFilteredPaginated($repoFilters);

        $this->audit->recordView(
            request: $request,
            actor: $user,
            subject: $user,
            context: [
                'context' => 'admin_promo_codes_list',
                'filters' => array_filter(
                    [
                        'is_active' => $filters['isActive'],
                        'discount_type' => $filters['discountType'],
                        'code' => $filters['codeContains'],
                        'valid_at' => $filters['validAt']?->format('c'),
                        'scope' => $sitewideOnly === true
                            ? 'platform'
                            : ($vendorId !== null ? 'vendor:' . $vendorId : null),
                        'limit' => $filters['limit'],
                        'offset' => $filters['offset'],
                    ],
                    static fn ($v) => $v !== null,
                ),
                'result_count' => count($result['items']),
            ],
        );

        return $this->ok(PaginatedEnvelope::build(
            items: $this->serializer->adminShapeMany($result['items']),
            total: $result['total'],
            limit: $filters['limit'],
            offset: $filters['offset'],
        ));
    }

    private function parseBool(mixed $raw, string $paramName): ?bool
    {
        if ($raw === null || $raw === '') {
            return null;
        }
        if ($raw === 'true' || $raw === '1') {
            return true;
        }
        if ($raw === 'false' || $raw === '0') {
            return false;
        }
        throw HttpException::validation([
            $paramName => ["$paramName must be 'true' or 'false'."],
        ]);
    }

    private function parseDiscountType(mixed $raw): ?string
    {
        if ($raw === null || $raw === '') {
            return null;
        }
        if (!is_string($raw) || !in_array($raw, PromoCode::ALL_DISCOUNT_TYPES, true)) {
            throw HttpException::validation([
                'discount_type' => [
                    'Must be one of: ' . implode(', ', PromoCode::ALL_DISCOUNT_TYPES),
                ],
            ]);
        }
        return $raw;
    }

    private function parseNonEmptyString(mixed $raw): ?string
    {
        if ($raw === null || !is_string($raw)) {
            return null;
        }
        $trimmed = trim($raw);
        return $trimmed === '' ? null : $trimmed;
    }

    /**
     * Parse the ?scope filter into a (sitewideOnly, vendorId) pair:
     *   'platform' / 'sitewide' → [true, null]  (admin-created, vendor_id NULL)
     *   a positive integer      → [null, int]   (that vendor's coupons)
     *   anything else / absent  → [null, null]  (no scope filter — all coupons)
     *
     * @return array{0: bool|null, 1: int|null}
     */
    private function parseScope(mixed $raw): array
    {
        if (!is_string($raw)) {
            return [null, null];
        }
        $value = trim($raw);
        if ($value === '') {
            return [null, null];
        }
        if ($value === 'platform' || $value === 'sitewide') {
            return [true, null];
        }
        if (ctype_digit($value) && (int) $value > 0) {
            return [null, (int) $value];
        }
        return [null, null];
    }

    private function parseDateTime(mixed $raw, string $paramName): ?DateTimeImmutable
    {
        if ($raw === null || $raw === '') {
            return null;
        }
        if (!is_string($raw)) {
            throw HttpException::validation([
                $paramName => ["$paramName must be a valid ISO 8601 datetime."],
            ]);
        }
        try {
            return new DateTimeImmutable($raw, new DateTimeZone('UTC'));
        } catch (\Exception $e) {
            throw HttpException::validation([
                $paramName => ["$paramName must be a valid ISO 8601 datetime."],
            ]);
        }
    }

    private function clampLimit(mixed $raw): int
    {
        if ($raw === null || $raw === '') {
            return self::DEFAULT_LIMIT;
        }
        if (!is_string($raw) || !ctype_digit($raw)) {
            throw HttpException::validation([
                'limit' => ['limit must be a non-negative integer.'],
            ]);
        }
        $value = (int) $raw;
        return max(1, min(self::MAX_LIMIT, $value));
    }

    private function clampOffset(mixed $raw): int
    {
        if ($raw === null || $raw === '') {
            return 0;
        }
        if (!is_string($raw) || !ctype_digit($raw)) {
            throw HttpException::validation([
                'offset' => ['offset must be a non-negative integer.'],
            ]);
        }
        return (int) $raw;
    }
}
