<?php

declare(strict_types=1);

namespace Bayti\Api\Http\Controllers\Admin\Cashback;

use Bayti\Api\Domain\Cashback\CashbackSettingsStore;
use Bayti\Api\Http\Responder;
use Bayti\Api\Http\Serializers\CashbackSerializer;
use DateTimeImmutable;
use DateTimeZone;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * GET /v3/admin/cashback/summary   (cashback.view)
 *
 * Programme dashboard: award totals per status, cashback gift-card money
 * (redeemed at checkout, net of balance given back by cancelled orders /
 * outstanding spendable balance) and per-rule spend versus budget (up to
 * 100 rules, active first).
 */
final class GetCashbackSummaryController
{
    use Responder;
    use AdminCashbackSupport;

    public function __construct(
        protected readonly ResponseFactoryInterface $responseFactory,
        private readonly EntityManagerInterface $em,
        private readonly CashbackSettingsStore $settings,
    ) {
    }

    protected function getResponseFactory(): ResponseFactoryInterface
    {
        return $this->responseFactory;
    }

    public function __invoke(ServerRequestInterface $request): ResponseInterface
    {
        $this->actor($request);
        $awards = $this->awardRepo($this->em);
        $rulesRepo = $this->ruleRepo($this->em);
        $now = new DateTimeImmutable('now', new DateTimeZone('UTC'));

        $totals = [];
        foreach ($awards->totalsByStatus() as $status => $t) {
            $totals[$status] = [
                'count'           => $t['count'],
                'amount'          => $t['amount'],
                'reversed_amount' => $t['reversed'],
            ];
        }

        $rules = $rulesRepo->findFilteredPaginated(['limit' => 100, 'offset' => 0], $now)['items'];
        $ids = [];
        foreach ($rules as $r) {
            if ($r->getId() !== null) {
                $ids[] = $r->getId();
            }
        }
        $spent = $ids === [] ? [] : $rulesRepo->spentByRuleIds($ids);
        $counts = $ids === [] ? [] : $rulesRepo->awardCountsByRuleIds($ids);

        $ruleRows = [];
        foreach ($rules as $r) {
            $id = (int) $r->getId();
            $budget = $r->getBudgetTotal();
            $s = $spent[$id] ?? '0.00';
            $remaining = null;
            if ($budget !== null) {
                $remaining = bcsub($budget, $s, 2);
                $remaining = bccomp($remaining, '0.00', 2) < 0 ? '0.00' : $remaining;
            }
            $ruleRows[] = [
                'id'               => $r->getId(),
                'name'             => $r->getName(),
                'status'           => CashbackSerializer::ruleStatus($r, $now),
                'is_active'        => $r->isActive(),
                'budget_total'     => $budget,
                'budget_spent'     => $s,
                'budget_remaining' => $remaining,
                'awards_count'     => $counts[$id] ?? 0,
            ];
        }

        return $this->ok(['data' => [
            'program'    => $this->settings->load()->toArray(),
            'currency'   => 'AED',
            'totals'     => $totals,
            'gift_cards' => $awards->giftCardMoney(),
            'rules'      => $ruleRows,
        ]]);
    }
}
