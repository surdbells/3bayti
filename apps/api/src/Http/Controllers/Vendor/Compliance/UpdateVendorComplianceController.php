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
 * PATCH /v3/vendor/compliance
 *
 * Submit/replace the authenticated vendor's KYC documents (front, back,
 * license_doc — base64 data URLs). Moves compliance to 'submitted'.
 * Replaces the legacy /vendors/settings/update-compliance call AND the
 * incorrect use of the onboarding (vendor-creation) endpoint.
 */
final class UpdateVendorComplianceController
{
    use Responder;

    /** Guard against oversized payloads (base64 of a few MB image). */
    private const MAX_DOC_LEN = 12_000_000;

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

        $body = (array) ($request->getParsedBody() ?? []);
        $front = $this->doc($body, 'front');
        $back = $this->doc($body, 'back');
        $license = $this->doc($body, 'license_doc');

        if ($front === null && $back === null && $license === null) {
            throw HttpException::badRequest('At least one document (front, back, or license_doc) is required.');
        }

        $vendor->submitCompliance($front, $back, $license);
        $repo->save($vendor);

        return $this->ok(['data' => [
            'front'             => $vendor->getIdFront(),
            'back'              => $vendor->getIdBack(),
            'license_doc'       => $vendor->getLicenseDoc(),
            'compliance_status' => $vendor->getComplianceStatus(),
        ]]);
    }

    /**
     * Read a document field — treats empty strings and bare placeholders
     * as "not provided" (null) so an unchanged placeholder doesn't wipe a
     * previously-stored document.
     */
    private function doc(array $body, string $key): ?string
    {
        $val = $body[$key] ?? null;
        if (!is_string($val)) {
            return null;
        }
        $val = trim($val);
        if ($val === '' || str_contains($val, 'placeholder') || strlen($val) < 40) {
            return null;
        }
        if (strlen($val) > self::MAX_DOC_LEN) {
            throw HttpException::badRequest("Document '{$key}' is too large.");
        }
        return $val;
    }
}
