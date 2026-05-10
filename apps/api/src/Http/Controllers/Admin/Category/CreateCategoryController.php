<?php

declare(strict_types=1);

namespace Bayti\Api\Http\Controllers\Admin\Category;

use Bayti\Api\Domain\Audit\AuditEmitter;
use Bayti\Api\Domain\Catalog\Category;
use Bayti\Api\Domain\Catalog\CategoryRepository;
use Bayti\Api\Domain\Common\SlugHelper;
use Bayti\Api\Domain\User\User;
use Bayti\Api\Http\Controllers\Admin\Category\Dto\CreateCategoryInput;
use Bayti\Api\Http\Errors\ErrorCodes;
use Bayti\Api\Http\Errors\HttpException;
use Bayti\Api\Http\Middleware\AuthMiddleware;
use Bayti\Api\Http\Responder;
use Bayti\Api\Http\Serializers\CategorySerializer;
use Bayti\Api\Http\Validator\RequestValidator;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * POST /v3/admin/categories
 *
 * Creates a category, optionally under a parent. Path computed
 * from parent's path + slug in the Category constructor.
 */
final class CreateCategoryController
{
    use Responder;

    public function __construct(
        protected readonly ResponseFactoryInterface $responseFactory,
        private readonly RequestValidator $validator,
        private readonly EntityManagerInterface $em,
        private readonly CategorySerializer $serializer,
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

        $input = $this->validator->parse($request, CreateCategoryInput::class);

        /** @var CategoryRepository $repo */
        $repo = $this->em->getRepository(Category::class);

        // Resolve parent if specified.
        $parent = null;
        if ($input->parent_id !== null) {
            $parent = $repo->find($input->parent_id);
            if ($parent === null) {
                throw HttpException::validation([
                    'parent_id' => ['Parent category does not exist.'],
                ]);
            }
        }

        // Resolve slug.
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

        $category = new Category(slug: $slug, name: $input->name, parent: $parent);

        if ($input->description !== null) {
            $category->setDescription($input->description);
        }
        if ($input->display_order !== null) {
            $category->setDisplayOrder($input->display_order);
        }
        if ($input->image_url !== null) {
            $category->setImageUrl($input->image_url);
        }

        $repo->save($category);

        $this->audit->recordCreate(
            request: $request,
            actor: $user,
            subject: $category,
            afterSnapshot: $this->audit->snapshot($category),
        );

        return $this->created([
            'category' => $this->serializer->adminShape($category),
        ]);
    }
}
