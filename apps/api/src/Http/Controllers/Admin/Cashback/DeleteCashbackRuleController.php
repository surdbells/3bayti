<?php

declare(strict_types=1);

namespace Bayti\Api\Http\Controllers\Admin\Cashback;

use Bayti\Api\Domain\Audit\AuditEmitter;
use Bayti\Api\Http\Responder;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * DELETE /v3/admin/cashback/rules/{id}   (cashback.delete) → 204
 *
 * Hard-deletes a rule that never contributed to an award. A rule with
 * awards is only deactivated (its award lines keep referencing it for the
 * ledger, budget and reports). Either way the response is 204, like the
 * promo-code delete.
 */
final class DeleteCashbackRuleController
{
    use Responder;
    use AdminCashbackSupport;

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

    /** @param array<string, string> $args */
    public function __invoke(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $actor = $this->actor($request);
        $rule = $this->findRule($this->em, $this->idArg($args, 'Cashback rule'));
        $before = $this->audit->snapshot($rule);

        if ($this->ruleRepo($this->em)->isUsed($rule)) {
            if ($rule->isActive()) {
                $rule->setActive(false);
                $rule->onUpdate();
                $this->em->flush();
                $this->audit->recordUpdate($request, $actor, $rule, $before, $this->audit->snapshot($rule));
            }
            return $this->noContent();
        }

        // Audit BEFORE removal: the subject id is gone after the flush.
        $this->audit->recordDelete($request, $actor, $rule, $before);
        $this->em->remove($rule);
        $this->em->flush();

        return $this->noContent();
    }
}
