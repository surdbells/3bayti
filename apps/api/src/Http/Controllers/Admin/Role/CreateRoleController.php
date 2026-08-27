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
use Psr\Log\LoggerInterface;

/** POST /v3/admin/roles, body { name, description?, permissions: [keys] }. */
final class CreateRoleController
{
    use Responder;
    use ResolvesRolePermissions;
    use GuardsPrivilegeEscalation;

    public function __construct(
        protected readonly ResponseFactoryInterface $responseFactory,
        private readonly EntityManagerInterface $em,
        private readonly RoleSerializer $serializer,
        private readonly LoggerInterface $logger,
    ) {}
    protected function getResponseFactory(): ResponseFactoryInterface { return $this->responseFactory; }

    public function __invoke(ServerRequestInterface $request): ResponseInterface
    {
        $actor = $this->actingAdmin($request);
        $body = (array) $request->getParsedBody();

        $name = trim((string) ($body['name'] ?? ''));
        if ($name === '') {
            throw HttpException::validation(['name' => 'A role name is required.']);
        }

        $keys = $this->validatePermissionKeys($body['permissions'] ?? []);

        // Privilege-escalation guard: a non-super-admin can only grant keys they
        // themselves hold.
        $this->assertNoPermissionEscalation($actor, $keys);

        $description = isset($body['description']) ? (trim((string) $body['description']) ?: null) : null;
        $role = new Role($this->uniqueSlug($this->em, $name), $name, $description, false);
        $this->syncPermissions($this->em, $role, $keys);

        $this->em->persist($role);
        $this->em->flush();

        $this->logger->info('admin.role.created', [
            'by_admin_id'     => $actor->getId(),
            'role_id'         => $role->getId(),
            'role_slug'       => $role->getSlug(),
            'permissions'     => $role->getPermissionKeys(),
        ]);

        return $this->created(PaginatedEnvelope::single($this->serializer->detail($role)));
    }
}
