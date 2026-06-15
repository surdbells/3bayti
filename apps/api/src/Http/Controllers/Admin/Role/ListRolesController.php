<?php declare(strict_types=1);
namespace Bayti\Api\Http\Controllers\Admin\Role;

use Bayti\Api\Domain\Authz\Role;
use Bayti\Api\Http\Responder;
use Bayti\Api\Http\Serializers\RoleSerializer;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/** GET /v3/admin/roles — all roles (system first, then alphabetical). */
final class ListRolesController
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
        $roles = $this->em->getRepository(Role::class)->findBy([], ['isSystem' => 'DESC', 'name' => 'ASC']);
        return $this->ok(['data' => $this->serializer->list($roles)]);
    }
}
