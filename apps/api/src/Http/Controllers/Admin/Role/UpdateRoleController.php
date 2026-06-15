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

/**
 * PUT /v3/admin/roles/{id} — body may include name, description, permissions[].
 * System roles may have their permissions/name tuned but their slug is immutable
 * (and they cannot be deleted).
 */
final class UpdateRoleController
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
        $role = $this->em->getRepository(Role::class)->find((int) $request->getAttribute('id'));
        if ($role === null) {
            throw HttpException::notFound('Role not found.');
        }

        $body = (array) $request->getParsedBody();

        if (array_key_exists('name', $body)) {
            $name = trim((string) $body['name']);
            if ($name === '') {
                throw HttpException::validation(['name' => 'A role name is required.']);
            }
            $role->setName($name);
        }
        if (array_key_exists('description', $body)) {
            $role->setDescription(trim((string) ($body['description'] ?? '')) ?: null);
        }
        if (array_key_exists('permissions', $body)) {
            $this->syncPermissions($this->em, $role, $this->validatePermissionKeys($body['permissions']));
        }

        $this->em->flush();

        return $this->ok(PaginatedEnvelope::single($this->serializer->detail($role)));
    }
}
