<?php

declare(strict_types=1);

namespace Bayti\Api\Http\Controllers\Admin\Currency;

use Bayti\Api\Domain\Currency\Currency;
use Bayti\Api\Domain\Currency\FxRate;
use Bayti\Api\Domain\Currency\FxRateRepository;
use Bayti\Api\Http\Responder;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * GET /v3/admin/fx-rates
 *
 * List all FX rates managed by the marketplace (M3.2.X.15-F).
 * Returns one row per supported (base_code, target_code) pair
 * with the current rate value, last-updated timestamp, and
 * updated-by user id.
 *
 * Auth: admin only (route group wires AdminAuthMiddleware).
 *
 * Used by the admin UI's currency-management view. Pairs with
 * the X.4-C audit log surface, operators can see who changed
 * what when via the audit_log table filtered on
 * subject_type='FxRate'.
 *
 * Response envelope:
 * {
 *   "data": [
 *     {
 *       "base_code": "AED",
 *       "target_code": "USD",
 *       "rate": "0.27225000",
 *       "updated_at": "2026-05-18T12:00:00+00:00",
 *       "updated_by_user_id": 42,
 *       "is_stale": false
 *     },
 *     ...
 *   ],
 *   "meta": {
 *     "supported_currencies": ["AED", "USD", "EUR", "SAR", "GBP"],
 *     "stale_after_hours": 48
 *   }
 * }
 */
final class ListFxRatesController
{
    use Responder;

    /** Mirrors CurrencyConversionService::STALE_AFTER_HOURS; kept in
     * sync manually since the constant is private to that service.
     * If staleness threshold ever changes, update both places. */
    private const STALE_AFTER_HOURS = 48;

    public function __construct(
        protected readonly ResponseFactoryInterface $responseFactory,
        private readonly EntityManagerInterface $em,
    ) {
    }

    protected function getResponseFactory(): ResponseFactoryInterface
    {
        return $this->responseFactory;
    }

    public function __invoke(
        ServerRequestInterface $request,
        ResponseInterface $response,
    ): ResponseInterface {
        /** @var FxRateRepository $repo */
        $repo = $this->em->getRepository(FxRate::class);
        $rates = $repo->findAllRates();

        $now = (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->getTimestamp();

        $data = array_map(function (FxRate $r) use ($now): array {
            $ageHours = (int) floor(($now - $r->getUpdatedAt()->getTimestamp()) / 3600);
            return [
                'base_code' => $r->getBaseCode(),
                'target_code' => $r->getTargetCode(),
                'rate' => $r->getRate(),
                'updated_at' => $r->getUpdatedAt()->format(\DateTimeInterface::ATOM),
                'updated_by_user_id' => $r->getUpdatedBy()?->getId(),
                'is_stale' => $ageHours >= self::STALE_AFTER_HOURS,
                'age_hours' => $ageHours,
            ];
        }, $rates);

        return $this->ok([
            'data' => $data,
            'meta' => [
                'supported_currencies' => Currency::supportedCodes(),
                'stale_after_hours' => self::STALE_AFTER_HOURS,
            ],
        ]);
    }
}
