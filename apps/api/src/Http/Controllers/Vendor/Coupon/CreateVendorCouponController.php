<?php declare(strict_types=1);
namespace Bayti\Api\Http\Controllers\Vendor\Coupon;

use Bayti\Api\Domain\Catalog\Vendor;
use Bayti\Api\Domain\Catalog\VendorRepository;
use Bayti\Api\Domain\Promo\PromoCode;
use Bayti\Api\Domain\Promo\PromoCodeRepository;
use Bayti\Api\Domain\User\User;
use Bayti\Api\Http\Controllers\Admin\PromoCode\Dto\CreatePromoCodeInput;
use Bayti\Api\Http\Errors\ErrorCodes;
use Bayti\Api\Http\Errors\HttpException;
use Bayti\Api\Http\Middleware\AuthMiddleware;
use Bayti\Api\Http\PaginatedEnvelope;
use Bayti\Api\Http\Responder;
use Bayti\Api\Http\Serializers\PromoCodeSerializer;
use Bayti\Api\Http\Validator\RequestValidator;
use DateTimeImmutable;
use DateTimeZone;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/** POST /v3/vendor/coupons, Create a vendor-owned promo code. */
final class CreateVendorCouponController
{
    use Responder;
    public function __construct(
        protected readonly ResponseFactoryInterface $responseFactory,
        private readonly RequestValidator $validator,
        private readonly EntityManagerInterface $em,
        private readonly PromoCodeSerializer $serializer,
    ) {}
    protected function getResponseFactory(): ResponseFactoryInterface { return $this->responseFactory; }

    public function __invoke(ServerRequestInterface $request): ResponseInterface
    {
        $user = $request->getAttribute(AuthMiddleware::ATTR_USER);
        if (!$user instanceof User) throw HttpException::unauthorized(ErrorCodes::AUTH_INVALID_TOKEN, 'Authentication required.');

        /** @var VendorRepository $vendorRepo */
        $vendorRepo = $this->em->getRepository(Vendor::class);
        $vendors    = $vendorRepo->findByOwnerUser($user);
        if (empty($vendors)) throw HttpException::forbidden('No approved vendor account found.');
        $vendorId = (int) $vendors[0]->getId();

        $input = $this->validator->parse($request, CreatePromoCodeInput::class);
        $tz    = new DateTimeZone('UTC');

        $code = new PromoCode($input->code, $input->discount_type, $input->discount_value);
        $code->setCurrency($input->currency ?? 'AED');
        if ($input->description !== null)       $code->setDescription($input->description);
        if ($input->min_subtotal !== null)       $code->setMinSubtotal($input->min_subtotal);
        if ($input->max_discount_amount !== null) $code->setMaxDiscountAmount($input->max_discount_amount);
        if ($input->usage_limit_global !== null)  $code->setUsageLimitGlobal($input->usage_limit_global);
        if ($input->usage_limit_per_user !== null) $code->setUsageLimitPerUser($input->usage_limit_per_user);
        if ($input->valid_from !== null)         $code->setValidFrom(new DateTimeImmutable($input->valid_from, $tz));
        if ($input->valid_until !== null)        $code->setValidUntil(new DateTimeImmutable($input->valid_until, $tz));
        $code->setVendorId($vendorId);

        /** @var PromoCodeRepository $repo */
        $repo = $this->em->getRepository(PromoCode::class);
        $repo->save($code);

        return $this->created(PaginatedEnvelope::single($this->serializer->adminShape($code)));
    }
}
