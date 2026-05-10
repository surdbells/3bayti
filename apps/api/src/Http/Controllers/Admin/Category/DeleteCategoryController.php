<?php

declare(strict_types=1);

namespace Bayti\Api\Http\Controllers\Admin\Category;

use Bayti\Api\Domain\Audit\AuditEmitter;
use Bayti\Api\Domain\Catalog\Category;
use Bayti\Api\Domain\Catalog\CategoryRepository;
use Bayti\Api\Domain\User\User;
use Bayti\Api\Http\Errors\ErrorCodes;
use Bayti\Api\Http\Errors\HttpException;
use Bayti\Api\Http\Middleware\AuthMiddleware;
use Bayti\Api\Http\Responder;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * DELETE /v3/admin/categories/{id}
 *
 * Soft-delete: sets is_active=false. Children are NOT cascade-soft-deleted;
 * they keep their is_active state. If you want to hide a subtree, the
 * admin can iterate.
 *
 * Idempotent.
 */
final class DeleteCategoryController
{
    use Responder;

    public function __construct(
        protected readonly ResponseFactoryInterface $responseFactory,
        private readonly EntityManagerInterface $em,
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

        $before = $this->audit->snapshot($category);

        $category->setActive(false);
        $this->em->flush();

        $this->audit->recordDelete(
            request: $request,
            actor: $user,
            subject: $category,
            beforeSnapshot: $before,
        );

        return $this->noContent();
    }
}
