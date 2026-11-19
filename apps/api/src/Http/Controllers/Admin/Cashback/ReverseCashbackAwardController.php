<?php

declare(strict_types=1);

namespace Bayti\Api\Http\Controllers\Admin\Cashback;

use Bayti\Api\Domain\Audit\AuditEmitter;
use Bayti\Api\Domain\Cashback\CashbackService;
use Bayti\Api\Http\Errors\HttpException;
use Bayti\Api\Http\Responder;
use Bayti\Api\Http\Serializers\CashbackSerializer;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * POST /v3/admin/cashback/awards/{id}/reverse   (cashback.manage)
 *
 * Claw back an ISSUED award: debits min(card balance, amount) from the
 * cashback gift card through its ledger (what the customer already spent
 * is written off). Body: { "reason": "...", "amount": "5.00" (optional,
 * partial; omitted = full reversal → status reversed) }.
 *
 * → { "data": award detail, "reversal": { "value", "collected", "written_off" } }
 *   409 CASHBACK_AWARD_NOT_ISSUED when the award is not issued.
 */
final class ReverseCashbackAwardController
{
    use Responder;
    use AdminCashbackSupport;

    public function __construct(
        protected readonly ResponseFactoryInterface $responseFactory,
        private readonly EntityManagerInterface $em,
        private readonly CashbackService $cashback,
        private readonly CashbackSerializer $serializer,
        private readonly AuditEmitter $audit,
    ) {
    }

    protected function getResponseFactory(): ResponseFactoryInterface
    {
        return $this->responseFactory;
    }

    /** @param array<string, string> $args */
    public function __invoke(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $actor = $this->actor($request);
        $award = $this->findAward($this->em, $this->idArg($args, 'Cashback award'));
        $reason = $this->reasonFrom($request, true);

        $amount = null;
        $rawAmount = $this->body($request)['amount'] ?? null;
        if ($rawAmount !== null && $rawAmount !== '') {
            if (is_int($rawAmount) || is_float($rawAmount)) {
                $rawAmount = number_format((float) $rawAmount, 2, '.', '');
            }
            if (!is_string($rawAmount) || !preg_match('/^\d{1,8}(\.\d{1,2})?$/', $rawAmount) || bccomp($rawAmount, '0.00', 2) <= 0) {
                throw HttpException::validation(['amount' => ['amount must be a positive amount with up to 2 decimals.']]);
            }
            $amount = bcadd($rawAmount, '0', 2);
        }

        if (!$award->isIssued()) {
            throw HttpException::conflict(
                'CASHBACK_AWARD_NOT_ISSUED',
                "This award is {$award->getStatus()}, only an issued award can be reversed.",
            );
        }
        $before = ['status' => $award->getStatus(), 'reversed_amount' => $award->getReversedAmount()];

        try {
            $result = $this->cashback->reverseAward($award, 'Reversed by admin: ' . $reason, $actor, $amount);
        } catch (\DomainException $e) {
            throw HttpException::conflict('CASHBACK_AWARD_NOT_ISSUED', $e->getMessage());
        }

        $reversal = [
            'value'       => $result['value'],
            'collected'   => $result['collected'],
            'written_off' => bcsub($result['value'], $result['collected'], 2),
        ];
        $this->audit->recordOverride($request, $actor, $award, [
            'action'   => 'cashback.reverse',
            'before'   => $before,
            'after'    => ['status' => $award->getStatus(), 'reversed_amount' => $award->getReversedAmount()],
            'reversal' => $reversal,
            'reason'   => $reason,
        ]);

        return $this->ok(['data' => $this->serializer->adminAwardDetailShape($award), 'reversal' => $reversal]);
    }
}
