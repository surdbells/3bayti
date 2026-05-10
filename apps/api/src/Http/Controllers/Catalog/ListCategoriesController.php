<?php

declare(strict_types=1);

namespace Bayti\Api\Http\Controllers\Catalog;

use Bayti\Api\Domain\Catalog\Category;
use Bayti\Api\Domain\Catalog\CategoryRepository;
use Bayti\Api\Http\Responder;
use Bayti\Api\Http\Serializers\CategorySerializer;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * GET /v3/categories  (public, tree)
 *
 * Returns the full active category tree as nested objects.
 * Each level only includes is_active=true categories.
 *
 * Frontend uses this once on app load to populate the navigation
 * menu. Tree is small (dozens to low hundreds of categories) so
 * full hydration is fine.
 */
final class ListCategoriesController
{
    use Responder;

    public function __construct(
        protected readonly ResponseFactoryInterface $responseFactory,
        private readonly EntityManagerInterface $em,
        private readonly CategorySerializer $serializer,
    ) {
    }

    protected function getResponseFactory(): ResponseFactoryInterface
    {
        return $this->responseFactory;
    }

    public function __invoke(ServerRequestInterface $request): ResponseInterface
    {
        /** @var CategoryRepository $repo */
        $repo = $this->em->getRepository(Category::class);
        $roots = $repo->findRoots();

        $fetchChildren = function (Category $c) use ($repo): array {
            return $repo->findChildren($c);
        };

        $tree = [];
        foreach ($roots as $root) {
            $children = $repo->findChildren($root);
            $tree[] = $this->serializer->publicShapeWithChildren(
                $root,
                $children,
                $fetchChildren,
            );
        }

        return $this->ok([
            'categories' => $tree,
        ]);
    }
}
