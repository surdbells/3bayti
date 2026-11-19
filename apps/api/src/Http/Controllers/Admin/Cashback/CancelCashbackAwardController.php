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
 * POST /v3/admin/cashback/awards/{id}/cancel   (cashback.manage)
 *
 * Cancel a PENDING award (nothing was paid out yet). Body:
 * { "reason": "..." } (required). → { "data": award detail }
 * 409 CASHBACK_AWARD_NOT_PENDING otherwise (use /reverse for an issued one).
 */
final class CancelCashbackAwardController
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

        if (!$this->cashback->cancelAward($award, 'Cancelled by admin: ' . $reason)) {
            throw HttpException::conflict(
                'CASHBACK_AWARD_NOT_PENDING',
                "This award is {$award->getStatus()}, only a pending award can be cancelled.",
            );
        }

        $this->audit->recordOverride($request, $actor, $award, [
            'action' => 'cashback.cancel',
            'before' => ['status' => $before],
            'after'  => ['status' => $award->getStatus()],
            'reason' => $reason,
        ]);

        return $this->ok(['data' => $this->serializer->adminAwardDetailShape($award)]);
    }
}
