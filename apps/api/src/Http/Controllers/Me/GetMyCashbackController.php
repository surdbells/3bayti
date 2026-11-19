<?php

declare(strict_types=1);

namespace Bayti\Api\Http\Controllers\Me;

use Bayti\Api\Domain\Cashback\CashbackAward;
use Bayti\Api\Domain\Cashback\CashbackAwardRepository;
use Bayti\Api\Domain\User\User;
use Bayti\Api\Http\Errors\ErrorCodes;
use Bayti\Api\Http\Errors\HttpException;
use Bayti\Api\Http\Middleware\AuthMiddleware;
use Bayti\Api\Http\PaginatedEnvelope;
use Bayti\Api\Http\Responder;
use Bayti\Api\Http\Serializers\CashbackSerializer;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * GET /v3/me/cashback   (auth)
 *
 * The customer's cashback: totals + award history (newest first,
 * paginated with limit 1-50 default 20, offset).
 *
 * → { "data": [award…], "meta": {total, limit, offset, has_more},
 *     "summary": { "pending_amount": "12.50", "earned_amount": "40.00",
 *                  "reversed_amount": "0.00", "pending_count": 1,
 *                  "earned_count": 3, "currency": "AED" } }
 * earned = issued + reversed awards' amount minus clawbacks.
 */
final class GetMyCashbackController
{
    use Responder;

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
        $user = $request->getAttribute(AuthMiddleware::ATTR_USER);
        if (!$user instanceof User) {
            throw HttpException::unauthorized(ErrorCodes::AUTH_INVALID_TOKEN, 'Authentication required.');
        }
        $q = $request->getQueryParams();
        $limit = isset($q['limit']) && is_numeric($q['limit']) ? max(1, min(50, (int) $q['limit'])) : 20;
        $offset = isset($q['offset']) && is_numeric($q['offset']) ? max(0, (int) $q['offset']) : 0;

        /** @var CashbackAwardRepository $repo */
        $repo = $this->em->getRepository(CashbackAward::class);
        $page = $repo->findForUserPaginated($user, $limit, $offset);
        $t = $repo->totalsByStatus($user);

        $issued = $t[CashbackAward::STATUS_ISSUED];
        $reversed = $t[CashbackAward::STATUS_REVERSED];
        $earned = bcsub(
            bcadd($issued['amount'], $reversed['amount'], 2),
            bcadd($issued['reversed'], $reversed['reversed'], 2),
            2,
        );
        if (bccomp($earned, '0.00', 2) < 0) {
            $earned = '0.00';
        }

        $envelope = PaginatedEnvelope::build(
            array_map(fn (CashbackAward $a): array => $this->serializer->customerAwardShape($a), $page['items']),
            $page['total'],
            $limit,
            $offset,
        );
        $envelope['summary'] = [
            'pending_amount'  => $t[CashbackAward::STATUS_PENDING]['amount'],
            'earned_amount'   => $earned,
            'reversed_amount' => bcadd($issued['reversed'], $reversed['reversed'], 2),
            'pending_count'   => $t[CashbackAward::STATUS_PENDING]['count'],
            'earned_count'    => $issued['count'],
            'currency'        => 'AED',
        ];

        return $this->ok($envelope);
    }
}
