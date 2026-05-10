<?php

declare(strict_types=1);

namespace Bayti\Api\Http\Controllers\Admin\Category;

use Bayti\Api\Domain\Audit\AuditEmitter;
use Bayti\Api\Domain\Catalog\Category;
use Bayti\Api\Domain\Catalog\CategoryRepository;
use Bayti\Api\Domain\User\User;
use Bayti\Api\Http\Controllers\Admin\Category\Dto\UpdateCategoryInput;
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
 * PUT /v3/admin/categories/{id}
 *
 * Updates a category. The tricky cases:
 *
 *   1. Slug change → triggers path rebuild for this category AND all
 *      descendants (rebuildSubtreePaths)
 *   2. Parent change → cycle check first; then path rebuild for self
 *      + descendants
 *   3. Both → handled together; rebuild after both fields applied
 *
 * Reparent uses two body fields (see UpdateCategoryInput docblock):
 *   - parent_id (int): new parent id, OR
 *   - move_to_root (bool): true means parent becomes null
 *   - neither: parent unchanged
 */
final class UpdateCategoryController
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
            throw HttpException::notFound('Category not found.');
        }

        /** @var CategoryRepository $repo */
        $repo = $this->em->getRepository(Category::class);
        $category = $repo->find((int) $idRaw);
        if ($category === null) {
            throw HttpException::notFound('Category not found.');
        }

        $input = $this->validator->parse($request, UpdateCategoryInput::class);

        $before = $this->audit->snapshot($category);

        // Track whether we need to rebuild descendant paths.
        $needsPathRebuild = false;

        // ----- Reparent logic -----
        $newParent = null;
        $parentChanged = false;

        if ($input->move_to_root) {
            // Move to root
            if ($category->getParent() !== null) {
                $parentChanged = true;
                $newParent = null;
            }
        } elseif ($input->parent_id !== null) {
            // Move under a specific parent
            if ($category->getParent()?->getId() !== $input->parent_id) {
                $parentCandidate = $repo->find($input->parent_id);
                if ($parentCandidate === null) {
                    throw HttpException::validation([
                        'parent_id' => ['Parent category does not exist.'],
                    ]);
                }
                if ($repo->wouldCreateCycle($category, $parentCandidate)) {
                    throw HttpException::validation([
                        'parent_id' => ['Setting this parent would create a cycle in the category tree.'],
                    ]);
                }
                $parentChanged = true;
                $newParent = $parentCandidate;
            }
        }

        if ($parentChanged) {
            $category->setParent($newParent);
            $needsPathRebuild = true;
        }

        // ----- Slug change -----
        if ($input->slug !== null && $input->slug !== $category->getSlug()) {
            if ($repo->slugExists($input->slug, excludeId: $category->getId())) {
                throw HttpException::conflict(
                    'slug_taken',
                    "Slug '{$input->slug}' is already taken.",
                );
            }
            $category->setSlug($input->slug);
            $needsPathRebuild = true;
        }

        // ----- Simple field updates -----
        $category->setName($input->name);
        $category->setDescription($input->description);
        $category->setImageUrl($input->image_url);

        if ($input->display_order !== null) {
            $category->setDisplayOrder($input->display_order);
        }
        if ($input->is_active !== null) {
            $category->setActive($input->is_active);
        }

        // ----- Path rebuild for descendants if needed -----
        // The category's OWN path was rebuilt by setSlug/setParent;
        // descendants need rebuilding because their paths inherit
        // from this category's path.
        if ($needsPathRebuild) {
            $repo->rebuildSubtreePaths($category);
        }

        $this->em->flush();

        $this->audit->recordUpdate(
            request: $request,
            actor: $user,
            subject: $category,
            beforeSnapshot: $before,
            afterSnapshot: $this->audit->snapshot($category),
        );

        return $this->ok([
            'category' => $this->serializer->adminShape($category),
        ]);
    }
}
