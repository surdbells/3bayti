<?php

declare(strict_types=1);

namespace Bayti\Api\Http\Controllers\Vendor\Compliance;

use Bayti\Api\Domain\Catalog\Vendor;
use Bayti\Api\Domain\Catalog\VendorRepository;
use Bayti\Api\Domain\Compliance\ComplianceDocumentService;
use Bayti\Api\Domain\Compliance\ComplianceDocumentSigner;
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
 * The authenticated vendor's KYC documents (read from PRIVATE storage and
 * returned as base64 data URLs in this authenticated response only) plus
 * compliance status. Replaces the legacy reliance on the session blob.
 */
final class GetVendorComplianceController
{
    use Responder;

    public function __construct(
        protected readonly ResponseFactoryInterface $responseFactory,
        private readonly EntityManagerInterface $em,
        private readonly ComplianceDocumentService $docs,
        private readonly ComplianceDocumentSigner $signer,
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
            'front'             => $this->docUrl($request, $vendor->getId(), 'front', $vendor->getIdFront()),
            'back'              => $this->docUrl($request, $vendor->getId(), 'back', $vendor->getIdBack()),
            'license_doc'       => $this->docUrl($request, $vendor->getId(), 'license_doc', $vendor->getLicenseDoc()),
            'compliance_status' => $vendor->getComplianceStatus(),
            'review_note'       => $vendor->getComplianceReviewNote(),
            'reviewed_at'       => $vendor->getComplianceReviewedAt()?->format(\DateTimeInterface::ATOM),
            'is_active'         => $vendor->isApproved(),
        ]]);
    }

    /** Absolute, short-lived signed URL for a stored document, or null. */
    private function docUrl(ServerRequestInterface $request, int $vendorId, string $field, ?string $path): ?string
    {
        if ($path === null || $path === '') {
            return null;
        }
        $uri = $request->getUri();

        return $uri->getScheme() . '://' . $uri->getAuthority() . $this->signer->signedPath($vendorId, $field);
    }
}
