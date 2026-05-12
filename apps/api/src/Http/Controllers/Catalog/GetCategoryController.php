<?php

declare(strict_types=1);

namespace Bayti\Api\Http\Controllers\Catalog;

use Bayti\Api\Domain\Catalog\Category;
use Bayti\Api\Domain\Catalog\CategoryRepository;
use Bayti\Api\Http\Errors\HttpException;
use Bayti\Api\Http\PaginatedEnvelope;
use Bayti\Api\Http\Responder;
use Bayti\Api\Http\Serializers\CategorySerializer;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * GET /v3/categories/{slug}
 *
 * Single category detail with direct children. 404 for unknown or
 * inactive category.
 */
final class GetCategoryController
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

    public function __invoke(
        ServerRequestInterface $request,
        ResponseInterface $response,
        array $args,
    ): ResponseInterface {
        $slug = (string) ($args['slug'] ?? '');
        if ($slug === '') {
            throw HttpException::notFound('Category not found.');
        }

        /** @var CategoryRepository $repo */
        $repo = $this->em->getRepository(Category::class);
        $category = $repo->findBySlug($slug);
        if ($category === null || !$category->isActive()) {
            throw HttpException::notFound('Category not found.');
        }

        // Include direct children — useful for "browse subcategories" UI.
        $children = $repo->findChildren($category);

        $shape = $this->serializer->publicShape($category);
        $shape['children'] = $this->serializer->publicShapeMany($children);

        return $this->ok(PaginatedEnvelope::single($shape));
    }
}
