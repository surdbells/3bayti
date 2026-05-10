<?php

declare(strict_types=1);

namespace Bayti\Api\Http\Controllers\Admin\Category;

use Bayti\Api\Domain\Catalog\Category;
use Bayti\Api\Domain\Catalog\CategoryRepository;
use Bayti\Api\Http\Responder;
use Bayti\Api\Http\Serializers\CategorySerializer;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * GET /v3/admin/categories
 *
 * Flat list of ALL categories (including inactive), ordered by path.
 * Admin clients use the flat list with path strings to render
 * indented dropdowns. Tree-nested view available at public endpoint.
 */
final class ListCategoriesAdminController
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
        $categories = $repo->findAll();

        return $this->ok([
            'categories' => $this->serializer->adminShapeMany($categories),
        ]);
    }
}
