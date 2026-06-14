<?php

declare(strict_types=1);

namespace Bayti\Api\Http\Controllers\Compliance;

use Bayti\Api\Domain\Catalog\Vendor;
use Bayti\Api\Domain\Catalog\VendorRepository;
use Bayti\Api\Domain\Compliance\ComplianceDocumentService;
use Bayti\Api\Domain\Compliance\ComplianceDocumentSigner;
use Bayti\Api\Http\Errors\HttpException;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * GET /v3/compliance-documents/{vendorId}/{field}?exp=&sig=
 *
 * Streams a private KYC document. Authorised by a short-lived HMAC signature
 * (ComplianceDocumentSigner) rather than a Bearer token, so the vendor's
 * compliance screen and the admin review screen can reference the URL directly
 * in an <img>/link. The signed URLs are minted only inside the authenticated
 * compliance GET responses. Bytes are streamed from PRIVATE storage and never
 * cached.
 */
final class ServeComplianceDocumentController
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly ComplianceDocumentService $docs,
        private readonly ComplianceDocumentSigner $signer,
    ) {
    }

    public function __invoke(
        ServerRequestInterface $request,
        ResponseInterface $response,
        array $args,
    ): ResponseInterface {
        $vendorId = (int) ($args['vendorId'] ?? 0);
        $field = (string) ($args['field'] ?? '');
        if ($vendorId <= 0 || !in_array($field, ComplianceDocumentSigner::FIELDS, true)) {
            throw HttpException::notFound('Document not found.');
        }

        $query = $request->getQueryParams();
        $exp = (int) ($query['exp'] ?? 0);
        $sig = (string) ($query['sig'] ?? '');
        if ($exp <= 0 || $sig === '' || !$this->signer->verify($vendorId, $field, $exp, $sig)) {
            throw HttpException::notFound('Document not found.');
        }

        /** @var VendorRepository $repo */
        $repo = $this->em->getRepository(Vendor::class);
        $vendor = $repo->find($vendorId);
        if (!$vendor instanceof Vendor) {
            throw HttpException::notFound('Document not found.');
        }

        $path = match ($field) {
            'front'       => $vendor->getIdFront(),
            'back'        => $vendor->getIdBack(),
            'license_doc' => $vendor->getLicenseDoc(),
            default       => null,
        };

        $doc = $this->docs->openForDownload($path);
        if ($doc === null) {
            throw HttpException::notFound('Document not found.');
        }

        $response->getBody()->write($doc['bytes']);

        return $response
            ->withHeader('Content-Type', $doc['mime'])
            ->withHeader('Content-Length', (string) strlen($doc['bytes']))
            ->withHeader('Content-Disposition', 'inline')
            ->withHeader('Cache-Control', 'private, no-store')
            ->withHeader('X-Content-Type-Options', 'nosniff');
    }
}
