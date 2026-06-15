<?php declare(strict_types=1);
namespace Bayti\Api\Http\Controllers\Admin\Role;

use Bayti\Api\Domain\Authz\Permission;
use Bayti\Api\Domain\Authz\PermissionCatalog;
use Bayti\Api\Domain\Authz\Role;
use Bayti\Api\Http\Errors\HttpException;
use Bayti\Api\Http\PaginatedEnvelope;
use Bayti\Api\Http\Responder;
use Bayti\Api\Http\Serializers\RoleSerializer;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/** POST /v3/admin/roles — body { name, description?, permissions: [keys] }. */
final class CreateRoleController
{
    use Responder;
    use ResolvesRolePermissions;

    public function __construct(
        protected readonly ResponseFactoryInterface $responseFactory,
        private readonly EntityManagerInterface $em,
        private readonly RoleSerializer $serializer,
    ) {}
    protected function getResponseFactory(): ResponseFactoryInterface { return $this->responseFactory; }

    public function __invoke(ServerRequestInterface $request): ResponseInterface
    {
        $body = (array) $request->getParsedBody();

        $name = trim((string) ($body['name'] ?? ''));
        if ($name === '') {
            throw HttpException::validation(['name' => 'A role name is required.']);
        }

        $keys = $this->validatePermissionKeys($body['permissions'] ?? []);

        $description = isset($body['description']) ? (trim((string) $body['description']) ?: null) : null;
        $role = new Role($this->uniqueSlug($this->em, $name), $name, $description, false);
        $this->syncPermissions($this->em, $role, $keys);

        $this->em->persist($role);
        $this->em->flush();

        return $this->created(PaginatedEnvelope::single($this->serializer->detail($role)));
    }
}
