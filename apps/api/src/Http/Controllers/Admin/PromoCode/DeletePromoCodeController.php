<?php

declare(strict_types=1);

namespace Bayti\Api\Http\Controllers\Admin\PromoCode;

use Bayti\Api\Domain\Audit\AuditEmitter;
use Bayti\Api\Domain\Promo\PromoCode;
use Bayti\Api\Domain\Promo\PromoCodeRepository;
use Bayti\Api\Domain\Promo\PromoRedemption;
use Bayti\Api\Domain\Promo\PromoRedemptionRepository;
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
 * DELETE /v3/admin/promo-codes/{id}
 *
 * Two delete modes (per plan §2.3 / §3-E):
 *
 *   - SOFT DELETE (default for codes with historical redemptions):
 *     sets is_active=false. Preserves FK integrity from
 *     promo_redemptions which would otherwise block hard delete
 *     (ON DELETE RESTRICT on promo_redemptions.promo_code_id).
 *
 *   - HARD DELETE (cleanup affordance for codes with zero
 *     redemptions): physically removes the row. Useful when an
 *     admin typo'd a code and wants it gone entirely rather than
 *     leaving a clutter row.
 *
 * The branch is automatic: count redemptions, pick the right path.
 * Admins don't need to specify; the schema constraint enforces
 * correctness.
 *
 * Idempotent for the soft-delete path: deleting an already-inactive
 * code with redemptions returns 204 without re-emitting an audit
 * row (the second call finds is_active=false already and short-
 * circuits).
 *
 * Audit: emits ACTION_DELETED with the before-snapshot.
 */
final class DeletePromoCodeController
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

        $before = $this->audit->snapshot($promo);

        /** @var PromoRedemptionRepository $redemptionRepo */
        $redemptionRepo = $this->em->getRepository(PromoRedemption::class);
        $redemptionCount = $redemptionRepo->countByPromoCodeIdGross($id);

        if ($redemptionCount === 0) {
            // Hard delete path: nothing FK-references this row, so we
            // can physically remove it. Saves clutter when admins
            // create a typo'd code and immediately want it gone.
            $repo->remove($promo);
        } else {
            // Soft delete path: set is_active=false. Idempotent -
            // already-inactive codes pass through unchanged.
            if ($promo->isActive()) {
                $promo->setActive(false);
                $this->em->flush();
            }
        }

        $this->audit->recordDelete(
            request: $request,
            actor: $user,
            subject: $promo,
            beforeSnapshot: $before,
        );

        return $this->noContent();
    }
}
