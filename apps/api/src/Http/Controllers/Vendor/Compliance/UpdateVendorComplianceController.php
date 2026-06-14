<?php

declare(strict_types=1);

namespace Bayti\Api\Http\Controllers\Vendor\Compliance;

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
 * PATCH /v3/vendor/compliance
 *
 * Submit/replace the authenticated vendor's KYC documents (front, back,
 * license_doc — base64 data URLs). Documents are stored as PRIVATE files
 * (the vendor row holds only the path); compliance moves to 'submitted'.
 * Replaces the legacy /vendors/settings/update-compliance call AND the
 * incorrect use of the onboarding (vendor-creation) endpoint.
 */
final class UpdateVendorComplianceController
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
        $vendorId = (int) $vendor->getId();

        $body = (array) ($request->getParsedBody() ?? []);

        $providedFront = $this->isProvided($body, 'front');
        $providedBack = $this->isProvided($body, 'back');
        $providedLicense = $this->isProvided($body, 'license_doc');

        if (!$providedFront && !$providedBack && !$providedLicense) {
            throw HttpException::badRequest('At least one document (front, back, or license_doc) is required.');
        }

        // Capture old paths so replaced files can be deleted after save.
        $oldFront = $vendor->getIdFront();
        $oldBack = $vendor->getIdBack();
        $oldLicense = $vendor->getLicenseDoc();

        try {
            $newFront = $providedFront ? $this->docs->store($vendorId, 'front', (string) $body['front']) : null;
            $newBack = $providedBack ? $this->docs->store($vendorId, 'back', (string) $body['back']) : null;
            $newLicense = $providedLicense ? $this->docs->store($vendorId, 'license', (string) $body['license_doc']) : null;
        } catch (\InvalidArgumentException $e) {
            throw HttpException::badRequest($e->getMessage());
        }

        $vendor->submitCompliance($newFront, $newBack, $newLicense);
        $repo->save($vendor);

        // Orphan cleanup — delete the files we just replaced.
        if ($newFront !== null) {
            $this->docs->delete($oldFront);
        }
        if ($newBack !== null) {
            $this->docs->delete($oldBack);
        }
        if ($newLicense !== null) {
            $this->docs->delete($oldLicense);
        }

        return $this->ok(['data' => [
            'compliance_status' => $vendor->getComplianceStatus(),
            'has_front'         => $vendor->getIdFront() !== null,
            'has_back'          => $vendor->getIdBack() !== null,
            'has_license_doc'   => $vendor->getLicenseDoc() !== null,
        ]]);
    }

    /**
     * Whether a document field carries a real new upload (a base64 data
     * URL) — empty strings, placeholders, and short/echoed values are
     * treated as "leave unchanged".
     */
    private function isProvided(array $body, string $key): bool
    {
        $val = $body[$key] ?? null;
        if (!is_string($val)) {
            return false;
        }
        $val = trim($val);
        return str_starts_with($val, 'data:') && strlen($val) >= 40;
    }
}
