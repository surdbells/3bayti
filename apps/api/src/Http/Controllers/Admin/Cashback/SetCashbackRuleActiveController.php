<?php

declare(strict_types=1);

namespace Bayti\Api\Http\Controllers\Admin\Cashback;

use Bayti\Api\Domain\Audit\AuditEmitter;
use Bayti\Api\Http\Responder;
use Bayti\Api\Http\Serializers\CashbackSerializer;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * POST /v3/admin/cashback/rules/{id}/activate     (cashback.edit)
 * POST /v3/admin/cashback/rules/{id}/deactivate   (cashback.edit)
 *
 * Idempotent switch → { "data": rule }. The action is read from the path.
 */
final class SetCashbackRuleActiveController
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
        $active = !str_ends_with(rtrim($request->getUri()->getPath(), '/'), '/deactivate');

        $before = $this->audit->snapshot($rule);
        if ($rule->isActive() !== $active) {
            $rule->setActive($active);
            $rule->onUpdate();
            $this->em->flush();
            $this->audit->recordUpdate($request, $actor, $rule, $before, $this->audit->snapshot($rule));
        }

        return $this->ok(['data' => $this->ruleDetail($this->em, $this->serializer, $rule)]);
    }
}
