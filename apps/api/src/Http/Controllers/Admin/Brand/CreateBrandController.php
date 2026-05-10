<?php

declare(strict_types=1);

namespace Bayti\Api\Http\Controllers\Admin\Brand;

use Bayti\Api\Domain\Audit\AuditEmitter;
use Bayti\Api\Domain\Catalog\Brand;
use Bayti\Api\Domain\Catalog\BrandRepository;
use Bayti\Api\Domain\Common\SlugHelper;
use Bayti\Api\Domain\User\User;
use Bayti\Api\Http\Controllers\Admin\Brand\Dto\CreateBrandInput;
use Bayti\Api\Http\Errors\ErrorCodes;
use Bayti\Api\Http\Errors\HttpException;
use Bayti\Api\Http\Middleware\AuthMiddleware;
use Bayti\Api\Http\Responder;
use Bayti\Api\Http\Serializers\BrandSerializer;
use Bayti\Api\Http\Validator\RequestValidator;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * POST /v3/admin/brands
 *
 * Body shape: see CreateBrandInput.
 *
 *   - If slug provided and taken → 409 Conflict
 *   - If slug NOT provided → generated from name with -2/-3 collision suffix
 *   - logo_url optional
 *   - new brands start with is_active=true
 *
 * Response: 201 Created with the admin shape.
 *
 * Audit: emits 'created' action.
 */
final class CreateBrandController
{
    use Responder;

    public function __construct(
        protected readonly ResponseFactoryInterface $responseFactory,
        private readonly RequestValidator $validator,
        private readonly EntityManagerInterface $em,
        private readonly BrandSerializer $serializer,
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
        // AdminAuthMiddleware has already verified admin status; defensive check.
        if (!$user instanceof User) {
            throw HttpException::unauthorized(ErrorCodes::AUTH_INVALID_TOKEN, 'Authentication required.');
        }

        $input = $this->validator->parse($request, CreateBrandInput::class);

        /** @var BrandRepository $repo */
        $repo = $this->em->getRepository(Brand::class);

        // Resolve slug: provided OR generate from name.
        if ($input->slug !== null) {
            // Admin-provided slug. Must not already exist.
            if ($repo->slugExists($input->slug)) {
                throw HttpException::conflict(
                    'slug_taken',
                    "Slug '{$input->slug}' is already taken.",
                );
            }
            $slug = $input->slug;
        } else {
            // Auto-generate.
            $slug = SlugHelper::generateUnique(
                $input->name,
                static fn (string $candidate): bool => $repo->slugExists($candidate),
            );
        }

        $brand = new Brand(slug: $slug, name: $input->name);
        if ($input->logo_url !== null) {
            $brand->setLogoUrl($input->logo_url);
        }

        $repo->save($brand);

        // Audit log.
        $this->audit->recordCreate(
            request: $request,
            actor: $user,
            subject: $brand,
            afterSnapshot: $this->audit->snapshot($brand),
        );

        return $this->created([
            'brand' => $this->serializer->adminShape($brand),
        ]);
    }
}
