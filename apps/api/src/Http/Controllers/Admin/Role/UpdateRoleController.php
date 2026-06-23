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

/**
 * PUT /v3/admin/roles/{id} — body may include name, description, permissions[].
 *
 * System roles (is_system) are protected: only their name/description may be
 * tuned. Any attempt to change their slug or permission set is rejected (422).
 * This is critical for super_admin, which is special-cased at runtime to hold
 * EVERY permission (PermissionCatalog) — editing its stored permission set must
 * never silently diverge from that contract. (System roles also cannot be
 * deleted; see DeleteRoleController.)
 *
 * For non-system roles a privilege-escalation guard applies: a non-super-admin
 * actor can only set a permission set that is a subset of their own effective
 * permissions.
 */
final class UpdateRoleController
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

        $role = $this->em->getRepository(Role::class)->find((int) $request->getAttribute('id'));
        if ($role === null) {
            throw HttpException::notFound('Role not found.');
        }

        $body = (array) $request->getParsedBody();

        // System-role protection. The slug is immutable and the permission set is
        // frozen; only the human-facing name/description may be tuned.
        if ($role->isSystem()) {
            if (array_key_exists('slug', $body) && trim((string) $body['slug']) !== $role->getSlug()) {
                throw HttpException::validation([
                    'slug' => 'The slug of a system role cannot be changed.',
                ]);
            }
            if (array_key_exists('permissions', $body)) {
                throw HttpException::validation([
                    'permissions' => 'The permissions of a system role cannot be changed.',
                ]);
            }
        }

        $before = $role->getPermissionKeys();

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
            // (Only reachable for non-system roles — guarded above.)
            $keys = $this->validatePermissionKeys($body['permissions']);
            $this->assertNoPermissionEscalation($actor, $keys);
            $this->syncPermissions($this->em, $role, $keys);
        }

        $this->em->flush();

        $after = $role->getPermissionKeys();
        $this->logger->info('admin.role.updated', [
            'by_admin_id' => $actor->getId(),
            'role_id'     => $role->getId(),
            'role_slug'   => $role->getSlug(),
            'is_system'   => $role->isSystem(),
            'added'       => array_values(array_diff($after, $before)),
            'removed'     => array_values(array_diff($before, $after)),
        ]);

        return $this->ok(PaginatedEnvelope::single($this->serializer->detail($role)));
    }
}
