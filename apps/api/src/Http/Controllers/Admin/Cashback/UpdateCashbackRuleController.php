<?php

declare(strict_types=1);

namespace Bayti\Api\Http\Controllers\Admin\Cashback;

use Bayti\Api\Domain\Audit\AuditEmitter;
use Bayti\Api\Http\Errors\HttpException;
use Bayti\Api\Http\Responder;
use Bayti\Api\Http\Serializers\CashbackSerializer;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * PUT /v3/admin/cashback/rules/{id}   (cashback.edit) → { "data": rule }
 *
 * Partial update: only keys present are applied; null clears a nullable
 * field (caps, threshold, dates, per_customer_limit, budget_total).
 * `targets`, when present, REPLACES the whole target set. Changes apply to
 * future accruals only; awards already accrued keep their snapshot.
 */
final class UpdateCashbackRuleController
{
    use Responder;
    use AdminCashbackSupport;

    public function __construct(
        protected readonly ResponseFactoryInterface $responseFactory,
        private readonly EntityManagerInterface $em,
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
        $rule = $this->findRule($this->em, $this->idArg($args, 'Cashback rule'));
        $before = $this->audit->snapshot($rule);

        $errors = CashbackRuleInput::apply($rule, $this->body($request), $this->em);
        if ($errors !== []) {
            // Nothing is flushed on this path; the request ends here.
            throw HttpException::validation($errors);
        }
        $rule->onUpdate();
        $this->em->flush();

        $this->audit->recordUpdate($request, $actor, $rule, $before, $this->audit->snapshot($rule));

        return $this->ok(['data' => $this->ruleDetail($this->em, $this->serializer, $rule)]);
    }
}
