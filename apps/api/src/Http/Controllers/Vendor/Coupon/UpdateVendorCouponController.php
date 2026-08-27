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
use Bayti\Api\Http\PaginatedEnvelope;
use Bayti\Api\Http\Responder;
use Bayti\Api\Http\Serializers\PromoCodeSerializer;
use DateTimeImmutable;
use DateTimeZone;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/** PUT /v3/vendor/coupons/{id}, Update a vendor-owned promo code (partial). */
final class UpdateVendorCouponController
{
    use Responder;
    public function __construct(
        protected readonly ResponseFactoryInterface $responseFactory,
        private readonly EntityManagerInterface $em,
        private readonly PromoCodeSerializer $serializer,
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
        if ($code === null) throw HttpException::notFound('Coupon not found.');

        $body = (array) ($request->getParsedBody() ?? []);
        $tz   = new DateTimeZone('UTC');

        if (isset($body['description']))       $code->setDescription((string) $body['description']);
        if (isset($body['discount_value']))       $code->setDiscountValue((string) $body['discount_value']);
        if (isset($body['min_subtotal']))          $code->setMinSubtotal((string) $body['min_subtotal'] !== '' ? (string) $body['min_subtotal'] : null);
        if (isset($body['max_discount_amount']))   $code->setMaxDiscountAmount((string) $body['max_discount_amount'] !== '' ? (string) $body['max_discount_amount'] : null);
        if (isset($body['usage_limit_global']))    $code->setUsageLimitGlobal((string) $body['usage_limit_global'] !== '' ? (int) $body['usage_limit_global'] : null);
        if (isset($body['usage_limit_per_user']))  $code->setUsageLimitPerUser((string) $body['usage_limit_per_user'] !== '' ? (int) $body['usage_limit_per_user'] : null);
        if (isset($body['valid_from']))            $code->setValidFrom((string) $body['valid_from'] !== '' ? new DateTimeImmutable((string) $body['valid_from'], $tz) : null);
        if (isset($body['valid_until']))           $code->setValidUntil((string) $body['valid_until'] !== '' ? new DateTimeImmutable((string) $body['valid_until'], $tz) : null);
        if (isset($body['is_active']))             $code->setActive((bool) $body['is_active']);

        $repo->save($code);
        return $this->ok(PaginatedEnvelope::single($this->serializer->adminShape($code)));
    }
}
