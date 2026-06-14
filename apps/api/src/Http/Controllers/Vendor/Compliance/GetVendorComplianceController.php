<?php

declare(strict_types=1);

namespace Bayti\Api\Http\Controllers\Vendor\Compliance;

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

/**
 * GET /v3/vendor/compliance
 *
 * The authenticated vendor's KYC documents + compliance status, for the
 * compliance page. Replaces the legacy reliance on the session blob's
 * id_front/id_back/license_doc fields.
 */
final class GetVendorComplianceController
{
    use Responder;

    public function __construct(
        protected readonly ResponseFactoryInterface $responseFactory,
        private readonly EntityManagerInterface $em,
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

        /** @var VendorRepository $repo */
        $repo = $this->em->getRepository(Vendor::class);
        $vendors = $repo->findByOwnerUser($user);
        if ($vendors === []) {
            throw HttpException::forbidden('No vendor account found.');
        }
        $vendor = $vendors[0];

        return $this->ok(['data' => [
            'front'             => $vendor->getIdFront(),
            'back'              => $vendor->getIdBack(),
            'license_doc'       => $vendor->getLicenseDoc(),
            'compliance_status' => $vendor->getComplianceStatus(),
            'is_active'         => $vendor->isApproved(),
        ]]);
    }
}
