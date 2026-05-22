<?php declare(strict_types=1);
namespace Bayti\Api\Http\Controllers\Admin\User;

use Bayti\Api\Domain\User\User;
use Bayti\Api\Domain\User\UserRepository;
use Bayti\Api\Http\Errors\ErrorCodes;
use Bayti\Api\Http\Errors\HttpException;
use Bayti\Api\Http\Middleware\AdminAuthMiddleware;
use Bayti\Api\Http\Responder;
use Bayti\Api\Http\Serializers\UserSerializer;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/** GET /v3/admin/users[?role=&search=&limit=&offset=] */
final class ListUsersController
{
    use Responder;
    public function __construct(
        protected readonly ResponseFactoryInterface $responseFactory,
        private readonly EntityManagerInterface $em,
        private readonly UserSerializer $serializer,
    ) {}
    protected function getResponseFactory(): ResponseFactoryInterface { return $this->responseFactory; }

    public function __invoke(ServerRequestInterface $request): ResponseInterface
    {
        $q      = $request->getQueryParams();
        $limit  = max(1, min(100, (int) ($q['limit']  ?? 20)));
        $offset = max(0, (int) ($q['offset'] ?? 0));

        /** @var UserRepository $repo */
        $repo   = $this->em->getRepository(User::class);
        $result = $repo->findPaginated([
            'role'   => $q['role']   ?? null,
            'search' => $q['search'] ?? null,
            'limit'  => $limit,
            'offset' => $offset,
        ]);

        return $this->ok([
            'data' => array_map([$this->serializer, 'publicProfile'], $result['items']),
            'meta' => ['total' => $result['total'], 'limit' => $limit, 'offset' => $offset],
        ]);
    }
}
