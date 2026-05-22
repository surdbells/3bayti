<?php declare(strict_types=1);
namespace Bayti\Api\Http\Controllers\Vendor\Coupon;

use Bayti\Api\Domain\Catalog\Vendor;
use Bayti\Api\Domain\Catalog\VendorRepository;
use Bayti\Api\Domain\Promo\PromoCode;
use Bayti\Api\Domain\Promo\PromoCodeRepository;
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
 * DELETE /v3/vendor/coupons/{id}
 * Soft-delete (deactivate) a vendor-owned coupon. Idempotent.
 */
final class DeleteVendorCouponController
{
    use Responder;
    public function __construct(
        protected readonly ResponseFactoryInterface $responseFactory,
        private readonly EntityManagerInterface $em,
    ) {}
    protected function getResponseFactory(): ResponseFactoryInterface { return $this->responseFactory; }

    public function __invoke(ServerRequestInterface $request): ResponseInterface
    {
        $user = $request->getAttribute(AuthMiddleware::ATTR_USER);
        if (!$user instanceof User) throw HttpException::unauthorized(ErrorCodes::AUTH_INVALID_TOKEN, 'Authentication required.');

        $id = (int) $request->getAttribute('id');

        /** @var VendorRepository $vendorRepo */
        $vendorRepo = $this->em->getRepository(Vendor::class);
        $vendors    = $vendorRepo->findByOwnerUser($user);
        if (empty($vendors)) throw HttpException::forbidden('No approved vendor account found.');
        $vendorId = (int) $vendors[0]->getId();

        /** @var PromoCodeRepository $repo */
        $repo = $this->em->getRepository(PromoCode::class);
        $code = $repo->findByIdAndVendor($id, $vendorId);
        if ($code === null) return $this->noContent(); // idempotent

        $code->setActive(false);
        $repo->save($code);

        return $this->noContent();
    }
}
