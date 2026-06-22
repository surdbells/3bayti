<?php

declare(strict_types=1);

namespace Bayti\Api\Http\Controllers\Admin\GiftCard;

use Bayti\Api\Domain\Audit\AuditEmitter;
use Bayti\Api\Domain\GiftCard\GiftCard;
use Bayti\Api\Domain\GiftCard\GiftCardRepository;
use Bayti\Api\Domain\User\User;
use Bayti\Api\Http\Controllers\GiftCard\GiftCardSerializer;
use Bayti\Api\Http\Errors\ErrorCodes;
use Bayti\Api\Http\Errors\HttpException;
use Bayti\Api\Http\Middleware\AuthMiddleware;
use Bayti\Api\Http\PaginatedEnvelope;
use Bayti\Api\Http\Responder;
use DateTimeImmutable;
use DateTimeZone;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * GET /v3/admin/gift-cards
 *
 * Paginated admin list of gift cards with search + filters. Gated by
 * gift_cards.view. Mirrors the ListPromoCodes pattern.
 *
 * Query parameters (all optional):
 *   ?search=...          — substring match on code / recipient email /
 *                          recipient name / purchaser email
 *   ?status=active|...   — exact GiftCard::STATUS_* match
 *   ?min_balance=100.00  — balance >=
 *   ?max_balance=500.00  — balance <=
 *   ?min_value=100.00    — denomination (original value) >=
 *   ?max_value=500.00    — denomination <=
 *   ?created_from=ISO    — created_at >=
 *   ?created_to=ISO      — created_at <=
 *   ?delivered=true|false— delivered (any channel) yes / no
 *   ?limit=N             — default 20, max 100
 *   ?offset=N            — default 0
 *
 * No per-row ledger — that would be N+1. The detail endpoint returns the
 * ledger for a single card.
 */
final class ListGiftCardsController
{
    use Responder;

    private const DEFAULT_LIMIT = 20;
    private const MAX_LIMIT = 100;

    public function __construct(
        protected readonly ResponseFactoryInterface $responseFactory,
        private readonly EntityManagerInterface $em,
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

        $limit  = $this->clampLimit($query['limit'] ?? null);
        $offset = $this->clampOffset($query['offset'] ?? null);

        $filters = [
            'search'      => $this->str($query['search'] ?? null),
            'status'      => $this->status($query['status'] ?? null),
            'minBalance'  => $this->money($query['min_balance'] ?? null, 'min_balance'),
            'maxBalance'  => $this->money($query['max_balance'] ?? null, 'max_balance'),
            'minValue'    => $this->money($query['min_value'] ?? null, 'min_value'),
            'maxValue'    => $this->money($query['max_value'] ?? null, 'max_value'),
            'createdFrom' => $this->date($query['created_from'] ?? null, 'created_from'),
            'createdTo'   => $this->date($query['created_to'] ?? null, 'created_to'),
            'delivered'   => $this->bool($query['delivered'] ?? null, 'delivered'),
            'limit'       => $limit,
            'offset'      => $offset,
        ];

        /** @var GiftCardRepository $repo */
        $repo = $this->em->getRepository(GiftCard::class);
        $result = $repo->findPaginatedForAdmin($filters);

        $this->audit->recordView(
            request: $request,
            actor: $user,
            subject: $user,
            context: [
                'context' => 'admin_gift_cards_list',
                'filters' => array_filter([
                    'search'       => $filters['search'],
                    'status'       => $filters['status'],
                    'min_balance'  => $filters['minBalance'],
                    'max_balance'  => $filters['maxBalance'],
                    'min_value'    => $filters['minValue'],
                    'max_value'    => $filters['maxValue'],
                    'created_from' => $filters['createdFrom']?->format('c'),
                    'created_to'   => $filters['createdTo']?->format('c'),
                    'delivered'    => $filters['delivered'],
                    'limit'        => $limit,
                    'offset'       => $offset,
                ], static fn ($v) => $v !== null),
                'result_count' => count($result['items']),
            ],
        );

        return $this->ok(PaginatedEnvelope::build(
            items: GiftCardSerializer::adminListShapeMany($result['items']),
            total: $result['total'],
            limit: $limit,
            offset: $offset,
        ));
    }

    private function str(mixed $raw): ?string
    {
        if ($raw === null || !is_string($raw)) {
            return null;
        }
        $t = trim($raw);
        return $t === '' ? null : $t;
    }

    private function status(mixed $raw): ?string
    {
        $s = $this->str($raw);
        if ($s === null) {
            return null;
        }
        $valid = [
            GiftCard::STATUS_PENDING_PAYMENT,
            GiftCard::STATUS_ACTIVE,
            GiftCard::STATUS_PARTIALLY_USED,
            GiftCard::STATUS_EXHAUSTED,
            GiftCard::STATUS_EXPIRED,
            GiftCard::STATUS_VOIDED,
        ];
        if (!in_array($s, $valid, true)) {
            throw HttpException::validation([
                'status' => ['Must be one of: ' . implode(', ', $valid)],
            ]);
        }
        return $s;
    }

    private function money(mixed $raw, string $param): ?string
    {
        $s = $this->str($raw);
        if ($s === null) {
            return null;
        }
        if (!preg_match('/^\d+(\.\d{1,2})?$/', $s)) {
            throw HttpException::validation([
                $param => ["$param must be a non-negative decimal (e.g. 100.00)."],
            ]);
        }
        return bcadd($s, '0.00', 2);
    }

    private function date(mixed $raw, string $param): ?DateTimeImmutable
    {
        $s = $this->str($raw);
        if ($s === null) {
            return null;
        }
        try {
            return new DateTimeImmutable($s, new DateTimeZone('UTC'));
        } catch (\Exception) {
            throw HttpException::validation([
                $param => ["$param must be a valid ISO 8601 datetime."],
            ]);
        }
    }

    private function bool(mixed $raw, string $param): ?bool
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
            $param => ["$param must be 'true' or 'false'."],
        ]);
    }

    private function clampLimit(mixed $raw): int
    {
        if ($raw === null || $raw === '') {
            return self::DEFAULT_LIMIT;
        }
        if (!is_string($raw) || !ctype_digit($raw)) {
            throw HttpException::validation(['limit' => ['limit must be a non-negative integer.']]);
        }
        return max(1, min(self::MAX_LIMIT, (int) $raw));
    }

    private function clampOffset(mixed $raw): int
    {
        if ($raw === null || $raw === '') {
            return 0;
        }
        if (!is_string($raw) || !ctype_digit($raw)) {
            throw HttpException::validation(['offset' => ['offset must be a non-negative integer.']]);
        }
        return (int) $raw;
    }
}
