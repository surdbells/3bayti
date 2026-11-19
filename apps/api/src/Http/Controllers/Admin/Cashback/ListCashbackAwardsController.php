<?php

declare(strict_types=1);

namespace Bayti\Api\Http\Controllers\Admin\Cashback;

use Bayti\Api\Domain\Cashback\CashbackAward;
use Bayti\Api\Http\Errors\HttpException;
use Bayti\Api\Http\PaginatedEnvelope;
use Bayti\Api\Http\Responder;
use Bayti\Api\Http\Serializers\CashbackSerializer;
use DateTimeImmutable;
use DateTimeZone;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * GET /v3/admin/cashback/awards   (cashback.view)
 *
 * The cashback ledger, newest first. Query: status
 * (pending|issued|cancelled|reversed), rule_id, user_id, customer (email /
 * phone contains), order_reference (contains), date_from / date_to
 * (award created_at, ISO date or datetime; date_to is inclusive of that
 * day), limit (1-100, default 20), offset. Paginated envelope.
 */
final class ListCashbackAwardsController
{
    use Responder;
    use AdminCashbackSupport;

    public function __construct(
        protected readonly ResponseFactoryInterface $responseFactory,
        private readonly EntityManagerInterface $em,
        private readonly CashbackSerializer $serializer,
    ) {
    }

    protected function getResponseFactory(): ResponseFactoryInterface
    {
        return $this->responseFactory;
    }

    public function __invoke(ServerRequestInterface $request): ResponseInterface
    {
        $this->actor($request);
        $q = $request->getQueryParams();

        $limit = isset($q['limit']) && is_numeric($q['limit']) ? (int) $q['limit'] : 20;
        $filters = [
            'limit'  => max(1, min(100, $limit)),
            'offset' => isset($q['offset']) && is_numeric($q['offset']) ? max(0, (int) $q['offset']) : 0,
        ];

        $status = self::str($q, 'status');
        if ($status !== null) {
            if (!in_array($status, CashbackAward::STATUSES, true)) {
                throw HttpException::validation(['status' => ['status must be one of: ' . implode(', ', CashbackAward::STATUSES) . '.']]);
            }
            $filters['status'] = $status;
        }
        foreach (['rule_id', 'user_id'] as $key) {
            $v = self::str($q, $key);
            if ($v !== null) {
                if (!ctype_digit($v)) {
                    throw HttpException::validation([$key => ["{$key} must be a positive integer."]]);
                }
                $filters[$key] = (int) $v;
            }
        }
        $customer = self::str($q, 'customer');
        if ($customer !== null) {
            $filters['customer'] = $customer;
        }
        $ref = self::str($q, 'order_reference');
        if ($ref !== null) {
            $filters['order_reference'] = $ref;
        }
        $from = self::str($q, 'date_from');
        if ($from !== null) {
            $filters['date_from'] = self::date($from, 'date_from', false);
        }
        $to = self::str($q, 'date_to');
        if ($to !== null) {
            $filters['date_to'] = self::date($to, 'date_to', true);
        }

        $result = $this->awardRepo($this->em)->findFilteredPaginated($filters);
        $items = array_map(fn (CashbackAward $a): array => $this->serializer->adminAwardShape($a), $result['items']);

        return $this->ok(PaginatedEnvelope::build($items, $result['total'], $filters['limit'], $filters['offset']));
    }

    /** @param array<string, mixed> $q */
    private static function str(array $q, string $key): ?string
    {
        $v = $q[$key] ?? null;
        return is_string($v) && trim($v) !== '' ? trim($v) : null;
    }

    private static function date(string $raw, string $field, bool $endOfDay): DateTimeImmutable
    {
        try {
            $d = new DateTimeImmutable($raw, new DateTimeZone('UTC'));
        } catch (\Exception) {
            throw HttpException::validation([$field => ["{$field} must be an ISO-8601 date or datetime."]]);
        }
        if ($endOfDay && preg_match('/^\d{4}-\d{2}-\d{2}$/', $raw) === 1) {
            $d = $d->setTime(23, 59, 59);
        }
        return $d;
    }
}
