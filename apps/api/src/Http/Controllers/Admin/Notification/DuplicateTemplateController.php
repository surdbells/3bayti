<?php

declare(strict_types=1);

namespace Bayti\Api\Http\Controllers\Admin\Notification;

use Bayti\Api\Domain\Notification\NotificationTemplate;
use Bayti\Api\Domain\User\User;
use Bayti\Api\Http\Errors\ErrorCodes;
use Bayti\Api\Http\Errors\HttpException;
use Bayti\Api\Http\Middleware\AuthMiddleware;
use Bayti\Api\Http\Responder;
use Bayti\Api\Http\Serializers\NotificationTemplateSerializer;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/** POST /v3/admin/notification-templates/{id}/duplicate, clone a template. */
final class DuplicateTemplateController
{
    use Responder;

    public function __construct(
        protected readonly ResponseFactoryInterface $responseFactory,
        private readonly EntityManagerInterface $em,
        private readonly NotificationTemplateSerializer $serializer,
    ) {
    }

    protected function getResponseFactory(): ResponseFactoryInterface
    {
        return $this->responseFactory;
    }

    public function __invoke(ServerRequestInterface $request, ResponseInterface $_r, array $args): ResponseInterface
    {
        $user = $request->getAttribute(AuthMiddleware::ATTR_USER);
        if (!$user instanceof User) {
            throw HttpException::unauthorized(ErrorCodes::AUTH_INVALID_TOKEN, 'Authentication required.');
        }

        $id = (int) ($args['id'] ?? 0);
        $source = $id > 0 ? $this->em->getRepository(NotificationTemplate::class)->find($id) : null;
        if (!$source instanceof NotificationTemplate) {
            throw HttpException::notFound('Template not found.');
        }

        $copy = new NotificationTemplate(
            mb_substr($source->getName() . ' (copy)', 0, 200),
            $source->getTitle(),
            $source->getBody(),
            $user->getId(),
            $source->getImageUrl(),
            $source->getDeepLink(),
        );
        $this->em->persist($copy);
        $this->em->flush();

        return $this->created(['data' => $this->serializer->shape($copy)]);
    }
}
