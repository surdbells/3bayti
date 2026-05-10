<?php

declare(strict_types=1);

namespace Bayti\Api\Http\Controllers\Admin\Vendor;

use Bayti\Api\Domain\Audit\AuditEmitter;
use Bayti\Api\Domain\Catalog\Vendor;
use Bayti\Api\Domain\Catalog\VendorRepository;
use Bayti\Api\Domain\Common\SlugHelper;
use Bayti\Api\Domain\User\User;
use Bayti\Api\Http\Controllers\Admin\Vendor\Dto\CreateVendorInput;
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
 * POST /v3/admin/vendors
 *
 * Creates a new vendor. Slug auto-generated from name if not provided.
 * Commission rate defaults to 10.00 if not supplied.
 * New vendors start with is_active=true, is_verified=false.
 */
final class CreateVendorController
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

    public function __invoke(ServerRequestInterface $request): ResponseInterface
    {
        $user = $request->getAttribute(AuthMiddleware::ATTR_USER);
        if (!$user instanceof User) {
            throw HttpException::unauthorized(ErrorCodes::AUTH_INVALID_TOKEN, 'Authentication required.');
        }

        $input = $this->validator->parse($request, CreateVendorInput::class);

        /** @var VendorRepository $repo */
        $repo = $this->em->getRepository(Vendor::class);

        // Resolve slug
        if ($input->slug !== null) {
            if ($repo->slugExists($input->slug)) {
                throw HttpException::conflict(
                    'slug_taken',
                    "Slug '{$input->slug}' is already taken.",
                );
            }
            $slug = $input->slug;
        } else {
            $slug = SlugHelper::generateUnique(
                $input->name,
                static fn (string $candidate): bool => $repo->slugExists($candidate),
            );
        }

        $vendor = new Vendor(
            slug: $slug,
            name: $input->name,
            contactEmail: $input->contact_email,
        );

        if ($input->description !== null) {
            $vendor->setDescription($input->description);
        }
        if ($input->logo_url !== null) {
            $vendor->setLogoUrl($input->logo_url);
        }
        if ($input->cover_image_url !== null) {
            $vendor->setCoverImageUrl($input->cover_image_url);
        }
        if ($input->contact_phone !== null) {
            $vendor->setContactPhone($input->contact_phone);
        }
        if ($input->commission_rate !== null) {
            $vendor->setCommissionRate($input->commission_rate);
        }

        $repo->save($vendor);

        $this->audit->recordCreate(
            request: $request,
            actor: $user,
            subject: $vendor,
            afterSnapshot: $this->audit->snapshot($vendor),
        );

        return $this->created([
            'vendor' => $this->serializer->adminShape($vendor),
        ]);
    }
}
