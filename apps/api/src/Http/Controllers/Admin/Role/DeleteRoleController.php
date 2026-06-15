<?php declare(strict_types=1);
namespace Bayti\Api\Http\Controllers\Admin\Role;

use Bayti\Api\Domain\Authz\Role;
use Bayti\Api\Http\Errors\HttpException;
use Bayti\Api\Http\Responder;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/** DELETE /v3/admin/roles/{id} — non-system roles only; user_role rows cascade. */
final class DeleteRoleController
{
    use Responder;
    public function __construct(
        protected readonly ResponseFactoryInterface $responseFactory,
        private readonly EntityManagerInterface $em,
    ) {}
    protected function getResponseFactory(): ResponseFactoryInterface { return $this->responseFactory; }

    public function __invoke(ServerRequestInterface $request): ResponseInterface
    {
        $role = $this->em->getRepository(Role::class)->find((int) $request->getAttribute('id'));
        if ($role === null) {
            throw HttpException::notFound('Role not found.');
        }
        if ($role->isSystem()) {
            throw HttpException::conflict('role_is_system', 'System roles cannot be deleted.');
        }

        $this->em->remove($role);
        $this->em->flush();

        return $this->noContent();
    }
}
