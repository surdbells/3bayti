<?php declare(strict_types=1);
namespace Bayti\Api\Http\Controllers\Admin\Role;

use Bayti\Api\Domain\Authz\Role;
use Bayti\Api\Http\Errors\HttpException;
use Bayti\Api\Http\PaginatedEnvelope;
use Bayti\Api\Http\Responder;
use Bayti\Api\Http\Serializers\RoleSerializer;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/** GET /v3/admin/roles/{id} */
final class GetRoleController
{
    use Responder;
    public function __construct(
        protected readonly ResponseFactoryInterface $responseFactory,
        private readonly EntityManagerInterface $em,
        private readonly RoleSerializer $serializer,
    ) {}
    protected function getResponseFactory(): ResponseFactoryInterface { return $this->responseFactory; }

    public function __invoke(ServerRequestInterface $request): ResponseInterface
    {
        $role = $this->em->getRepository(Role::class)->find((int) $request->getAttribute('id'));
        if ($role === null) {
            throw HttpException::notFound('Role not found.');
        }
        return $this->ok(PaginatedEnvelope::single($this->serializer->detail($role)));
    }
}
