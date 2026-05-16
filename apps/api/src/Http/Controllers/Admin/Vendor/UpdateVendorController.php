<?php

declare(strict_types=1);

namespace Bayti\Api\Http\Controllers\Admin\Vendor;

use Bayti\Api\Domain\Audit\AuditEmitter;
use Bayti\Api\Domain\Catalog\Vendor;
use Bayti\Api\Domain\Catalog\VendorRepository;
use Bayti\Api\Domain\User\User;
use Bayti\Api\Http\Controllers\Admin\Vendor\Dto\UpdateVendorInput;
use Bayti\Api\Http\Errors\ErrorCodes;
use Bayti\Api\Http\Errors\HttpException;
use Bayti\Api\Http\Middleware\AuthMiddleware;
use Bayti\Api\Http\Responder;
use Bayti\Api\Http\Serializers\VendorSerializer;
use Bayti\Api\Http\Validator\RequestValidator;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * PUT /v3/admin/vendors/{id}
 */
final class UpdateVendorController
{
    use Responder;

    public function __construct(
        protected readonly ResponseFactoryInterface $responseFactory,
        private readonly RequestValidator $validator,
        private readonly EntityManagerInterface $em,
        private readonly VendorSerializer $serializer,
        private readonly AuditEmitter $audit,
    ) {
    }

    protected function getResponseFactory(): ResponseFactoryInterface
    {
        return $this->responseFactory;
    }

    public function __invoke(
        ServerRequestInterface $request,
        ResponseInterface $response,
        array $args,
    ): ResponseInterface {
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

        $input = $this->validator->parse($request, UpdateVendorInput::class);

        $before = $this->audit->snapshot($vendor);

        $vendor->setName($input->name);
        $vendor->setContactEmail($input->contact_email);

        if ($input->slug !== null && $input->slug !== $vendor->getSlug()) {
            if ($repo->slugExists($input->slug, excludeId: $vendor->getId())) {
                throw HttpException::conflict(
                    'slug_taken',
                    "Slug '{$input->slug}' is already taken.",
                );
            }
            $vendor->setSlug($input->slug);
        }

        $vendor->setDescription($input->description);
        $vendor->setLogoUrl($input->logo_url);
        $vendor->setCoverImageUrl($input->cover_image_url);
        $vendor->setContactPhone($input->contact_phone);

        if ($input->commission_rate !== null) {
            $vendor->setCommissionRate($input->commission_rate);
        }
        if ($input->is_active !== null) {
            $vendor->setActive($input->is_active);
        }
        if ($input->is_verified !== null) {
            $vendor->setVerified($input->is_verified);
        }
        if ($input->is_featured !== null) {
            // M3.2.X.2-D: Designer Spotlight curation toggle.
            // The audit snapshot before/after pair below captures
            // this change automatically via AuditEmitter::snapshot
            // (which reads all entity fields).
            $vendor->setFeatured($input->is_featured);
        }

        $this->em->flush();

        $this->audit->recordUpdate(
            request: $request,
            actor: $user,
            subject: $vendor,
            beforeSnapshot: $before,
            afterSnapshot: $this->audit->snapshot($vendor),
        );

        return $this->ok([
            'vendor' => $this->serializer->adminShape($vendor),
        ]);
    }
}
