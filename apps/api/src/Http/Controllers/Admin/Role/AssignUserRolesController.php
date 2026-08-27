<?php declare(strict_types=1);
namespace Bayti\Api\Http\Controllers\Admin\Role;

use Bayti\Api\Domain\Authz\Role;
use Bayti\Api\Domain\User\User;
use Bayti\Api\Domain\User\UserRepository;
use Bayti\Api\Http\Errors\HttpException;
use Bayti\Api\Http\PaginatedEnvelope;
use Bayti\Api\Http\Responder;
use Bayti\Api\Http\Serializers\UserSerializer;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Log\LoggerInterface;

/** POST /v3/admin/users/{id}/roles, body { role_ids: [int] }. Replaces the user's roles. */
final class AssignUserRolesController
{
    use Responder;
    use GuardsPrivilegeEscalation;

    public function __construct(
        protected readonly ResponseFactoryInterface $responseFactory,
        private readonly EntityManagerInterface $em,
        private readonly UserSerializer $userSerializer,
        private readonly LoggerInterface $logger,
    ) {}
    protected function getResponseFactory(): ResponseFactoryInterface { return $this->responseFactory; }

    public function __invoke(ServerRequestInterface $request): ResponseInterface
    {
        $actor = $this->actingAdmin($request);

        /** @var UserRepository $userRepo */
        $userRepo = $this->em->getRepository(User::class);
        $user = $userRepo->findById((int) $request->getAttribute('id'));
        if ($user === null) {
            throw HttpException::notFound('User not found.');
        }

        $body = (array) $request->getParsedBody();
        $raw = $body['role_ids'] ?? null;
        if (!is_array($raw)) {
            throw HttpException::validation(['role_ids' => 'role_ids must be an array of role ids.']);
        }
        $ids = array_values(array_unique(array_filter(
            array_map(static fn ($i): int => (int) $i, $raw),
            static fn (int $i): bool => $i > 0,
        )));

        $roles = $ids === [] ? [] : $this->em->getRepository(Role::class)->findBy(['id' => $ids]);
        if (count($roles) !== count($ids)) {
            throw HttpException::validation(['role_ids' => 'One or more roles were not found.']);
        }

        // Privilege-escalation guard: a non-super-admin can only assign roles
        // whose permission set is a subset of their own. Otherwise they could
        // hand themselves (or another account) more power than they hold.
        foreach ($roles as $role) {
            $this->assertNoPermissionEscalation($actor, $role->getPermissionKeys());
        }

        $before = $user->effectivePermissionKeys();
        $beforeRoleIds = array_map(
            static fn (Role $r): ?int => $r->getId(),
            $user->getAssignedRoles()->toArray(),
        );

        $user->clearRoles();
        foreach ($roles as $role) {
            $user->addRole($role);
        }
        $this->em->flush();

        $this->logger->info('admin.user.roles_assigned', [
            'by_admin_id'    => $actor->getId(),
            'target_user_id' => $user->getId(),
            'before_role_ids' => array_values(array_filter($beforeRoleIds, static fn ($v): bool => $v !== null)),
            'after_role_ids'  => $ids,
            'permissions_before' => $before,
            'permissions_after'  => $user->effectivePermissionKeys(),
        ]);

        return $this->ok(PaginatedEnvelope::single($this->userSerializer->staff($user)));
    }
}
