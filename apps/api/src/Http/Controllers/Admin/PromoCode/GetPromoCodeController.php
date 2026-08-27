<?php

declare(strict_types=1);

namespace Bayti\Api\Http\Controllers\Admin\PromoCode;

use Bayti\Api\Domain\Promo\PromoCode;
use Bayti\Api\Domain\Promo\PromoCodeRepository;
use Bayti\Api\Domain\Promo\PromoRedemption;
use Bayti\Api\Domain\Promo\PromoRedemptionRepository;
use Bayti\Api\Domain\User\User;
use Bayti\Api\Http\Errors\ErrorCodes;
use Bayti\Api\Http\Errors\HttpException;
use Bayti\Api\Http\Middleware\AuthMiddleware;
use Bayti\Api\Http\Responder;
use Bayti\Api\Http\Serializers\PromoCodeSerializer;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * GET /v3/admin/promo-codes/{id}
 *
 * Returns a single promo code with its redemption count attached.
 * The count is the GROSS figure (including redemptions tied to
 * cancelled / failed orders), operationally meaningful for "how
 * many times has this code been used" reports. The effective count
 * (used by the resolver's limit enforcement) is documented in
 * PromoRedemptionRepository.
 *
 * Failure modes:
 *   - Non-numeric id → 404
 *   - Id not found → 404
 *   - Non-admin caller → 403 (AdminAuthMiddleware)
 *
 * No audit, read-only single lookups are typically not audited
 * (only list views, per the convention in
 * ListNotificationLogsController).
 */
final class GetPromoCodeController
{
    use Responder;

    public function __construct(
        protected readonly ResponseFactoryInterface $responseFactory,
        private readonly EntityManagerInterface $em,
        private readonly PromoCodeSerializer $serializer,
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

        /** @var PromoRedemptionRepository $redemptionRepo */
        $redemptionRepo = $this->em->getRepository(PromoRedemption::class);
        $count = $redemptionRepo->countByPromoCodeIdGross($id);

        return $this->ok([
            'data' => $this->serializer->adminShapeWithCount($promo, $count),
        ]);
    }
}
