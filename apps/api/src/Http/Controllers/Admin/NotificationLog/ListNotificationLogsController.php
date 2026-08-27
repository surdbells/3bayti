<?php

declare(strict_types=1);

namespace Bayti\Api\Http\Controllers\Admin\NotificationLog;

use Bayti\Api\Domain\Audit\AuditEmitter;
use Bayti\Api\Domain\Notification\NotificationLog;
use Bayti\Api\Domain\Notification\NotificationLogRepository;
use Bayti\Api\Domain\User\User;
use Bayti\Api\Http\Errors\ErrorCodes;
use Bayti\Api\Http\Errors\HttpException;
use Bayti\Api\Http\Middleware\AuthMiddleware;
use Bayti\Api\Http\PaginatedEnvelope;
use Bayti\Api\Http\Responder;
use Bayti\Api\Http\Serializers\NotificationLogSerializer;
use Bayti\Api\Notification\EmailTemplate;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * GET /v3/admin/notification-logs
 *
 * Admin observability surface for the notification_logs table.
 * Lists notification send attempts with filtering for triage queries.
 *
 * Query parameters (Q-FilterSet = A locked)
 * ==========================================
 *   ?order_id=N       , exact order match
 *   ?template=...     , exact EmailTemplate enum value match
 *                         (e.g. 'order.placed.customer')
 *   ?status=...       , sent | failed | skipped
 *   ?recipient=...    , exact email match
 *   ?error_kind=...   , MailerException kind or exception class name
 *   ?since=...        , ISO datetime, inclusive lower bound on sent_at
 *   ?until=...        , ISO datetime, inclusive upper bound on sent_at
 *   ?limit=N          , default 20, max 100
 *   ?offset=N         , default 0
 *
 * Audit (Q-Audit = A locked)
 * ==========================
 * Emits ACTION_VIEWED per request, matching ListAdminOrdersController.
 * The recipient emails + error_message fields are debatably sensitive
 * (PII per GDPR), so the audit trail records "admin X queried the
 * notification logs with these filters."
 *
 * Response envelope
 * =================
 * Standard PaginatedEnvelope shape:
 *   {
 *     "data": [...notification_log rows...],
 *     "meta": {
 *       "total": int,    // total matching the filter (before limit)
 *       "limit": int,    // echo of requested limit (post-clamp)
 *       "offset": int,
 *       "has_more": bool
 *     }
 *   }
 *
 * Failure modes
 * =============
 *   - Invalid status value → 400 (validation)
 *   - Invalid template value → 400 (validation)
 *   - Invalid date format → 400 (validation)
 *   - Non-admin caller → 403 (gated by AdminAuthMiddleware)
 *   - Empty result set → 200 with data:[] (empty isn't an error)
 *
 * Routing: under /v3/admin group + AdminAuthMiddleware + AuthMiddleware.
 * Guarantees user is admin before invocation.
 */
final class ListNotificationLogsController
{
    use Responder;

    private const DEFAULT_LIMIT = 20;
    private const MAX_LIMIT = 100;

    public function __construct(
        protected readonly ResponseFactoryInterface $responseFactory,
        private readonly EntityManagerInterface $em,
        private readonly NotificationLogSerializer $serializer,
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
            throw HttpException::unauthorized(
                ErrorCodes::AUTH_INVALID_TOKEN,
                'Authentication required.',
            );
        }

        $query = $request->getQueryParams();

        // Parse + validate filters. Invalid values raise 400; absent
        // values fall through and don't filter.
        $filters = [
            'orderId' => $this->parsePositiveInt($query['order_id'] ?? null, 'order_id'),
            'template' => $this->parseTemplate($query['template'] ?? null),
            'status' => $this->parseStatus($query['status'] ?? null),
            'recipient' => $this->parseNonEmptyString($query['recipient'] ?? null),
            'errorKind' => $this->parseNonEmptyString($query['error_kind'] ?? null),
            'since' => $this->parseDateTime($query['since'] ?? null, 'since'),
            'until' => $this->parseDateTime($query['until'] ?? null, 'until'),
            'limit' => $this->clampLimit($query['limit'] ?? null),
            'offset' => $this->clampOffset($query['offset'] ?? null),
        ];

        /** @var NotificationLogRepository $repo */
        $repo = $this->em->getRepository(NotificationLog::class);
        $result = $repo->findFilteredPaginated($filters);

        // Audit the listing access. Subject is the admin User
        // themselves, list views have no single subject entity. The
        // filter context lets ops reconstruct what the admin viewed.
        $this->audit->recordView(
            request: $request,
            actor: $user,
            subject: $user,
            context: [
                'context' => 'admin_notification_logs_list',
                'filters' => array_filter(
                    [
                        'order_id' => $filters['orderId'],
                        'template' => $filters['template'],
                        'status' => $filters['status'],
                        'recipient' => $filters['recipient'],
                        'error_kind' => $filters['errorKind'],
                        'since' => $filters['since']?->format('c'),
                        'until' => $filters['until']?->format('c'),
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

    // -----------------------------------------------------------------
    // Filter parsing helpers
    // -----------------------------------------------------------------

    private function parsePositiveInt(mixed $raw, string $paramName): ?int
    {
        if ($raw === null || $raw === '') {
            return null;
        }
        if (!is_string($raw) || !ctype_digit($raw)) {
            throw HttpException::validation([
                $paramName => ["$paramName must be a positive integer."],
            ]);
        }
        $value = (int) $raw;
        return $value > 0 ? $value : null;
    }

    private function parseTemplate(mixed $raw): ?string
    {
        if ($raw === null || $raw === '') {
            return null;
        }
        if (!is_string($raw)) {
            throw HttpException::validation([
                'template' => ['template must be a string.'],
            ]);
        }
        // Verify it's a known EmailTemplate enum value
        if (EmailTemplate::tryFrom($raw) === null) {
            $valid = array_map(static fn (EmailTemplate $t) => $t->value, EmailTemplate::cases());
            throw HttpException::validation([
                'template' => ['Must be one of: ' . implode(', ', $valid)],
            ]);
        }
        return $raw;
    }

    private function parseStatus(mixed $raw): ?string
    {
        if ($raw === null || $raw === '') {
            return null;
        }
        if (!is_string($raw) || !in_array($raw, NotificationLog::ALL_STATUSES, true)) {
            throw HttpException::validation([
                'status' => ['Must be one of: ' . implode(', ', NotificationLog::ALL_STATUSES)],
            ]);
        }
        return $raw;
    }

    private function parseNonEmptyString(mixed $raw): ?string
    {
        if ($raw === null || $raw === '' || !is_string($raw)) {
            return null;
        }
        return $raw;
    }

    private function parseDateTime(mixed $raw, string $paramName): ?DateTimeImmutable
    {
        if ($raw === null || $raw === '') {
            return null;
        }
        if (!is_string($raw)) {
            throw HttpException::validation([
                $paramName => ["$paramName must be an ISO-8601 datetime string."],
            ]);
        }
        try {
            return new DateTimeImmutable($raw);
        } catch (\Throwable $e) {
            throw HttpException::validation([
                $paramName => ["$paramName must be a valid ISO-8601 datetime (e.g. '2026-05-17T08:00:00Z')."],
            ]);
        }
    }

    private function clampLimit(mixed $raw): int
    {
        if ($raw === null || $raw === '') {
            return self::DEFAULT_LIMIT;
        }
        if (!is_string($raw) || !ctype_digit($raw)) {
            return self::DEFAULT_LIMIT;
        }
        $value = (int) $raw;
        if ($value < 1) {
            return self::DEFAULT_LIMIT;
        }
        return min($value, self::MAX_LIMIT);
    }

    private function clampOffset(mixed $raw): int
    {
        if ($raw === null || $raw === '') {
            return 0;
        }
        if (!is_string($raw) || !ctype_digit($raw)) {
            return 0;
        }
        return max(0, (int) $raw);
    }
}
