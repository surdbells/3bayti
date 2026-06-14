<?php

declare(strict_types=1);

namespace Bayti\Api\Http\Controllers\Admin\Vendor\Compliance;

use Bayti\Api\Domain\Catalog\Vendor;
use Bayti\Api\Domain\Catalog\VendorRepository;
use Bayti\Api\Domain\Compliance\ComplianceDocumentService;
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
 * GET /v3/admin/vendors/{id}/compliance
 *
 * An admin reviewer's view of a vendor's KYC submission: the documents
 * (read from private storage into data URLs for this authenticated admin
 * response), the compliance status, and review metadata.
 */
final class GetAdminVendorComplianceController
{
    use Responder;

    public function __construct(
        protected readonly ResponseFactoryInterface $responseFactory,
        private readonly EntityManagerInterface $em,
        private readonly ComplianceDocumentService $docs,
    ) {
    }

    protected function getResponseFactory(): ResponseFactoryInterface
    {
        return $this->responseFactory;
    }

    /** @param array<string, string> $args */
    public function __invoke(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $user = $request->getAttribute(AuthMiddleware::ATTR_USER);
        if (!$user instanceof User) {
            throw HttpException::unauthorized(ErrorCodes::AUTH_INVALID_TOKEN, 'Authentication required.');
        }

        $idRaw = $args['id'] ?? '';
        if (!ctype_digit((string) $idRaw)) {
            throw HttpException::notFound('Vendor not found.');
        }

        /** @var VendorRepository $repo */
        $repo = $this->em->getRepository(Vendor::class);
        $vendor = $repo->find((int) $idRaw);
        if ($vendor === null) {
            throw HttpException::notFound('Vendor not found.');
        }

        return $this->ok(['data' => [
            'vendor_id'         => (int) $vendor->getId(),
            'store_name'        => $vendor->getName(),
            'front'             => $this->docs->readAsDataUrl($vendor->getIdFront()),
            'back'              => $this->docs->readAsDataUrl($vendor->getIdBack()),
            'license_doc'       => $this->docs->readAsDataUrl($vendor->getLicenseDoc()),
            'compliance_status' => $vendor->getComplianceStatus(),
            'reviewed_at'       => $vendor->getComplianceReviewedAt()?->format(\DateTimeInterface::ATOM),
            'reviewed_by'       => $vendor->getComplianceReviewedBy(),
            'review_note'       => $vendor->getComplianceReviewNote(),
        ]]);
    }
}
