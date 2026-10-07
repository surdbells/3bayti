<?php

declare(strict_types=1);

namespace Bayti\Api\Http\Controllers\Admin\Vendor;

use Bayti\Api\Domain\Catalog\Vendor;
use Bayti\Api\Domain\Catalog\VendorLabel;
use Bayti\Api\Domain\Catalog\VendorLabelRepository;
use Bayti\Api\Domain\Catalog\VendorRepository;
use Bayti\Api\Http\Errors\HttpException;
use Bayti\Api\Http\Responder;
use Bayti\Api\Http\Serializers\VendorLabelSerializer;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * POST /v3/admin/vendors/{id}/labels
 *
 * Create a label for the store in the PATH (v3 vendor id), so the admin
 * product form's "add label" creates it under the product's store rather than
 * under the admin's own session (POST /v3/vendor/labels resolves the store
 * from the caller, which is wrong for an admin).
 *
 * Mirrors POST /v3/vendor/labels: body { label } (or { name }), trimmed;
 * blank -> 400; slug = kebab-cased name + random suffix
 * (VendorLabel::generateSlug), so duplicate names are allowed and never
 * collide; 201 { data: <manage shape> }. Additionally rejects a name longer
 * than the VARCHAR(150) column with a 422 instead of a DB error.
 *
 * Audit: like the vendor create, no explicit AuditEmitter call; the Doctrine
 * EntityAuditListener records the VendorLabel insert with the admin as actor.
 *
 * Authorization: admin group middleware + products.edit (routes.php).
 */
final class CreateAdminVendorLabelController
{
    use Responder;

    public function __construct(
        protected readonly ResponseFactoryInterface $responseFactory,
        private readonly EntityManagerInterface $em,
        private readonly VendorLabelSerializer $serializer,
    ) {
    }

    protected function getResponseFactory(): ResponseFactoryInterface
    {
        return $this->responseFactory;
    }

    /**
     * @param array<string, string> $args
     */
    public function __invoke(
        ServerRequestInterface $request,
        ResponseInterface $_response,
        array $args,
    ): ResponseInterface {
        $rawId = (string) ($args['id'] ?? '');
        if ($rawId === '' || !ctype_digit($rawId)) {
            throw HttpException::notFound('Vendor not found.');
        }

        /** @var VendorRepository $vendorRepo */
        $vendorRepo = $this->em->getRepository(Vendor::class);
        $vendor = $vendorRepo->find((int) $rawId);
        if ($vendor === null) {
            throw HttpException::notFound('Vendor not found.');
        }

        $body = (array) ($request->getParsedBody() ?? []);
        $raw  = $body['label'] ?? $body['name'] ?? '';
        $name = is_scalar($raw) ? trim((string) $raw) : '';
        if ($name === '') {
            throw HttpException::badRequest('label name is required.');
        }
        if (mb_strlen($name) > VendorLabel::MAX_NAME_LENGTH) {
            throw HttpException::validation([
                'label' => ['Label name must be ' . VendorLabel::MAX_NAME_LENGTH . ' characters or fewer.'],
            ]);
        }

        $label = new VendorLabel($vendor, VendorLabel::generateSlug($name), $name);

        /** @var VendorLabelRepository $labelRepo */
        $labelRepo = $this->em->getRepository(VendorLabel::class);
        $labelRepo->save($label);

        return $this->created(['data' => $this->serializer->manageShape($label)]);
    }
}
