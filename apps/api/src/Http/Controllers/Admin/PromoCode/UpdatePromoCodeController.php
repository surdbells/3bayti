<?php

declare(strict_types=1);

namespace Bayti\Api\Http\Controllers\Admin\PromoCode;

use Bayti\Api\Domain\Audit\AuditEmitter;
use Bayti\Api\Domain\Promo\PromoCode;
use Bayti\Api\Domain\Promo\PromoCodeRepository;
use Bayti\Api\Domain\User\User;
use Bayti\Api\Http\Controllers\Admin\PromoCode\Dto\UpdatePromoCodeInput;
use Bayti\Api\Http\Errors\ErrorCodes;
use Bayti\Api\Http\Errors\HttpException;
use Bayti\Api\Http\Middleware\AuthMiddleware;
use Bayti\Api\Http\Responder;
use Bayti\Api\Http\Serializers\PromoCodeSerializer;
use Bayti\Api\Http\Validator\RequestValidator;
use DateTimeImmutable;
use DateTimeZone;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * PUT /v3/admin/promo-codes/{id}
 *
 * Partial update, only fields present in the body (non-null) are
 * applied. Mirrors the UpdateBrandController convention.
 *
 * Code uniqueness:
 *   - If `code` is being changed AND the new value is already taken
 *     by a DIFFERENT row → 409 Conflict.
 *
 * Audit:
 *   - Emits ACTION_UPDATED with before+after snapshots. The
 *     AuditEmitter diff machinery surfaces only fields that
 *     actually changed.
 *
 * Failure modes:
 *   - 404, id not numeric or not found
 *   - 409, new code conflicts with existing
 *   - 422, entity-level validation (e.g. percentage > 100)
 */
final class UpdatePromoCodeController
{
    use Responder;

    public function __construct(
        protected readonly ResponseFactoryInterface $responseFactory,
        private readonly RequestValidator $validator,
        private readonly EntityManagerInterface $em,
        private readonly PromoCodeSerializer $serializer,
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
        array $args,
    ): ResponseInterface {
        $user = $request->getAttribute(AuthMiddleware::ATTR_USER);
        if (!$user instanceof User) {
            throw HttpException::unauthorized(ErrorCodes::AUTH_INVALID_TOKEN, 'Authentication required.');
        }

        $idRaw = $args['id'] ?? '';
        if (!ctype_digit((string) $idRaw)) {
            throw HttpException::notFound('Promo code not found.');
        }
        $id = (int) $idRaw;

        /** @var PromoCodeRepository $repo */
        $repo = $this->em->getRepository(PromoCode::class);
        $promo = $repo->find($id);
        if ($promo === null) {
            throw HttpException::notFound('Promo code not found.');
        }

        $input = $this->validator->parse($request, UpdatePromoCodeInput::class);

        $before = $this->audit->snapshot($promo);

        try {
            // Code change requires uniqueness recheck.
            if ($input->code !== null && $input->code !== $promo->getCode()) {
                $existing = $repo->findByNormalizedCode($input->code);
                if ($existing !== null && $existing->getId() !== $promo->getId()) {
                    throw HttpException::conflict(
                        'promo_code_taken',
                        "Promo code '{$input->code}' is already taken.",
                    );
                }
                $promo->setCode($input->code);
            }
            if ($input->description !== null) {
                $promo->setDescription($input->description);
            }
            if ($input->discount_type !== null) {
                $promo->setDiscountType($input->discount_type);
            }
            if ($input->discount_value !== null) {
                $promo->setDiscountValue($input->discount_value);
            }
            if ($input->currency !== null) {
                $promo->setCurrency($input->currency);
            }
            if ($input->min_subtotal !== null) {
                $promo->setMinSubtotal($input->min_subtotal);
            }
            if ($input->max_discount_amount !== null) {
                $promo->setMaxDiscountAmount($input->max_discount_amount);
            }
            if ($input->usage_limit_global !== null) {
                $promo->setUsageLimitGlobal($input->usage_limit_global);
            }
            if ($input->usage_limit_per_user !== null) {
                $promo->setUsageLimitPerUser($input->usage_limit_per_user);
            }
            if ($input->valid_from !== null) {
                $promo->setValidFrom($this->parseDate($input->valid_from, 'valid_from'));
            }
            if ($input->valid_until !== null) {
                $promo->setValidUntil($this->parseDate($input->valid_until, 'valid_until'));
            }
            if ($input->is_active !== null) {
                $promo->setActive($input->is_active);
            }
        } catch (\InvalidArgumentException $e) {
            throw HttpException::validation(['_root' => [$e->getMessage()]]);
        }

        // Cross-field bounds check after all setters have applied.
        if ($promo->getValidFrom() !== null
            && $promo->getValidUntil() !== null
            && $promo->getValidFrom() > $promo->getValidUntil()
        ) {
            throw HttpException::validation([
                'valid_until' => ['valid_until must be on or after valid_from.'],
            ]);
        }

        $this->em->flush();

        $after = $this->audit->snapshot($promo);
        $this->audit->recordUpdate(
            request: $request,
            actor: $user,
            subject: $promo,
            beforeSnapshot: $before,
            afterSnapshot: $after,
        );

        return $this->ok([
            'data' => $this->serializer->adminShape($promo),
        ]);
    }

    private function parseDate(string $raw, string $paramName): DateTimeImmutable
    {
        try {
            return new DateTimeImmutable($raw, new DateTimeZone('UTC'));
        } catch (\Exception $e) {
            throw HttpException::validation([
                $paramName => ["$paramName must be a valid ISO 8601 datetime."],
            ]);
        }
    }
}
