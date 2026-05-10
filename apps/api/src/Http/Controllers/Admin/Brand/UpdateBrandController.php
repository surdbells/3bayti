<?php

declare(strict_types=1);

namespace Bayti\Api\Http\Controllers\Admin\Brand;

use Bayti\Api\Domain\Audit\AuditEmitter;
use Bayti\Api\Domain\Catalog\Brand;
use Bayti\Api\Domain\Catalog\BrandRepository;
use Bayti\Api\Domain\User\User;
use Bayti\Api\Http\Controllers\Admin\Brand\Dto\UpdateBrandInput;
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
 * PUT /v3/admin/brands/{id}
 *
 * Replace brand fields. Body shape: see UpdateBrandInput.
 *
 * Slug rules:
 *   - If slug omitted/null in body → unchanged
 *   - If slug provided AND same as current → unchanged
 *   - If slug provided AND different → must not be taken (else 409)
 *
 * Audit: emits 'updated' with before/after only-changed-fields.
 */
final class UpdateBrandController
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
            throw HttpException::notFound('Brand not found.');
        }
        $id = (int) $idRaw;

        /** @var BrandRepository $repo */
        $repo = $this->em->getRepository(Brand::class);
        $brand = $repo->find($id);
        if ($brand === null) {
            throw HttpException::notFound('Brand not found.');
        }

        $input = $this->validator->parse($request, UpdateBrandInput::class);

        // Capture before snapshot for audit.
        $before = $this->audit->snapshot($brand);

        // Apply changes.
        $brand->setName($input->name);

        if ($input->slug !== null && $input->slug !== $brand->getSlug()) {
            if ($repo->slugExists($input->slug, excludeId: $brand->getId())) {
                throw HttpException::conflict(
                    'slug_taken',
                    "Slug '{$input->slug}' is already taken.",
                );
            }
            $brand->setSlug($input->slug);
        }

        // logo_url + is_active: null = unchanged for is_active; null
        // for logo_url means "clear". UpdateBrandInput doesn't
        // distinguish "absent" from "null" — see UpdateProfileInput
        // (M1.7.1) for the same tristate limitation. PUT semantics
        // here mean: send the logo_url you want, or null to clear.
        $brand->setLogoUrl($input->logo_url);
        if ($input->is_active !== null) {
            $brand->setActive($input->is_active);
        }

        $this->em->flush();

        $after = $this->audit->snapshot($brand);
        $this->audit->recordUpdate(
            request: $request,
            actor: $user,
            subject: $brand,
            beforeSnapshot: $before,
            afterSnapshot: $after,
        );

        return $this->ok([
            'brand' => $this->serializer->adminShape($brand),
        ]);
    }
}
