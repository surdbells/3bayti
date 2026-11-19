<?php

declare(strict_types=1);

namespace Bayti\Api\Http\Controllers\Admin\Cashback;

use Bayti\Api\Domain\Audit\AuditEmitter;
use Bayti\Api\Domain\Cashback\CashbackSettingsStore;
use Bayti\Api\Http\Errors\HttpException;
use Bayti\Api\Http\Responder;
use Bayti\Api\Http\Serializers\CashbackSerializer;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * POST /v3/admin/cashback/rules   (cashback.create) → 201 { "data": rule }
 *
 * Required: name, reward_type, reward_value. Everything else is optional
 * (see CashbackRuleInput). hold_days / gift_card_validity_days default to
 * the programme defaults. A new rule is inactive unless is_active=true.
 */
final class CreateCashbackRuleController
{
    use Responder;
    use AdminCashbackSupport;

    public function __construct(
        protected readonly ResponseFactoryInterface $responseFactory,
        private readonly EntityManagerInterface $em,
        private readonly CashbackSerializer $serializer,
        private readonly CashbackSettingsStore $settings,
        private readonly AuditEmitter $audit,
    ) {
    }

    protected function getResponseFactory(): ResponseFactoryInterface
    {
        return $this->responseFactory;
    }

    public function __invoke(ServerRequestInterface $request): ResponseInterface
    {
        $actor = $this->actor($request);
        $body = $this->body($request);

        [$rule, $errors] = CashbackRuleInput::create($body);
        if ($rule === null) {
            throw HttpException::validation($errors);
        }
        $defaults = $this->settings->load();
        $rule->setHoldDays($defaults->defaultHoldDays);
        $rule->setGiftCardValidityDays($defaults->defaultValidityDays);
        $rule->setCreatedBy($actor->getId());

        $errors = CashbackRuleInput::apply($rule, $body, $this->em);
        if ($errors !== []) {
            throw HttpException::validation($errors);
        }

        $this->ruleRepo($this->em)->save($rule);
        $this->audit->recordCreate($request, $actor, $rule, $this->audit->snapshot($rule));

        return $this->created(['data' => $this->ruleDetail($this->em, $this->serializer, $rule)]);
    }
}
