<?php declare(strict_types=1);
namespace Bayti\Api\Http\Controllers\Admin\User;

use Bayti\Api\Domain\User\User;
use Bayti\Api\Domain\User\UserRepository;
use Bayti\Api\Http\Errors\ErrorCodes;
use Bayti\Api\Http\Errors\HttpException;
use Bayti\Api\Http\Middleware\AdminAuthMiddleware;
use Bayti\Api\Http\PaginatedEnvelope;
use Bayti\Api\Http\Responder;
use Bayti\Api\Http\Serializers\UserSerializer;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/** GET /v3/admin/users/{id} */
final class GetUserController
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
        $id = (int) $request->getAttribute('id');
        /** @var UserRepository $repo */
        $repo = $this->em->getRepository(User::class);
        $user = $repo->findById($id);
        if ($user === null) throw HttpException::notFound('User not found.');
        return $this->ok(PaginatedEnvelope::single($this->serializer->publicProfile($user)));
    }
}
