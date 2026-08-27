<?php

declare(strict_types=1);

namespace Bayti\Api\Http\Controllers\Admin\GiftCard;

use Bayti\Api\Domain\Audit\AuditEmitter;
use Bayti\Api\Domain\GiftCard\GiftCardTransaction;
use Bayti\Api\Domain\User\User;
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
 * GET /v3/admin/gift-cards/redemptions
 *
 * The admin "gift cards spent at checkout" report, every DEBIT tied to an
 * order (a customer applying a gift card / their wallet toward a purchase),
 * newest first, plus a summary of the total value redeemed over the range.
 * Gated by gift_cards.view.
 *
 * This complements the per-card ledger (in the card detail) with a single
 * cross-card view of redemption activity, so admins have a clear picture of
 * how much gift-card value is being spent and against which orders.
 *
 * Query parameters (all optional):
 *   ?from=ISO  , created_at >= (start of range)
 *   ?to=ISO    , created_at <= (end of range)
 *   ?limit=N   , default 20, max 100
 *   ?offset=N  , default 0
 *
 * Response: { data: [ …redemption rows… ], meta: {…pagination…},
 *             summary: { total_redeemed, redemption_count, currency } }
 */
final class ListGiftCardRedemptionsController
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

        $query  = $request->getQueryParams();
        $limit  = $this->clampLimit($query['limit'] ?? null);
        $offset = $this->clampOffset($query['offset'] ?? null);
        $from   = $this->date($query['from'] ?? null, 'from');
        $to     = $this->date($query['to'] ?? null, 'to');

        // Base filter: checkout DEBITs (a redemption always carries the order
        // reference; admin adjustments/voids don't and are excluded).
        $base = $this->em->getRepository(GiftCardTransaction::class)
            ->createQueryBuilder('t')
            ->where('t.type = :debit')
            ->andWhere('t.orderReference IS NOT NULL')
            ->setParameter('debit', GiftCardTransaction::TYPE_DEBIT);
        if ($from !== null) {
            $base->andWhere('t.createdAt >= :from')->setParameter('from', $from);
        }
        if ($to !== null) {
            $base->andWhere('t.createdAt <= :to')->setParameter('to', $to);
        }

        // Summary (count + summed value) over the whole filtered set.
        $summaryQb = (clone $base)
            ->select('COUNT(t.id) AS cnt', 'COALESCE(SUM(t.amount), 0) AS total');
        /** @var array{cnt: int|string, total: int|string|float} $row */
        $row   = $summaryQb->getQuery()->getSingleResult();
        $count = (int) $row['cnt'];
        $totalRedeemed = bcadd((string) $row['total'], '0.00', 2);

        // Page, eager-load the card + buyer so the row shaper never N+1s.
        $items = (clone $base)
            ->leftJoin('t.giftCard', 'g')->addSelect('g')
            ->leftJoin('g.buyerUser', 'b')->addSelect('b')
            ->orderBy('t.id', 'DESC')
            ->setMaxResults($limit)
            ->setFirstResult($offset)
            ->getQuery()
            ->getResult();

        $this->audit->recordView(
            request: $request,
            actor: $user,
            subject: $user,
            context: [
                'context'      => 'admin_gift_card_redemptions',
                'from'         => $from?->format('c'),
                'to'           => $to?->format('c'),
                'result_count' => count($items),
            ],
        );

        $envelope = PaginatedEnvelope::build(
            items: array_map([$this, 'shape'], $items),
            total: $count,
            limit: $limit,
            offset: $offset,
        );
        $envelope['summary'] = [
            'total_redeemed'   => $totalRedeemed,
            'redemption_count' => $count,
            'currency'         => 'AED',
        ];

        return $this->ok($envelope);
    }

    /**
     * @return array<string, mixed>
     */
    private function shape(GiftCardTransaction $tx): array
    {
        $card  = $tx->getGiftCard();
        $buyer = $card->getBuyerUser();
        $name  = trim(((string) $buyer->getFirstName()) . ' ' . ((string) $buyer->getLastName()));

        return [
            'id'              => $tx->getId(),
            'amount'          => $tx->getAmount(),
            'balance_after'   => $tx->getBalanceAfter(),
            'order_reference' => $tx->getOrderReference(),
            'order_id'        => $tx->getOrderId(),
            'created_at'      => $tx->getCreatedAt()->format(\DateTimeInterface::ATOM),
            'card' => [
                'id'     => $card->getId(),
                'code'   => $card->formattedCode(),
                'theme'  => $card->getTheme(),
                'status' => $card->getStatus(),
            ],
            'purchaser' => [
                'id'    => $buyer->getId(),
                'email' => $buyer->getEmail(),
                'name'  => $name === '' ? null : $name,
            ],
        ];
    }

    private function date(mixed $raw, string $param): ?DateTimeImmutable
    {
        if ($raw === null || !is_string($raw) || trim($raw) === '') {
            return null;
        }
        try {
            return new DateTimeImmutable(trim($raw), new DateTimeZone('UTC'));
        } catch (\Exception) {
            throw HttpException::validation([$param => ["$param must be a valid ISO 8601 datetime."]]);
        }
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
