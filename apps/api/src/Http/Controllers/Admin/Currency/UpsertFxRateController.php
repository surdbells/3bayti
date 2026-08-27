<?php

declare(strict_types=1);

namespace Bayti\Api\Http\Controllers\Admin\Currency;

use Bayti\Api\Domain\Audit\AuditEmitter;
use Bayti\Api\Domain\Currency\Currency;
use Bayti\Api\Domain\Currency\FxRate;
use Bayti\Api\Domain\Currency\FxRateRepository;
use Bayti\Api\Domain\User\User;
use Bayti\Api\Http\Errors\ErrorCodes;
use Bayti\Api\Http\Errors\HttpException;
use Bayti\Api\Http\Middleware\AuthMiddleware;
use Bayti\Api\Http\Responder;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * PUT /v3/admin/fx-rates/{target}
 *
 * Upsert the rate for AED→{target} (M3.2.X.15-F). The {target}
 * placeholder MUST match one of Currency::supportedCodes().
 *
 * Auth: admin only (route group wires AdminAuthMiddleware).
 *
 * Body:
 * {
 *   "rate": "0.27225000"     , required, decimal string,
 *                               validated by FxRate::setRate
 *                               (>0, <1000, /^\d+(\.\d+)?$/)
 * }
 *
 * Behavior:
 *   - Looks up existing rate by (AED, target). If found, updates
 *     the rate value + touches updated_at + records the actor
 *     in updated_by_user_id. Audited via recordUpdate with
 *     before/after diff.
 *   - If not found (shouldn't happen since X.15-A seeds all 5
 *     supported targets, but defensive), creates a new row.
 *     Audited via recordCreate.
 *
 * Q-AdminUI = A locked: simple POST/PUT upsert; bulk CSV deferred
 * to operator follow-up #29 when currency count grows.
 *
 * Q-AdminVisibility = C locked: uses existing audit_log table
 * via subject_type='FxRate'. No new audit infrastructure.
 *
 * Errors:
 *   400, body validation fails (rate out of range, non-numeric)
 *   404, {target} is not a supported currency
 *   422, body shape wrong (missing 'rate' field)
 *   403, caller not admin (from AdminAuthMiddleware)
 *
 * Response (200):
 * {
 *   "data": {
 *     "base_code": "AED",
 *     "target_code": "USD",
 *     "rate": "0.27225000",
 *     "updated_at": "2026-05-18T15:42:00+00:00",
 *     "updated_by_user_id": 42
 *   }
 * }
 */
final class UpsertFxRateController
{
    use Responder;

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

    /**
     * @param array<string, string> $args
     */
    public function __invoke(
        ServerRequestInterface $request,
        ResponseInterface $response,
        array $args = [],
    ): ResponseInterface {
        $user = $request->getAttribute(AuthMiddleware::ATTR_USER);
        if (!$user instanceof User) {
            throw HttpException::unauthorized(
                ErrorCodes::AUTH_INVALID_TOKEN,
                'Authentication required.',
            );
        }

        // 1. Validate target currency from path
        $rawTarget = $args['target'] ?? '';
        $targetUpper = strtoupper($rawTarget);
        if (!in_array($targetUpper, Currency::supportedCodes(), true)) {
            throw HttpException::notFound(
                "Unsupported target currency: {$rawTarget}.",
            );
        }
        // Reject AED specifically, identity rate is meaningless to
        // update; would let an admin accidentally set 'AED→AED = 0.5'
        // and break every conversion.
        if ($targetUpper === Currency::AED->value) {
            throw HttpException::validation([
                'target' => ['AED is the base currency; cannot be updated.'],
            ]);
        }
        $target = Currency::from($targetUpper);

        // 2. Validate body
        $body = $request->getParsedBody();
        if (!is_array($body)) {
            throw HttpException::validation([
                'body' => ['Request body must be a JSON object.'],
            ]);
        }
        $rateRaw = $body['rate'] ?? null;
        if (!is_string($rateRaw) || $rateRaw === '') {
            throw HttpException::validation([
                'rate' => ['Rate must be a decimal string.'],
            ]);
        }

        // 3. Upsert
        /** @var FxRateRepository $repo */
        $repo = $this->em->getRepository(FxRate::class);
        $existing = $repo->findByPair(Currency::AED->value, $target->value);

        if ($existing === null) {
            // Create path
            try {
                $rate = new FxRate(
                    baseCode: Currency::AED->value,
                    targetCode: $target->value,
                    rate: $rateRaw,
                    updatedBy: $user,
                );
            } catch (\InvalidArgumentException $e) {
                throw HttpException::validation([
                    'rate' => [$e->getMessage()],
                ]);
            }
            $this->em->persist($rate);
            $this->em->flush();

            $this->audit->recordCreate(
                request: $request,
                actor: $user,
                subject: $rate,
                afterSnapshot: $this->audit->snapshot($rate),
            );

            return $this->ok([
                'data' => $this->serialize($rate),
            ]);
        }

        // Update path
        $before = $this->audit->snapshot($existing);
        try {
            $existing->setRate($rateRaw);
        } catch (\InvalidArgumentException $e) {
            throw HttpException::validation([
                'rate' => [$e->getMessage()],
            ]);
        }
        $existing->touchUpdatedAt();
        $existing->setUpdatedBy($user);

        $this->em->flush();

        $this->audit->recordUpdate(
            request: $request,
            actor: $user,
            subject: $existing,
            beforeSnapshot: $before,
            afterSnapshot: $this->audit->snapshot($existing),
        );

        return $this->ok([
            'data' => $this->serialize($existing),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function serialize(FxRate $r): array
    {
        return [
            'base_code' => $r->getBaseCode(),
            'target_code' => $r->getTargetCode(),
            'rate' => $r->getRate(),
            'updated_at' => $r->getUpdatedAt()->format(\DateTimeInterface::ATOM),
            'updated_by_user_id' => $r->getUpdatedBy()?->getId(),
        ];
    }
}
