<?php

declare(strict_types=1);

namespace Bayti\Api\Http\Controllers\Admin\Cashback;

use Bayti\Api\Domain\Audit\AuditEmitter;
use Bayti\Api\Domain\Cashback\CashbackProgramSettings;
use Bayti\Api\Domain\Cashback\CashbackSettingsStore;
use Bayti\Api\Http\Errors\HttpException;
use Bayti\Api\Http\Responder;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * PUT /v3/admin/cashback/settings   (cashback.edit)
 *
 * Partial update of the programme settings (master switch, minimum award,
 * per-order cap, default hold / validity days). Takes effect on the next
 * accrual. Audited via recordSettingChange.
 *
 * Body (any subset): { "enabled": true, "min_award": "1.00",
 *   "max_award_per_order": "500.00"|null, "default_hold_days": 14,
 *   "default_validity_days": 365 }
 */
final class UpdateCashbackSettingsController
{
    use Responder;
    use AdminCashbackSupport;

    public function __construct(
        protected readonly ResponseFactoryInterface $responseFactory,
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
        $before = $this->settings->load();

        [$after, $errors] = CashbackProgramSettings::fromAdminInput($this->body($request), $before);
        if ($after === null) {
            throw HttpException::validation($errors);
        }

        $this->settings->save($after);
        $this->audit->recordSettingChange(
            request: $request,
            actor: $actor,
            settingKey: CashbackProgramSettings::KEY,
            before: $before->toArray(),
            after: $after->toArray(),
        );

        return $this->ok(['data' => $after->toArray()]);
    }
}
