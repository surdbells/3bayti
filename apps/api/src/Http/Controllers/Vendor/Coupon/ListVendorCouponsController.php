<?php

declare(strict_types=1);

namespace Bayti\Api\Http\Controllers\Vendor\Coupon;

use Bayti\Api\Domain\Catalog\Vendor;
use Bayti\Api\Domain\Catalog\VendorRepository;
use Bayti\Api\Domain\Promo\PromoCode;
use Bayti\Api\Domain\Promo\PromoCodeRepository;
use Bayti\Api\Domain\Promo\PromoCodeSerializer;
use Bayti\Api\Domain\User\User;
use Bayti\Api\Http\Errors\ErrorCodes;
use Bayti\Api\Http\Errors\HttpException;
use Bayti\Api\Http\Middleware\AuthMiddleware;
use Bayti\Api\Http\Responder;
use Bayti\Api\Http\Serializers\PromoCodeSerializer as PromoSerializer;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * GET /v3/vendor/coupons[?limit=&offset=]
 * List the authenticated vendor's own promo codes, newest first.
 */
final class ListVendorCouponsController
{
    use Responder;

    public function __construct(
        protected readonly ResponseFactoryInterface $responseFactory,
        private readonly EntityManagerInterface $em,
        private readonly PromoSerializer $serializer,
    ) {}

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

        /** @var VendorRepository $vendorRepo */
        $vendorRepo = $this->em->getRepository(Vendor::class);
        $vendors    = $vendorRepo->findByOwnerUser($user);
        if (empty($vendors)) {
            throw HttpException::forbidden('No approved vendor account found.');
        }
        $vendorId = (int) $vendors[0]->getId();

        $q      = $request->getQueryParams();
        $limit  = max(1, min(100, (int) ($q['limit']  ?? 20)));
        $offset = max(0, (int) ($q['offset'] ?? 0));

        /** @var PromoCodeRepository $repo */
        $repo  = $this->em->getRepository(PromoCode::class);
        $items = $repo->findByVendorId($vendorId, $limit, $offset);
        $total = $repo->countByVendorId($vendorId);

        return $this->ok([
            'data' => array_map([$this->serializer, 'adminShape'], $items),
            'meta' => ['total' => $total, 'limit' => $limit, 'offset' => $offset],
        ]);
    }
}
