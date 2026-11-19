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
 * POST /v3/admin/cashback/awards/{id}/release   (cashback.manage)
 *
 * "Release now": issues the cashback gift card without waiting for
 * delivery + hold. Still refuses while a return is open (422
 * CASHBACK_RETURN_OPEN) or when the order isn't paid (422
 * CASHBACK_NOT_RELEASABLE); an award whose order died is cancelled
 * instead (outcome=cancelled). Row-locked, so it can't double-issue with
 * the cron. Body: { "reason": "..." } (required).
 *
 * → { "data": award detail, "outcome": "issued" | "cancelled" }
 *   409 CASHBACK_AWARD_NOT_PENDING when it is no longer pending.
 */
final class ReleaseCashbackAwardController
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
        $before = $award->getStatus();

        try {
            $outcome = $this->cashback->releaseAwardById((int) $award->getId(), null, true);
        } catch (\InvalidArgumentException | \DomainException | \LogicException $e) {
            // e.g. the card could not be issued; nothing was committed.
            throw HttpException::businessRuleViolation(
                'CASHBACK_RELEASE_FAILED',
                'The cashback gift card could not be issued: ' . $e->getMessage(),
            );
        }

        match ($outcome) {
            CashbackService::OUTCOME_NOT_PENDING => throw HttpException::conflict(
                'CASHBACK_AWARD_NOT_PENDING',
                "This award is {$award->getStatus()}, only a pending award can be released.",
            ),
            CashbackService::OUTCOME_HELD_RETURN => throw HttpException::businessRuleViolation(
                'CASHBACK_RETURN_OPEN',
                'A return is still open on this order; release it once the return is resolved.',
            ),
            CashbackService::OUTCOME_NOT_DUE, CashbackService::OUTCOME_NOT_FOUND => throw HttpException::businessRuleViolation(
                'CASHBACK_NOT_RELEASABLE',
                'This order is not in a releasable state.',
            ),
            default => null,
        };

        $this->audit->recordOverride($request, $actor, $award, [
            'action'  => 'cashback.release',
            'before'  => ['status' => $before],
            'after'   => ['status' => $award->getStatus(), 'amount' => $award->getAmount(), 'gift_card_id' => $award->getGiftCard()?->getId()],
            'outcome' => $outcome,
            'reason'  => $reason,
        ]);

        return $this->ok(['data' => $this->serializer->adminAwardDetailShape($award), 'outcome' => $outcome]);
    }
}
