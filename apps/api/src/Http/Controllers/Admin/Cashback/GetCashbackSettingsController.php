<?php

declare(strict_types=1);

namespace Bayti\Api\Http\Controllers\Admin\Cashback;

use Bayti\Api\Domain\Cashback\CashbackSettingsStore;
use Bayti\Api\Http\Responder;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * GET /v3/admin/cashback/settings   (cashback.view)
 *
 * { "data": { "enabled": bool, "min_award": "1.00", "max_award_per_order": "500.00"|null,
 *             "default_hold_days": 14, "default_validity_days": 365 } }
 */
final class GetCashbackSettingsController
{
    use Responder;
    use AdminCashbackSupport;

    public function __construct(
        protected readonly ResponseFactoryInterface $responseFactory,
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
        return $this->ok(['data' => $this->settings->load()->toArray()]);
    }
}
