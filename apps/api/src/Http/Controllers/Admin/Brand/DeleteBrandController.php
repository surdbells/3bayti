<?php

declare(strict_types=1);

namespace Bayti\Api\Http\Controllers\Admin\Brand;

use Bayti\Api\Domain\Audit\AuditEmitter;
use Bayti\Api\Domain\Catalog\Brand;
use Bayti\Api\Domain\Catalog\BrandRepository;
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
 * DELETE /v3/admin/brands/{id}
 *
 * Soft-delete via is_active=false (D1). Hard-delete is forbidden:
 * brand_id may be referenced by products (M2.2), and removing a
 * brand row would orphan those references or cascade-delete real
 * products silently.
 *
 * Idempotent: deleting an already-inactive brand returns 204 too.
 *
 * Audit: emits 'deleted' with the brand snapshot.
 */
final class DeleteBrandController
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
            throw HttpException::notFound('Brand not found.');
        }
        $id = (int) $idRaw;

        /** @var BrandRepository $repo */
        $repo = $this->em->getRepository(Brand::class);
        $brand = $repo->find($id);
        if ($brand === null) {
            throw HttpException::notFound('Brand not found.');
        }

        // Snapshot before the soft-delete so audit captures last known state.
        $before = $this->audit->snapshot($brand);

        $brand->setActive(false);
        $this->em->flush();

        $this->audit->recordDelete(
            request: $request,
            actor: $user,
            subject: $brand,
            beforeSnapshot: $before,
        );

        return $this->noContent();
    }
}
