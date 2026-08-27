<?php

declare(strict_types=1);

namespace Bayti\Api\Http\Controllers\Admin\PromoCode;

use Bayti\Api\Domain\Audit\AuditEmitter;
use Bayti\Api\Domain\Promo\PromoCode;
use Bayti\Api\Domain\Promo\PromoCodeRepository;
use Bayti\Api\Domain\User\User;
use Bayti\Api\Http\Controllers\Admin\PromoCode\Dto\CreatePromoCodeInput;
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
 * POST /v3/admin/promo-codes
 *
 * Create a new promo code. The body shape (CreatePromoCodeInput)
 * requires code + discount_type + discount_value; everything else
 * is optional.
 *
 * Code uniqueness:
 *   - Pre-flight `findByNormalizedCode` check returns 409 if taken.
 *   - DB-level functional UNIQUE index on UPPER(code) is the
 *     backstop against race conditions.
 *
 * Validation cascade:
 *   - Symfony validator: shape + format (regex on decimals, enum
 *     check on discount_type, etc.)
 *   - PromoCode constructor + setters: range checks (percentage
 *     must be 0 < v <= 100; money strings DECIMAL(10,2); etc.)
 *    , these throw InvalidArgumentException which the controller
 *     wraps to 422 with a friendly error code.
 *
 * Returns 201 Created with the admin shape.
 * Audit: emits ACTION_CREATED with the snapshotted promo state.
 */
final class CreatePromoCodeController
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

    public function __invoke(ServerRequestInterface $request): ResponseInterface
    {
        $user = $request->getAttribute(AuthMiddleware::ATTR_USER);
        if (!$user instanceof User) {
            throw HttpException::unauthorized(ErrorCodes::AUTH_INVALID_TOKEN, 'Authentication required.');
        }

        $input = $this->validator->parse($request, CreatePromoCodeInput::class);

        /** @var PromoCodeRepository $repo */
        $repo = $this->em->getRepository(PromoCode::class);

        // Pre-flight uniqueness check. Race-safe via the DB UNIQUE
        // index, this 409 is just the friendly path.
        if ($repo->findByNormalizedCode($input->code) !== null) {
            throw HttpException::conflict(
                'promo_code_taken',
                "Promo code '{$input->code}' is already taken.",
            );
        }

        try {
            $promo = new PromoCode(
                code: $input->code,
                discountType: $input->discount_type,
                discountValue: $input->discount_value,
            );
            $promo->setCurrency($input->currency);
            if ($input->description !== null) {
                $promo->setDescription($input->description);
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
            $promo->setActive($input->is_active);
        } catch (\InvalidArgumentException $e) {
            // Domain-layer validation (range checks etc.), surface as
            // 422 with the entity's own message so admin UI can render.
            throw HttpException::validation(['_root' => [$e->getMessage()]]);
        }

        // Cross-field bounds check: valid_from <= valid_until.
        if ($promo->getValidFrom() !== null
            && $promo->getValidUntil() !== null
            && $promo->getValidFrom() > $promo->getValidUntil()
        ) {
            throw HttpException::validation([
                'valid_until' => ['valid_until must be on or after valid_from.'],
            ]);
        }

        $repo->save($promo);

        $this->audit->recordCreate(
            request: $request,
            actor: $user,
            subject: $promo,
            afterSnapshot: $this->audit->snapshot($promo),
        );

        return $this->created([
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
