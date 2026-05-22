<?php declare(strict_types=1);
namespace Bayti\Api\Http\Controllers\Vendor\Settings;

use Bayti\Api\Domain\Catalog\Vendor;
use Bayti\Api\Domain\Catalog\VendorRepository;
use Bayti\Api\Domain\User\User;
use Bayti\Api\Http\Errors\ErrorCodes;
use Bayti\Api\Http\Errors\HttpException;
use Bayti\Api\Http\Middleware\AuthMiddleware;
use Bayti\Api\Http\Responder;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/** GET /v3/vendor/store/tax */
final class GetVendorTaxController
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

        /** @var VendorRepository $repo */
        $repo    = $this->em->getRepository(Vendor::class);
        $vendors = $repo->findByOwnerUser($user);
        if (empty($vendors)) throw HttpException::notFound('No vendor account found.');
        $v = $vendors[0];

        return $this->ok(['data' => [
            'store_legal_name'               => $v->getLegalName(),
            'trade_license_number'           => $v->getTradeLicenseNumber(),
            'licensing_authority'            => $v->getLicensingAuthority(),
            'tax_registration_number'        => $v->getTaxRegistrationNumber(),
            'vat_registration_effective_date'=> $v->getVatRegistrationEffectiveDate()?->format('Y-m-d'),
            'registered_tax_address'         => $v->getRegisteredTaxAddress(),
            'tax_contact_email'              => $v->getTaxContactEmail(),
            'vat_status'                     => $v->getVatStatus(),
        ]]);
    }
}
